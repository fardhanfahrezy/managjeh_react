<?php

namespace App\Services;

use App\Enums\AiPlannerRunStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\AiPlannerRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\RecurrenceExpanderService;
use App\Services\Calendar\ScheduleConflictDetector;
use Carbon\CarbonImmutable;

class DashboardAggregatorService
{
    public function __construct(
        private RecurrenceExpanderService $recurrenceExpander,
        private ScheduleConflictDetector $conflictDetector,
    ) {}

    /**
     * Aggregate all 8 dashboard sections for the authenticated user without N+1 queries.
     *
     * @return array<string, mixed>
     */
    public function aggregate(User $user): array
    {
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $nowLocal = CarbonImmutable::now($timezone);
        $todayStartLocal = $nowLocal->startOfDay();
        $todayEndLocal = $nowLocal->endOfDay();

        $todayStartUtc = $todayStartLocal->utc();
        $todayEndUtc = $todayEndLocal->utc();
        $upcomingEndUtc = $todayStartLocal->addDays(7)->endOfDay()->utc();
        $sevenDaysStartUtc = $todayStartLocal->subDays(6)->startOfDay()->utc();

        $todayTasks = $this->getTodayTasks($user, $todayStartUtc, $todayEndUtc, $timezone);
        $upcomingTasks = $this->getUpcomingTasks($user, $todayStartUtc, $todayEndUtc, $upcomingEndUtc, $timezone);
        $todayEvents = $this->getTodayEvents($user, $todayStartLocal, $timezone);
        $activeProjects = $this->getActiveProjects($user);
        $completedSummary = $this->getCompletedSummary($user, $todayStartUtc, $todayEndUtc, $sevenDaysStartUtc);

        $localizedNow = $nowLocal->copy();
        $localizedNow->setLocale('id');

        return [
            'greeting' => $this->resolveGreeting($nowLocal->hour),
            'local_date' => $nowLocal->format('Y-m-d'),
            'local_date_label' => $localizedNow->translatedFormat('l, d F Y'),
            'timezone' => $timezone,
            'today_tasks' => $todayTasks,
            'upcoming_tasks' => $upcomingTasks,
            'today_events' => $todayEvents,
            'active_projects' => $activeProjects,
            'ai_priorities' => $this->getTodayAiPriorities($user, $nowLocal->format('Y-m-d')),
            'whatsapp_status' => $this->getWhatsAppStatus($user),
            'completed_summary' => $completedSummary,
        ];
    }

