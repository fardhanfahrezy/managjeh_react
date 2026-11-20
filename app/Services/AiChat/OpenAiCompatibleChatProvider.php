<?php

namespace App\Services\AiChat;

use App\Exceptions\AiProviderException;
use App\Mcp\McpToolRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use stdClass;

abstract class OpenAiCompatibleChatProvider implements AiChatProviderInterface
{
    abstract protected function serviceConfigKey(): string;

    public function model(): string
    {
        return (string) config("services.{$this->serviceConfigKey()}.model", '');
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function chat(string $systemPrompt, array $messages, McpToolRegistry $tools): AiChatProviderTurn
    {
        $configKey = $this->serviceConfigKey();
        $apiKey = (string) config("services.{$configKey}.api_key", '');
        $model = $this->model();
        $endpoint = (string) config("services.{$configKey}.endpoint", '');
        $timeout = (int) config("services.{$configKey}.timeout", 20);

        if ($apiKey === '' || $endpoint === '' || $model === '') {
            throw new AiProviderException("{$this->name()} API is not configured.");
        }

        $payload = [
            'model' => $model,
            'temperature' => 0.3,
            'max_tokens' => 2048,
            'messages' => $this->formatMessages($systemPrompt, $messages),
            'tools' => $tools->toOpenAiTools(),
            'tool_choice' => 'auto',
        ];

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->post($endpoint, $payload);
        } catch (ConnectionException) {
            throw new AiProviderException("{$this->name()} connection failed.");
        }

        if (! $response->successful()) {
            throw new AiProviderException(sprintf('%s returned HTTP %d.', $this->name(), $response->status()));
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw new AiProviderException("{$this->name()} returned invalid choice message.");
        }

        $text = is_string($message['content'] ?? null) && trim($message['content']) !== ''
            ? trim($message['content'])
            : null;

        $toolCalls = [];
        $rawToolCalls = $message['tool_calls'] ?? [];

        if (is_array($rawToolCalls)) {
            $index = 0;
            foreach ($rawToolCalls as $rawCall) {
                if (! is_array($rawCall) || ! isset($rawCall['function']) || ! is_array($rawCall['function'])) {
                    continue;
                }

                $fnName = $rawCall['function']['name'] ?? null;
                if (! is_string($fnName) || $fnName === '') {
                    continue;
                }

                $callId = is_string($rawCall['id'] ?? null) && $rawCall['id'] !== ''
                    ? $rawCall['id']
                    : sprintf('%s_call_%d_%s', $this->name(), ++$index, $fnName);

                $rawArgs = $rawCall['function']['arguments'] ?? '{}';
                $arguments = [];

                if (is_string($rawArgs) && trim($rawArgs) !== '') {
                    $decoded = json_decode($rawArgs, true);
                    if (is_array($decoded)) {
                        $arguments = $decoded;
                    }
                } elseif (is_array($rawArgs)) {
                    $arguments = $rawArgs;
                }

                $toolCalls[] = new AiToolCall(
                    id: $callId,
                    name: $fnName,
                    arguments: $arguments,
                );
            }
        }

        if ($text === null && $toolCalls === []) {
            throw new AiProviderException("{$this->name()} returned neither text nor tool calls.");
        }

        return new AiChatProviderTurn(
            provider: $this->name(),
            model: $model,
            text: $text,
            toolCalls: $toolCalls,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function formatMessages(string $systemPrompt, array $messages): array
    {
        $formatted = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
        ];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if ($role === 'tool') {
                $content = is_array($message['content'] ?? null)
                    ? (string) json_encode($message['content'], JSON_UNESCAPED_UNICODE)
                    : (string) ($message['content'] ?? '{}');

                $formatted[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($message['tool_call_id'] ?? ''),
                    'name' => (string) ($message['name'] ?? ''),
                    'content' => $content,
                ];

                continue;
            }

            if ($role === 'assistant') {
                $entry = [
                    'role' => 'assistant',
                    'content' => is_string($message['content'] ?? null) ? $message['content'] : null,
                ];

                if (isset($message['tool_calls']) && is_array($message['tool_calls']) && $message['tool_calls'] !== []) {
                    $serializedCalls = [];
                    foreach ($message['tool_calls'] as $call) {
                        if ($call instanceof AiToolCall) {
                            $serializedCalls[] = [
                                'id' => $call->id,
                                'type' => 'function',
                                'function' => [
                                    'name' => $call->name,
                                    'arguments' => (string) json_encode(
                                        $call->arguments === [] ? new stdClass : $call->arguments,
                                        JSON_UNESCAPED_UNICODE,
                                    ),
                                ],
                            ];
                        }
                    }

                    if ($serializedCalls !== []) {
                        $entry['tool_calls'] = $serializedCalls;
                    }
                }

                $formatted[] = $entry;

                continue;
            }

            $formatted[] = [
                'role' => 'user',
                'content' => is_string($message['content'] ?? null) ? $message['content'] : '',
            ];
        }

        return $formatted;
    }
}
