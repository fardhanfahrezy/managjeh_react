<?php

namespace App\Services\AiPlanner;

readonly class AiPlannerProviderResponse
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $provider,
        public ?string $model,
        public array $result,
    ) {}
}
