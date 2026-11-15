<?php

namespace Database\Factories;

use App\Enums\AiPlannerRunStatus;
use App\Models\AiPlannerRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPlannerRun>
 */
class AiPlannerRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'planning_date' => now()->format('Y-m-d'),
            'trigger_type' => 'manual',
            'provider' => 'fallback',
            'model' => null,
            'status' => AiPlannerRunStatus::Success,
            'input_snapshot' => [
                'planning_date' => now()->format('Y-m-d'),
                'tasks' => [],
                'events' => [],
                'free_slots' => [],
            ],
            'result' => [
                'summary' => 'Fokus menyelesaikan tugas prioritas hari ini.',
                'priorities' => [],
                'recommended_reminders' => [],
                'warnings' => [],
            ],
            'error_message' => null,
        ];
    }
}