    private function resolveGreeting(int $hour): string
    {
        if ($hour >= 4 && $hour < 11) {
            return 'Selamat pagi';
        }

        if ($hour >= 11 && $hour < 15) {
            return 'Selamat siang';
        }

        if ($hour >= 15 && $hour < 18) {
            return 'Selamat sore';
        }

        return 'Selamat malam';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTodayTasks(
        User $user,
        CarbonImmutable $todayStartUtc,
        CarbonImmutable $todayEndUtc,
        string $timezone,
    ): array {
        return $user->tasks()
            ->whereNull('parent_id')
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->where(function ($query) use ($todayStartUtc, $todayEndUtc): void {
                $query->where('status', TaskStatus::InProgress->value)
                    ->orWhereBetween('due_at', [$todayStartUtc, $todayEndUtc]);
            })
            ->with('project:id,name,status')
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('sort_order')
            ->limit(10)
            ->get()
            ->map(fn (Task $task): array => $this->serializeTask($task, $todayStartUtc, $timezone))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getUpcomingTasks(
        User $user,
        CarbonImmutable $todayStartUtc,
        CarbonImmutable $todayEndUtc,
        CarbonImmutable $upcomingEndUtc,
        string $timezone,
    ): array {
        return $user->tasks()
            ->whereNull('parent_id')
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->whereNotNull('due_at')
            ->where(function ($query) use ($todayStartUtc, $todayEndUtc, $upcomingEndUtc): void {
                $query->where('due_at', '<', $todayStartUtc)
                    ->orWhere(function ($inner) use ($todayEndUtc, $upcomingEndUtc): void {
                        $inner->where('due_at', '>', $todayEndUtc)
                            ->where('due_at', '<=', $upcomingEndUtc);
                    });
            })
            ->with('project:id,name,status')
            ->orderBy('due_at')
            ->limit(8)
            ->get()
            ->map(fn (Task $task): array => $this->serializeTask($task, $todayStartUtc, $timezone))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTodayEvents(
        User $user,
        CarbonImmutable $todayLocal,
        string $timezone,
    ): array {
        $rawEvents = $user->calendarEvents()
            ->whereNull('parent_event_id')
            ->with([
                'project:id,name',
                'task:id,title,status',
                'overrides.project:id,name',
                'overrides.task:id,title,status',
            ])
            ->get();

        $occurrences = $this->recurrenceExpander->expand($rawEvents, $todayLocal, $todayLocal, $timezone);

        return $this->conflictDetector->annotateConflicts($occurrences);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getActiveProjects(User $user): array
    {
        return $user->projects()
            ->where('status', ProjectStatus::Active->value)
            ->withProgressCounts()
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status->value,
                'due_date' => $project->due_date?->format('Y-m-d'),
                'progress' => $project->calculateProgress(),
                'completed_tasks_count' => (int) ($project->completed_tasks_count ?? 0),
                'non_cancelled_tasks_count' => (int) ($project->non_cancelled_tasks_count ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function getWhatsAppStatus(User $user): array
    {
        $quietStart = substr((string) ($user->reminder_quiet_start ?? '00:00'), 0, 5);
        $quietEnd = substr((string) ($user->reminder_quiet_end ?? '05:00'), 0, 5);

        return [
            'enabled' => $user->whatsapp_enabled,
            'verified' => $user->whatsapp_verified_at !== null,
            'can_receive' => $user->canReceiveWhatsAppReminders(),
            'number_masked' => $this->maskPhoneNumber($user->whatsapp_number),
            'quiet_hours' => sprintf('%s–%s', $quietStart, $quietEnd),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function getCompletedSummary(
        User $user,
        CarbonImmutable $todayStartUtc,
        CarbonImmutable $todayEndUtc,
        CarbonImmutable $sevenDaysStartUtc,
    ): array {
        $todayCount = $user->tasks()
            ->where('status', TaskStatus::Completed->value)
            ->whereBetween('completed_at', [$todayStartUtc, $todayEndUtc])
            ->count();

        $last7DaysCount = $user->tasks()
            ->where('status', TaskStatus::Completed->value)
            ->where('completed_at', '>=', $sevenDaysStartUtc)
            ->count();

        $activeCount = $user->tasks()
            ->whereNull('parent_id')
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->count();

        return [
            'today_count' => $todayCount,
            'last_7_days_count' => $last7DaysCount,
            'active_count' => $activeCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTask(Task $task, CarbonImmutable $todayStartUtc, string $timezone): array
    {
        $localDue = $task->dueAtInUserTimezone($timezone);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_at' => $task->due_at?->toISOString(),
            'due_at_local' => $localDue?->format('Y-m-d\TH:i'),
            'is_overdue' => $task->due_at !== null && $task->due_at->lt($todayStartUtc),
            'project' => $task->project !== null ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
                'status' => $task->project->status->value,
            ] : null,
        ];
    }

    private function maskPhoneNumber(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        $len = strlen($number);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($number, -4);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getTodayAiPriorities(User $user, string $localDate): ?array
    {
        /** @var AiPlannerRun|null $run */
        $run = $user->aiPlannerRuns()
            ->where('planning_date', $localDate)
            ->where('status', AiPlannerRunStatus::Success->value)
            ->latest('id')
            ->first();

        if ($run === null || ! is_array($run->result)) {
            return null;
        }

        return [
            'provider' => $run->provider,
            'summary' => is_string($run->result['summary'] ?? null) ? $run->result['summary'] : null,
            'priorities' => is_array($run->result['priorities'] ?? null) ? $run->result['priorities'] : [],
        ];
    }
}
