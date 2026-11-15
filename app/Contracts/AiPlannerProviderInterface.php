<?php

namespace App\Contracts;

use App\Exceptions\AiProviderException;
use App\Services\AiPlanner\AiPlannerContext;
use App\Services\AiPlanner\AiPlannerProviderResponse;

interface AiPlannerProviderInterface
{
    public function name(): string;

    public function model(): ?string;

    /**
     * @throws AiProviderException
     */
    public function generatePlan(AiPlannerContext $context): AiPlannerProviderResponse;
}
