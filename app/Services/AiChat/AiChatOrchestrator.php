<?php

namespace App\Services\AiChat;

use App\Enums\AiActionProposalStatus;
use App\Enums\AiChatRole;
use App\Exceptions\AiChatUnavailableException;
use App\Exceptions\AiProviderException;
use App\Mcp\McpToolExecutor;
use App\Mcp\McpToolRegistry;
use App\Models\AiActionProposal;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\Ai\AiCircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiChatOrchestrator
{
    public const int HISTORY_WINDOW = 16;

    public const int MAX_TOOL_ROUNDS = 4;

    public const string CIRCUIT_NAMESPACE = 'ai_chat';

    /** @var list<AiChatProviderInterface> */
    private array $providers;

    public function __construct(
        private McpToolRegistry $registry,
        private McpToolExecutor $executor,
        private AiChatPromptBuilder $promptBuilder,
        private AiCircuitBreaker $circuitBreaker,
        GeminiChatProvider $geminiProvider,
        GrokChatProvider $grokProvider,
        OpenRouterChatProvider $openRouterProvider,
    ) {
        $this->providers = [
            $geminiProvider,
            $grokProvider,
            $openRouterProvider,
        ];
    }

    /**
     * Process a user message across the provider fallback chain with native MCP tool calling.
     *
     * @return array{
     *     conversation: AiChatConversation,
     *     user_message: AiChatMessage,
     *     assistant_message: AiChatMessage,
     * }
     *
     * @throws AiChatUnavailableException
     */
    public function sendMessage(
        User $user,
        string $messageText,
        ?AiChatConversation $conversation = null,
    ): array {
        $cleanMessage = trim($messageText);
        $systemPrompt = $this->promptBuilder->build($user);
        $baseHistory = $this->loadBaseHistory($conversation, $cleanMessage);

        $outcome = null;

        foreach ($this->providers as $provider) {
            if ($this->circuitBreaker->isOpen($provider->name(), self::CIRCUIT_NAMESPACE)) {
                continue;
            }

            try {
                $outcome = $this->runProviderLoop($provider, $user, $systemPrompt, $baseHistory);
                $this->circuitBreaker->reset($provider->name(), self::CIRCUIT_NAMESPACE);

                break;
            } catch (AiProviderException $exception) {
                $this->circuitBreaker->recordFailure($provider->name(), self::CIRCUIT_NAMESPACE);

                Log::warning('AI Chat provider failed; falling back to next provider.', [
                    'provider' => $provider->name(),
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        if ($outcome === null) {
            throw new AiChatUnavailableException(
                'Layanan AI Chat sedang tidak tersedia saat ini. Silakan coba beberapa saat lagi atau gunakan AI Planner harian.',
            );
        }

        /** @var array{conversation: AiChatConversation, user_message: AiChatMessage, assistant_message: AiChatMessage} $saved */
        $saved = DB::transaction(function () use ($user, $conversation, $cleanMessage, $outcome): array {
            $now = CarbonImmutable::now('UTC');

            if ($conversation === null) {
                $conversation = $user->aiChatConversations()->create([
                    'title' => $this->generateTitle($cleanMessage),
                    'last_message_at' => $now,
                ]);
            }

            $userMessage = $conversation->messages()->create([
                'user_id' => $user->id,
                'role' => AiChatRole::User,
                'content' => $cleanMessage,
            ]);

            $assistantMessage = $conversation->messages()->create([
                'user_id' => $user->id,
                'role' => AiChatRole::Assistant,
                'content' => $outcome['text'],
                'provider' => $outcome['provider'],
                'model' => $outcome['model'],
                'tool_calls_summary' => $outcome['tool_calls_summary'] !== [] ? $outcome['tool_calls_summary'] : null,
            ]);

            $expiresAt = $now->addHours(24);
            foreach ($outcome['proposals'] as $proposalData) {
                AiActionProposal::create([
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'ai_chat_message_id' => $assistantMessage->id,
                    'tool_name' => $proposalData['tool_name'],
                    'payload' => $proposalData['payload'],
                    'conflict_preview' => $proposalData['conflict_preview'] !== [] ? $proposalData['conflict_preview'] : null,
                    'status' => AiActionProposalStatus::Pending,
                    'expires_at' => $expiresAt,
                    'result_summary' => $proposalData['summary'],
                ]);
            }

            $conversation->update(['last_message_at' => $now]);
            $assistantMessage->load('actionProposals');

            return [
                'conversation' => $conversation->fresh() ?? $conversation,
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
            ];
        });

        return $saved;
    }

    /**
     * Load sliding window of up to 15 previous DB messages plus the new user message (total <= 16).
     *
     * @return list<array<string, mixed>>
     */
    private function loadBaseHistory(?AiChatConversation $conversation, string $newUserMessage): array
    {
        $messages = [];

        if ($conversation !== null) {
            $history = $conversation->messages()
                ->orderByDesc('id')
                ->limit(self::HISTORY_WINDOW - 1)
                ->get()
                ->reverse()
                ->values();

            foreach ($history as $item) {
                $messages[] = [
                    'role' => $item->role->value,
                    'content' => $item->content,
                ];
            }
        }

        $messages[] = [
            'role' => AiChatRole::User->value,
            'content' => $newUserMessage,
        ];

        return $messages;
    }

    /**
     * Execute up to MAX_TOOL_ROUNDS of native tool calling on a single provider.
     *
     * @param  list<array<string, mixed>>  $baseHistory
     * @return array{
     *     text: string,
     *     provider: string,
     *     model: string,
     *     tool_calls_summary: list<array<string, mixed>>,
     *     proposals: list<array{
     *         tool_name: string,
     *         payload: array<string, mixed>,
     *         conflict_preview: array<int, array<string, mixed>>,
     *         summary: string,
     *     }>,
     * }
     *
     * @throws AiProviderException
     */
    private function runProviderLoop(
        AiChatProviderInterface $provider,
        User $user,
        string $systemPrompt,
        array $baseHistory,
    ): array {
        $workingMessages = $baseHistory;
        $toolCallsSummary = [];
        $pendingProposals = [];
        $finalText = null;
        $usedModel = $provider->model();

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $turn = $provider->chat($systemPrompt, $workingMessages, $this->registry);
            $usedModel = $turn->model;

            if (! $turn->hasToolCalls()) {
                $finalText = $turn->text;

                break;
            }

            if ($round === self::MAX_TOOL_ROUNDS) {
                $finalText = $turn->text;

                break;
            }

            $workingMessages[] = [
                'role' => 'assistant',
                'content' => $turn->text,
                'tool_calls' => $turn->toolCalls,
                'raw_parts' => $turn->rawParts,
            ];

            foreach ($turn->toolCalls as $call) {
                $tool = $this->registry->get($call->name);

                if ($tool !== null && $tool->isMutation()) {
                    $preview = $this->executor->previewMutation($user, $call->name, $call->arguments);

                    if ($preview['valid']) {
                        $pendingProposals[] = [
                            'tool_name' => $call->name,
                            'payload' => $preview['payload'],
                            'conflict_preview' => $preview['conflict_preview'],
                            'summary' => $preview['summary'],
                        ];

                        $toolCallsSummary[] = [
                            'tool' => $call->name,
                            'type' => 'mutation_proposed',
                            'summary' => $preview['summary'],
                            'has_conflicts' => $preview['conflict_preview'] !== [],
                        ];

                        $toolResponse = [
                            'ok' => true,
                            'status' => 'queued_for_user_approval',
                            'requires_confirmation' => true,
                            'summary' => $preview['summary'],
                            'conflict_preview' => $preview['conflict_preview'],
                        ];
                    } else {
                        $toolCallsSummary[] = [
                            'tool' => $call->name,
                            'type' => 'mutation_invalid',
                            'error' => $preview['error'],
                        ];

                        $toolResponse = [
                            'ok' => false,
                            'error' => $preview['error'] ?? 'Argumen mutasi tidak valid.',
                        ];
                    }
                } else {
                    $result = $this->executor->executeRead($user, $call->name, $call->arguments);

                    $toolCallsSummary[] = [
                        'tool' => $call->name,
                        'type' => 'read',
                        'ok' => $result->success,
                    ];

                    $toolResponse = $result->toToolPayload();
                }

                $workingMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'name' => $call->name,
                    'content' => $toolResponse,
                ];
            }
        }

        if ($finalText === null || trim($finalText) === '') {
            if ($pendingProposals !== []) {
                $finalText = sprintf(
                    'Saya telah menyiapkan %d usulan perubahan berdasarkan data Anda. Silakan tinjau kartu usulan di bawah dan klik Setujui untuk menerapkannya.',
                    count($pendingProposals),
                );
            } else {
                throw new AiProviderException("{$provider->name()} finished tool loop without final text.");
            }
        }

        return [
            'text' => trim($finalText),
            'provider' => $provider->name(),
            'model' => $usedModel,
            'tool_calls_summary' => $toolCallsSummary,
            'proposals' => $pendingProposals,
        ];
    }

    private function generateTitle(string $firstMessage): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($firstMessage)) ?? '';
        if ($normalized === '') {
            return 'Percakapan AI Baru';
        }

        return Str::limit($normalized, 57, '...');
    }
}
