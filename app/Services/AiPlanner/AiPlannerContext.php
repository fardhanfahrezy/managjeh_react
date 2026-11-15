<?php

namespace App\Services\AiPlanner;

readonly class AiPlannerContext
{
    /**
     * @param  array<int, array{
     *     id: int,
     *     title: string,
     *     status: string,
     *     priority: string,
     *     due_at_local: string|null,
     *     is_overdue: bool,
     *     project_name: string|null,
     * }>  $activeTasks
     * @param  array<int, array{
     *     title: string,
     *     starts_at_local: string|null,
     *     ends_at_local: string|null,
     *     is_all_day: bool,
     *     has_conflict: bool,
     * }>  $todayEvents
     * @param  array<int, array{
     *     start: string,
     *     end: string,
     *     minutes: int,
     * }>  $freeSlots
     */
    public function __construct(
        public string $planningDate,
        public string $timezone,
        public array $activeTasks,
        public array $todayEvents,
        public array $freeSlots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedSnapshot(): array
    {
        return [
            'planning_date' => $this->planningDate,
            'timezone' => $this->timezone,
            'tasks' => $this->activeTasks,
            'events' => $this->todayEvents,
            'free_slots' => $this->freeSlots,
        ];
    }
}
