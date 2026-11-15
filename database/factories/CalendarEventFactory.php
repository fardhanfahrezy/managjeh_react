<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now('UTC')->addDay()->setTime(9, 0);

        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'task_id' => null,
            'parent_event_id' => null,
            'recurrence_id' => null,
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'start_date' => null,
            'end_date' => null,
            'is_all_day' => false,
            'is_cancelled' => false,
            'recurrence_rule' => null,
            'recurrence_until' => null,
            'recurrence_exceptions' => null,
        ];
    }

    public function allDay(string $startDate = '2026-11-20', ?string $endDate = null): static
    {
        return $this->state(fn (): array => [
            'is_all_day' => true,
            'starts_at' => null,
            'ends_at' => null,
            'start_date' => $startDate,
            'end_date' => $endDate ?? $startDate,
        ]);
    }

    public function recurring(string $rule = 'FREQ=WEEKLY;INTERVAL=1', ?string $until = null): static
    {
        return $this->state(fn (): array => [
            'recurrence_rule' => $rule,
            'recurrence_until' => $until,
        ]);
    }
}
