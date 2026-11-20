<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

class AiCircuitBreaker
{
    public const int FAILURE_THRESHOLD = 3;

    public const int WINDOW_SECONDS = 300;

    public function isOpen(string $provider, string $namespace = 'ai_planner'): bool
    {
        return (bool) Cache::get($this->openKey($provider, $namespace), false);
    }

    public function recordFailure(string $provider, string $namespace = 'ai_planner'): void
    {
        $failuresKey = $this->failuresKey($provider, $namespace);
        $failures = (int) Cache::get($failuresKey, 0) + 1;

        Cache::put($failuresKey, $failures, self::WINDOW_SECONDS);

        if ($failures >= self::FAILURE_THRESHOLD) {
            Cache::put($this->openKey($provider, $namespace), true, self::WINDOW_SECONDS);
        }
    }

    public function reset(string $provider, string $namespace = 'ai_planner'): void
    {
        Cache::forget($this->failuresKey($provider, $namespace));
        Cache::forget($this->openKey($provider, $namespace));
    }

    private function failuresKey(string $provider, string $namespace): string
    {
        return "{$namespace}:cb:{$provider}:failures";
    }

    private function openKey(string $provider, string $namespace): string
    {
        return "{$namespace}:cb:{$provider}:open";
    }
}
