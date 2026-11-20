<?php

namespace App\Services\AiChat;

final readonly class AiChatProviderTurn
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     * @param  list<array<string, mixed>>  $rawParts  Provider-specific assistant parts (e.g. Gemini thoughtSignature preservation)
     */
    public function __construct(
        public string $provider,
        public string $model,
        public ?string $text = null,
        public array $toolCalls = [],
        public array $rawParts = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
