<?php

namespace App\Http\Controllers;

use App\Enums\AiActionProposalStatus;
use App\Exceptions\AiChatUnavailableException;
use App\Http\Requests\AiChat\DecideAiProposalsRequest;
use App\Http\Requests\AiChat\SendAiChatMessageRequest;
use App\Http\Requests\AiChat\UpdateAiConversationRequest;
use App\Models\AiActionProposal;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChat\AiActionProposalService;
use App\Services\AiChat\AiChatOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AiChatController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $timezone = $user->timezone ?: 'Asia/Jakarta';

        $conversations = $this->listConversations($user, $timezone);
        $activeConversation = null;
        $messages = [];

        if (! $request->boolean('new')) {
            $latest = $user->aiChatConversations()
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();

            if ($latest !== null) {
                $activeConversation = $this->serializeConversation($latest, $timezone);
                $messages = $this->loadConversationMessages($latest, $timezone);
            }
        }

        return Inertia::render('ai-chat/index', [
            'conversations' => $conversations,
            'active_conversation' => $activeConversation,
            'messages' => $messages,
            'audits' => $this->listAudits($user, $timezone),
            'timezone' => $timezone,
            'ai_preferences' => $user->resolvedAiPreferences(),
        ]);
    }

    public function show(Request $request, AiChatConversation $conversation): Response|JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();
        $timezone = $user->timezone ?: 'Asia/Jakarta';

        $serializedConversation = $this->serializeConversation($conversation, $timezone);
        $messages = $this->loadConversationMessages($conversation, $timezone);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'conversation' => $serializedConversation,
                'messages' => $messages,
            ]);
        }

        return Inertia::render('ai-chat/index', [
            'conversations' => $this->listConversations($user, $timezone),
            'active_conversation' => $serializedConversation,
            'messages' => $messages,
            'audits' => $this->listAudits($user, $timezone),
            'timezone' => $timezone,
            'ai_preferences' => $user->resolvedAiPreferences(),
        ]);
    }

    public function storeMessage(
        SendAiChatMessageRequest $request,
        AiChatOrchestrator $orchestrator,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $validated = $request->validated();

        $conversation = null;
        if (isset($validated['conversation_id'])) {
            $conversation = $user->aiChatConversations()->find((int) $validated['conversation_id']);
            if ($conversation !== null) {
                Gate::authorize('update', $conversation);
            }
        }

        try {
            $result = $orchestrator->sendMessage(
                user: $user,
                messageText: (string) $validated['message'],
                conversation: $conversation,
            );
        } catch (AiChatUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'fallback_url' => route('ai-planner.index'),
            ], 503);
        }

        return response()->json([
            'conversation' => $this->serializeConversation($result['conversation'], $timezone),
            'user_message' => $this->serializeMessage($result['user_message'], $timezone),
            'assistant_message' => $this->serializeMessage($result['assistant_message'], $timezone),
        ], 201);
    }

    public function updateConversation(
        UpdateAiConversationRequest $request,
        AiChatConversation $conversation,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $conversation);

        /** @var User $user */
        $user = $request->user();
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $title = trim((string) $request->validated('title'));

        $conversation->update(['title' => $title]);
        $conversation->refresh();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'conversation' => $this->serializeConversation($conversation, $timezone),
            ]);
        }

        return back()->with('status', 'Judul percakapan berhasil diperbarui.');
    }

    public function destroyConversation(
        Request $request,
        AiChatConversation $conversation,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('delete', $conversation);

        $conversation->delete();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'deleted' => true,
            ]);
        }

        return to_route('ai-chat.index')->with('status', 'Percakapan AI berhasil dihapus.');
    }

    public function decideProposals(
        DecideAiProposalsRequest $request,
        AiChatMessage $message,
        AiActionProposalService $proposalService,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $timezone = $user->timezone ?: 'Asia/Jakarta';

        /** @var array<int, mixed> $rawIds */
        $rawIds = $request->validated('approved_proposal_ids', []);
        $approvedIds = array_values(array_map('intval', $rawIds));
        $rejectAll = (bool) $request->validated('reject_all', false);

        $decision = $proposalService->decideBatch(
            user: $user,
            message: $message,
            approvedIds: $approvedIds,
            rejectAll: $rejectAll,
        );

        return response()->json([
            'proposals' => array_map(
                fn (AiActionProposal $proposal): array => $this->serializeProposal($proposal, $timezone),
                $decision['proposals'],
            ),
            'summary' => $decision['summary'],
            'audits' => $this->listAudits($user, $timezone),
        ]);
    }

    public function audits(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $timezone = $user->timezone ?: 'Asia/Jakarta';

        return response()->json([
            'audits' => $this->listAudits($user, $timezone),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listConversations(User $user, string $timezone): array
    {
        return array_values(
            $user->aiChatConversations()
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get()
                ->map(fn (AiChatConversation $conversation): array => $this->serializeConversation($conversation, $timezone))
                ->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadConversationMessages(AiChatConversation $conversation, string $timezone): array
    {
        return array_values(
            $conversation->messages()
                ->with(['actionProposals' => fn ($query) => $query->orderBy('id')])
                ->orderBy('id')
                ->get()
                ->map(fn (AiChatMessage $message): array => $this->serializeMessage($message, $timezone))
                ->all(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAudits(User $user, string $timezone): array
    {
        return array_values(
            $user->aiActionProposals()
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (AiActionProposal $proposal): array => $this->serializeProposal($proposal, $timezone))
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversation(AiChatConversation $conversation, string $timezone): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'last_message_at_local' => $conversation->last_message_at !== null
                ? CarbonImmutable::instance($conversation->last_message_at)->utc()->setTimezone($timezone)->format('d M H:i')
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(AiChatMessage $message, string $timezone): array
    {
        $proposals = $message->relationLoaded('actionProposals')
            ? $message->actionProposals
                ->map(fn (AiActionProposal $proposal): array => $this->serializeProposal($proposal, $timezone))
                ->values()
                ->all()
            : [];

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'role' => $message->role->value,
            'content' => $message->content,
            'provider' => $message->provider,
            'model' => $message->model,
            'tool_calls_summary' => $message->tool_calls_summary ?? [],
            'proposals' => $proposals,
            'created_at' => $message->created_at?->toIso8601String(),
            'created_at_local' => $message->created_at !== null
                ? CarbonImmutable::instance($message->created_at)->utc()->setTimezone($timezone)->format('H:i')
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProposal(AiActionProposal $proposal, string $timezone): array
    {
        $effectiveStatus = $proposal->isExpired() && $proposal->status === AiActionProposalStatus::Pending
            ? AiActionProposalStatus::Expired->value
            : $proposal->status->value;

        return [
            'id' => $proposal->id,
            'conversation_id' => $proposal->conversation_id,
            'ai_chat_message_id' => $proposal->ai_chat_message_id,
            'tool_name' => $proposal->tool_name,
            'payload' => $proposal->payload,
            'conflict_preview' => $proposal->conflict_preview ?? [],
            'status' => $effectiveStatus,
            'is_expired' => $proposal->isExpired(),
            'expires_at' => $proposal->expires_at->toIso8601String(),
            'expires_at_local' => CarbonImmutable::instance($proposal->expires_at)->utc()->setTimezone($timezone)->format('d M Y H:i'),
            'decided_at' => $proposal->decided_at?->toIso8601String(),
            'executed_at' => $proposal->executed_at?->toIso8601String(),
            'executed_at_local' => $proposal->executed_at !== null
                ? CarbonImmutable::instance($proposal->executed_at)->utc()->setTimezone($timezone)->format('d M H:i')
                : null,
            'target_type' => $proposal->target_type,
            'target_id' => $proposal->target_id,
            'result_summary' => $proposal->result_summary,
        ];
    }
}
