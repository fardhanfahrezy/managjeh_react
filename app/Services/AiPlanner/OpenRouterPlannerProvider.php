<?php

namespace App\Services\AiPlanner;

class OpenRouterPlannerProvider extends OpenAiCompatiblePlannerProvider
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
