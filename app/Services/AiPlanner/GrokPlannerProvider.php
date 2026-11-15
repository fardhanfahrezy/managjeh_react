<?php

namespace App\Services\AiPlanner;

class GrokPlannerProvider extends OpenAiCompatiblePlannerProvider
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
