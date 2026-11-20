<?php

namespace App\Services\AiChat;

class OpenRouterChatProvider extends OpenAiCompatibleChatProvider
{
    public function name(): string
    {
        return 'openrouter';
    }

    protected function serviceConfigKey(): string
    {
        return 'openrouter';
    }
}
