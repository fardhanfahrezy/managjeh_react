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
     * @param  array{
     *     tone?: string,
     *     preferred_study_start?: string,
     *     preferred_study_end?: string,
     *     default_focus_minutes?: int,
     *     custom_instruction?: string|null,
     * }  $aiPreferences
     */
    public function __construct(
        public string $planningDate,
        public string $timezone,
        public array $activeTasks,
        public array $todayEvents,
        public array $freeSlots,
        public array $aiPreferences = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedSnapshot(): array
    {
        $snapshot = [
            'planning_date' => $this->planningDate,
            'timezone' => $this->timezone,
            'tasks' => $this->activeTasks,
            'events' => $this->todayEvents,
            'free_slots' => $this->freeSlots,
        ];

        if ($this->aiPreferences !== []) {
            $snapshot['ai_preferences'] = [
                'tone' => $this->aiPreferences['tone'] ?? 'balanced',
                'preferred_study_start' => $this->aiPreferences['preferred_study_start'] ?? '19:00',
                'preferred_study_end' => $this->aiPreferences['preferred_study_end'] ?? '22:00',
                'default_focus_minutes' => $this->aiPreferences['default_focus_minutes'] ?? 60,
                'custom_instruction' => $this->aiPreferences['custom_instruction'] ?? null,
            ];
        }

        return $snapshot;
    }
}
