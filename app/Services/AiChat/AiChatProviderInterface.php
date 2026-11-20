<?php

namespace App\Services\AiChat;

use App\Exceptions\AiProviderException;
use App\Mcp\McpToolRegistry;

interface AiChatProviderInterface
{
    public function name(): string;

    public function model(): string;

    /**
     * Send a single conversation turn with native tool calling declarations.
     *
     * @param  list<array<string, mixed>>  $messages
     *
     * @throws AiProviderException
     */
    public function chat(string $systemPrompt, array $messages, McpToolRegistry $tools): AiChatProviderTurn;
}
