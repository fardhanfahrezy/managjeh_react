<?php

namespace App\Services\AiPlanner;

use App\Contracts\AiPlannerProviderInterface;
use App\Enums\AiPlannerRunStatus;
use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Exceptions\AiProviderException;
use App\Models\AiPlannerRun;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\RecurrenceExpanderService;
use App\Services\Calendar\ScheduleConflictDetector;
use App\Services\Reminder\QuietHoursAdjuster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AiPlannerService
{
    private const int CIRCUIT_FAILURE_THRESHOLD = 3;

    private const int CIRCUIT_WINDOW_SECONDS = 300;

    public function __construct(
        private GeminiPlannerProvider $geminiProvider,
        private GrokPlannerProvider $grokProvider,
        private OpenRouterPlannerProvider $openRouterProvider,
        private FallbackPlannerProvider $fallbackProvider,
        private RecurrenceExpanderService $recurrenceExpander,
        private ScheduleConflictDetector $conflictDetector,
        private QuietHoursAdjuster $quietHoursAdjuster,
    ) {}

    public function generateForUser(User $user, string $triggerType = 'manual'): AiPlannerRun
    {
        $context = $this->buildContext($user);
        $snapshot = $context->toSanitizedSnapshot();

        /** @var array<int, AiPlannerProviderInterface> $providers */
        $providers = [
            $this->geminiProvider,
            $this->grokProvider,
            $this->openRouterProvider,
            $this->fallbackProvider,
        ];

        $errors = [];

        foreach ($providers as $provider) {
            $name = $provider->name();

            if ($name !== 'fallback' && $this->isCircuitOpen($name)) {
                $errors[] = "{$name}: circuit open";

                continue;
            }

            try {
                $response = $provider->generatePlan($context);
                $validatedResult = $this->validateAndNormalizeResult($response->result, $context);

                if ($name !== 'fallback') {
                    $this->resetCircuit($name);
                }

                return AiPlannerRun::query()->create([
                    'user_id' => $user->id,
                    'planning_date' => $context->planningDate,
                    'trigger_type' => $triggerType,
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'status' => AiPlannerRunStatus::Success,
                    'input_snapshot' => $snapshot,
                    'result' => $validatedResult,
                    'error_message' => null,
                ]);
            } catch (AiProviderException $e) {
                $errors[] = "{$name}: {$e->getMessage()}";
                if ($name !== 'fallback') {
                    $this->recordFailure($name);
                }
            } catch (Throwable $e) {
                $errors[] = "{$name}: unexpected error";
                if ($name !== 'fallback') {
                    $this->recordFailure($name);
                }
            }
        }

        return AiPlannerRun::query()->create([
            'user_id' => $user->id,
            'planning_date' => $context->planningDate,
            'trigger_type' => $triggerType,
            'provider' => 'fallback',
            'model' => null,
            'status' => AiPlannerRunStatus::Failed,
            'input_snapshot' => $snapshot,
            'result' => null,
            'error_message' => substr(implode(' | ', $errors), 0, 500),
        ]);
    }

    public function approveReminder(User $user, int $taskId, string $remindAtLocal, string $reason): Reminder
    {
        /** @var Task $task */
        $task = $user->tasks()->findOrFail($taskId);
        $timezone = $user->timezone ?: 'Asia/Jakarta';

        $rawUtc = CarbonImmutable::parse($remindAtLocal, $timezone)->utc();
        $adjustedUtc = $this->quietHoursAdjuster->adjust($rawUtc, $user);

        $idempotencyKey = sprintf('ai_rec:%d:%d:%d', $user->id, $task->id, $adjustedUtc->timestamp);

        $existing = Reminder::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $localLabel = $adjustedUtc->setTimezone($timezone)->format('d M Y H:i');
        $message = sprintf(
            'Pengingat AI Planner: Tugas "%s" dijadwalkan pukul %s (%s). %s',
            $task->title,
            $localLabel,
            $timezone,
            $reason,
        );

        return Reminder::query()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'calendar_event_id' => null,
            'channel' => 'whatsapp',
            'reminder_type' => 'ai_recommended',
            'scheduled_at' => $adjustedUtc,
            'status' => ReminderStatus::Pending,
            'message' => trim($message),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function buildContext(User $user): AiPlannerContext
    {
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $todayLocal = CarbonImmutable::now($timezone)->startOfDay();
        $todayStartUtc = $todayLocal->utc();

        $activeTasks = $user->tasks()
            ->whereNull('parent_id')
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->with('project:id,name')
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->limit(25)
            ->get()
            ->map(function (Task $task) use ($todayStartUtc, $timezone): array {
                $localDue = $task->dueAtInUserTimezone($timezone);

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status->value,
                    'priority' => $task->priority->value,
                    'due_at_local' => $localDue?->format('Y-m-d\TH:i'),
                    'is_overdue' => $task->due_at !== null && $task->due_at->lt($todayStartUtc),
                    'project_name' => $task->project !== null ? $task->project->name : null,
                ];
            })
            ->values()
            ->all();

        $rawEvents = $user->calendarEvents()
            ->whereNull('parent_event_id')
            ->with(['project:id,name', 'task:id,title,status', 'overrides.project:id,name', 'overrides.task:id,title,status'])
            ->get();

        $expanded = $this->conflictDetector->annotateConflicts(
            $this->recurrenceExpander->expand($rawEvents, $todayLocal, $todayLocal, $timezone),
        );

        $todayEvents = array_map(fn (array $event): array => [
            'title' => (string) $event['title'],
            'starts_at_local' => is_string($event['starts_at_local']) ? $event['starts_at_local'] : null,
            'ends_at_local' => is_string($event['ends_at_local']) ? $event['ends_at_local'] : null,
            'is_all_day' => (bool) $event['is_all_day'],
            'has_conflict' => (bool) $event['has_conflict'],
        ], $expanded);

        $freeSlots = $this->calculateFreeSlots($todayEvents, $todayLocal->format('Y-m-d'));

        return new AiPlannerContext(
            planningDate: $todayLocal->format('Y-m-d'),
            timezone: $timezone,
            activeTasks: $activeTasks,
            todayEvents: $todayEvents,
            freeSlots: $freeSlots,
        );
    }

    /**
     * @param  array<int, array{
     *     title: string,
     *     starts_at_local: string|null,
     *     ends_at_local: string|null,
     *     is_all_day: bool,
     *     has_conflict: bool,
     * }>  $todayEvents
     * @return array<int, array{start: string, end: string, minutes: int}>
     */
    private function calculateFreeSlots(array $todayEvents, string $dateKey): array
    {
        $dayStart = 6 * 60;
        $dayEnd = 22 * 60;
        $busy = [];

        foreach ($todayEvents as $event) {
            if ($event['is_all_day'] || $event['starts_at_local'] === null || $event['ends_at_local'] === null) {
                continue;
            }

            if (! str_starts_with($event['starts_at_local'], $dateKey)) {
                continue;
            }

            $startMins = $this->toMinutes($event['starts_at_local']);
            $endMins = $this->toMinutes($event['ends_at_local']);

            if ($endMins > $dayStart && $startMins < $dayEnd) {
                $busy[] = [max($dayStart, $startMins), min($dayEnd, $endMins)];
            }
        }

        usort($busy, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $slots = [];
        $cursor = $dayStart;

        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($busyStart - $cursor >= 30) {
                $slots[] = [
                    'start' => $this->formatMinutes($cursor),
                    'end' => $this->formatMinutes($busyStart),
                    'minutes' => $busyStart - $cursor,
                ];
            }
            $cursor = max($cursor, $busyEnd);
        }

        if ($dayEnd - $cursor >= 30) {
            $slots[] = [
                'start' => $this->formatMinutes($cursor),
                'end' => $this->formatMinutes($dayEnd),
                'minutes' => $dayEnd - $cursor,
            ];
        }

        return array_slice($slots, 0, 5);
    }

    private function toMinutes(string $localIso): int
    {
        $hour = (int) substr($localIso, 11, 2);
        $minute = (int) substr($localIso, 14, 2);

        return $hour * 60 + $minute;
    }

    private function formatMinutes(int $totalMinutes): string
    {
        return sprintf('%02d:%02d', intdiv($totalMinutes, 60), $totalMinutes % 60);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function validateAndNormalizeResult(array $raw, AiPlannerContext $context): array
    {
        $taskTitlesById = [];
        foreach ($context->activeTasks as $task) {
            $taskTitlesById[$task['id']] = $task['title'];
        }

        $priorities = [];
        if (isset($raw['priorities']) && is_array($raw['priorities'])) {
            foreach ($raw['priorities'] as $item) {
                if (! is_array($item) || ! isset($item['task_id']) || ! is_numeric($item['task_id'])) {
                    continue;
                }

                $taskId = (int) $item['task_id'];
                if (! isset($taskTitlesById[$taskId])) {
                    continue;
                }

                $priorities[] = [
                    'task_id' => $taskId,
                    'task_title' => $taskTitlesById[$taskId],
                    'reason' => is_string($item['reason'] ?? null) ? trim($item['reason']) : 'Prioritas utama hari ini.',
                    'suggested_action' => is_string($item['suggested_action'] ?? null) ? trim($item['suggested_action']) : 'Kerjakan langkah pertama.',
                    'estimated_minutes' => max(5, min(480, (int) ($item['estimated_minutes'] ?? 45))),
                ];

                if (count($priorities) >= 3) {
                    break;
                }
            }
        }

        $recommendedReminders = [];
        if (isset($raw['recommended_reminders']) && is_array($raw['recommended_reminders'])) {
            foreach ($raw['recommended_reminders'] as $item) {
                if (! is_array($item) || ! isset($item['task_id']) || ! is_numeric($item['task_id'])) {
                    continue;
                }

                $taskId = (int) $item['task_id'];
                $remindAtLocal = $item['remind_at_local'] ?? null;

                if (! isset($taskTitlesById[$taskId]) || ! is_string($remindAtLocal) || trim($remindAtLocal) === '') {
                    continue;
                }

                $recommendedReminders[] = [
                    'task_id' => $taskId,
                    'task_title' => $taskTitlesById[$taskId],
                    'remind_at_local' => trim($remindAtLocal),
                    'reason' => is_string($item['reason'] ?? null) ? trim($item['reason']) : 'Waktu fokus yang disarankan.',
                ];
            }
        }

        $warnings = [];
        if (isset($raw['warnings']) && is_array($raw['warnings'])) {
            foreach ($raw['warnings'] as $item) {
                if (! is_array($item) || ! is_string($item['message'] ?? null)) {
                    continue;
                }

                $warnings[] = [
                    'type' => is_string($item['type'] ?? null) ? $item['type'] : 'info',
                    'message' => trim($item['message']),
                ];
            }
        }

        $summary = is_string($raw['summary'] ?? null) && trim($raw['summary']) !== ''
            ? trim($raw['summary'])
            : 'Rencana prioritas harian telah disusun.';

        return [
            'summary' => $summary,
            'priorities' => $priorities,
            'recommended_reminders' => $recommendedReminders,
            'warnings' => $warnings,
        ];
    }

    private function isCircuitOpen(string $provider): bool
    {
        return (bool) Cache::get("ai_planner:cb:{$provider}:open", false);
    }

    private function recordFailure(string $provider): void
    {
        $countKey = "ai_planner:cb:{$provider}:failures";
        $failures = (int) Cache::get($countKey, 0) + 1;

        Cache::put($countKey, $failures, self::CIRCUIT_WINDOW_SECONDS);

        if ($failures >= self::CIRCUIT_FAILURE_THRESHOLD) {
            Cache::put("ai_planner:cb:{$provider}:open", true, self::CIRCUIT_WINDOW_SECONDS);
        }
    }

    private function resetCircuit(string $provider): void
    {
        Cache::forget("ai_planner:cb:{$provider}:failures");
        Cache::forget("ai_planner:cb:{$provider}:open");
    }
}
