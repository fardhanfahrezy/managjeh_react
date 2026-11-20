<?php

namespace App\Services\AiChat;

use App\Exceptions\AiProviderException;
use App\Mcp\McpToolRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use stdClass;

class GeminiChatProvider implements AiChatProviderInterface
{
    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return (string) config('services.gemini.model', 'gemini-flash-latest');
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function chat(string $systemPrompt, array $messages, McpToolRegistry $tools): AiChatProviderTurn
    {
        $apiKey = (string) config('services.gemini.api_key', '');
        $model = $this->model();
        $baseEndpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = (int) config('services.gemini.timeout', 20);

        if ($apiKey === '' || $model === '') {
            throw new AiProviderException('Gemini API key or model is not configured.');
        }

        $url = sprintf('%s/models/%s:generateContent?key=%s', $baseEndpoint, $model, $apiKey);

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => $this->formatContents($messages),
            'tools' => [
                [
                    'functionDeclarations' => $tools->toGeminiFunctionDeclarations(),
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 2048,
            ],
        ];

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($url, $payload);
        } catch (ConnectionException) {
            throw new AiProviderException('Gemini connection failed.');
        }

        if (! $response->successful()) {
            throw new AiProviderException(sprintf('Gemini returned HTTP %d.', $response->status()));
        }

        $rawParts = $response->json('candidates.0.content.parts');

        if (! is_array($rawParts) || $rawParts === []) {
            throw new AiProviderException('Gemini returned empty response parts.');
        }

        $textFragments = [];
        $toolCalls = [];
        $preservedParts = [];
        $callIndex = 0;

        foreach ($rawParts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $preservedParts[] = $part;

            if (isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                $textFragments[] = trim($part['text']);
            }

            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $fnName = $part['functionCall']['name'] ?? null;
                if (is_string($fnName) && $fnName !== '') {
                    $rawArgs = $part['functionCall']['args'] ?? [];
                    $arguments = is_array($rawArgs) ? $rawArgs : [];
                    $toolCalls[] = new AiToolCall(
                        id: sprintf('gemini_call_%d_%s', ++$callIndex, $fnName),
                        name: $fnName,
                        arguments: $arguments,
                    );
                }
            }
        }

        $text = $textFragments !== [] ? implode("\n\n", $textFragments) : null;

        if ($text === null && $toolCalls === []) {
            throw new AiProviderException('Gemini returned neither text nor tool calls.');
        }

        return new AiChatProviderTurn(
            provider: $this->name(),
            model: $model,
            text: $text,
            toolCalls: $toolCalls,
            rawParts: $preservedParts,
        );
    }

    /**
     * Convert canonical chat history into Gemini `contents` format.
     * Consecutive tool results are grouped into a single user turn of `functionResponse` parts.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function formatContents(array $messages): array
    {
        $contents = [];
        /** @var list<array<string, mixed>> $pendingToolParts */
        $pendingToolParts = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if ($role === 'tool') {
                $toolName = (string) ($message['name'] ?? '');
                $toolPayload = is_array($message['content'] ?? null)
                    ? $message['content']
                    : ['result' => (string) ($message['content'] ?? '')];

                $pendingToolParts[] = [
                    'functionResponse' => [
                        'name' => $toolName,
                        'response' => $toolPayload === [] ? new stdClass : $toolPayload,
                    ],
                ];

                continue;
            }

            if ($pendingToolParts !== []) {
                $contents[] = [
                    'role' => 'user',
                    'parts' => $pendingToolParts,
                ];
                $pendingToolParts = [];
            }

            if ($role === 'assistant') {
                if (isset($message['raw_parts']) && is_array($message['raw_parts']) && $message['raw_parts'] !== []) {
                    $contents[] = [
                        'role' => 'model',
                        'parts' => $message['raw_parts'],
                    ];

                    continue;
                }

                $parts = [];
                $contentText = is_string($message['content'] ?? null) ? trim($message['content']) : '';
                if ($contentText !== '') {
                    $parts[] = ['text' => $contentText];
                }

                if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $call) {
                        if ($call instanceof AiToolCall) {
                            $parts[] = [
                                'functionCall' => [
                                    'name' => $call->name,
                                    'args' => $call->arguments === [] ? new stdClass : $call->arguments,
                                ],
                            ];
                        }
                    }
                }

                if ($parts !== []) {
                    $contents[] = [
                        'role' => 'model',
                        'parts' => $parts,
                    ];
                }

                continue;
            }

            $userText = is_string($message['content'] ?? null) ? $message['content'] : '';
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => $userText],
                ],
            ];
        }

        if ($pendingToolParts !== []) {
            $contents[] = [
                'role' => 'user',
                'parts' => $pendingToolParts,
            ];
        }

        return $contents;
    }
}
