<?php

namespace App\Services\AiChat;

class GrokChatProvider extends OpenAiCompatibleChatProvider
{
    public function name(): string
    {
        return 'grok';
    }

    protected function serviceConfigKey(): string
    {
        return 'grok';
    }
}
