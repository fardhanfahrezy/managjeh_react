<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Http\Requests\Calendar\StoreCalendarEventRequest;
use App\Http\Requests\Calendar\UpdateCalendarEventRequest;
use App\Models\CalendarEvent;
use App\Models\Task;
use App\Services\Calendar\RecurrenceExpanderService;
use App\Services\Calendar\ScheduleConflictDetector;
use App\Services\Reminder\ReminderSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CalendarEventController extends Controller
{
    public function __construct(
        private RecurrenceExpanderService $expander,
        private ScheduleConflictDetector $conflictDetector,
        private ReminderSynchronizer $reminderSynchronizer,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $timezone = $user->timezone;

        $view = $request->string('view')->toString();
        if (! in_array($view, ['day', 'week', 'month'], true)) {
            $view = 'week';
        }

        $dateParam = $request->string('date')->toString();
        $activeDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam) === 1
            ? CarbonImmutable::parse($dateParam, $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();

        [$rangeStartLocal, $rangeEndLocal] = $this->resolveRange($view, $activeDate);

        /** @var Collection<int, CalendarEvent> $rawEvents */
        $rawEvents = $user->calendarEvents()
            ->forDisplayRange($rangeStartLocal, $rangeEndLocal)
            ->get();

        $this->expander->loadRelationsForRange($rawEvents, $rangeStartLocal, $rangeEndLocal);

        $occurrences = $this->expander->expand($rawEvents, $rangeStartLocal, $rangeEndLocal, $timezone);
        $occurrences = $this->conflictDetector->annotateConflicts($occurrences);

        $taskDeadlines = $user->tasks()
            ->where('status', '!=', TaskStatus::Cancelled)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$rangeStartLocal->startOfDay()->utc(), $rangeEndLocal->endOfDay()->utc()])
            ->with('project:id,name')
            ->orderBy('due_at')
            ->get()
            ->map(function (Task $task) use ($timezone): array {
                $localDue = $task->dueAtInUserTimezone($timezone);

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status->value,
                    'priority' => $task->priority->value,
                    'due_at' => $task->due_at?->toISOString(),
                    'due_at_local' => $localDue?->format('Y-m-d\TH:i'),
                    'due_date_local' => $localDue?->format('Y-m-d'),
                    'project' => $task->project !== null ? [
                        'id' => $task->project->id,
                        'name' => $task->project->name,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $projects = $user->projects()->select(['id', 'name', 'status'])->orderBy('name')->get();
        $tasksForSelect = $user->tasks()
            ->whereNull('parent_id')
            ->select(['id', 'title', 'status'])
            ->orderBy('title')
            ->get();

        return Inertia::render('calendar/index', [
            'view' => $view,
            'active_date' => $activeDate->format('Y-m-d'),
            'range_start' => $rangeStartLocal->format('Y-m-d'),
            'range_end' => $rangeEndLocal->format('Y-m-d'),
            'events' => $occurrences,
            'task_deadlines' => $taskDeadlines,
            'projects' => $projects,
            'tasks_for_select' => $tasksForSelect,
            'conflict_warnings' => $request->session()->get('conflict_warnings', []),
        ]);
    }

    public function store(StoreCalendarEventRequest $request): RedirectResponse
    {
        $user = $request->user();
        /** @var CalendarEvent $event */
        $event = $user->calendarEvents()->create($request->normalizedValidated());
        $this->reminderSynchronizer->syncForCalendarEvent($event);

        $conflicts = $this->detectConflictsForEvent($user->id, $event, $user->timezone);

        Inertia::flash('toast', [
            'type' => $conflicts !== [] ? 'warning' : 'success',
            'message' => $conflicts !== []
                ? 'Agenda disimpan, namun terdapat jadwal yang bentrok.'
                : 'Agenda berhasil ditambahkan.',
        ]);

        return back()->with('conflict_warnings', $conflicts);
    }

    public function update(UpdateCalendarEventRequest $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        $user = $request->user();
        $editScope = $request->string('edit_scope')->toString();
        $recurrenceId = $request->string('recurrence_id')->toString();

        $master = $calendarEvent->parent_event_id !== null
            ? ($calendarEvent->parentEvent ?? $calendarEvent)
            : $calendarEvent;

        if ($editScope === 'single' && $master->recurrence_rule !== null && $recurrenceId !== '') {
            /** @var CalendarEvent|null $existingOverride */
            $existingOverride = $master->overrides()
                ->where('recurrence_id', $recurrenceId)
                ->first();

            $payload = $request->normalizedValidated($existingOverride ?? $master);
            unset($payload['recurrence_rule'], $payload['recurrence_until']);

            if ($existingOverride !== null) {
                $existingOverride->update($payload);
                $updated = $existingOverride;
            } else {
                $merged = array_merge([
                    'user_id' => $user->id,
                    'parent_event_id' => $master->id,
                    'recurrence_id' => $recurrenceId,
                    'title' => $master->title,
                    'description' => $master->description,
                    'project_id' => $master->project_id,
                    'task_id' => $master->task_id,
                    'is_all_day' => $master->is_all_day,
                    'is_cancelled' => false,
                    'starts_at' => $master->starts_at,
                    'ends_at' => $master->ends_at,
                    'start_date' => $master->start_date,
                    'end_date' => $master->end_date,
                ], $payload);

                /** @var CalendarEvent $updated */
                $updated = CalendarEvent::query()->create($merged);
            }
        } else {
            $payload = $request->normalizedValidated($calendarEvent);
            $calendarEvent->update($payload);
            $updated = $calendarEvent;
        }

        $this->reminderSynchronizer->syncForCalendarEvent($updated);
        $conflicts = $this->detectConflictsForEvent($user->id, $updated, $user->timezone);

        Inertia::flash('toast', [
            'type' => $conflicts !== [] ? 'warning' : 'success',
            'message' => $conflicts !== []
                ? 'Agenda diperbarui, namun terdapat jadwal yang bentrok.'
                : 'Agenda berhasil diperbarui.',
        ]);

        return back()->with('conflict_warnings', $conflicts);
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        Gate::authorize('delete', $calendarEvent);

        $editScope = $request->string('edit_scope')->toString();
        $recurrenceId = $request->string('recurrence_id')->toString();

        $master = $calendarEvent->parent_event_id !== null
            ? ($calendarEvent->parentEvent ?? $calendarEvent)
            : $calendarEvent;

        if ($editScope === 'single' && $master->recurrence_rule !== null && $recurrenceId !== '') {
            /** @var array<int, string> $exceptions */
            $exceptions = $master->recurrence_exceptions ?? [];
            if (! in_array($recurrenceId, $exceptions, true)) {
                $exceptions[] = $recurrenceId;
                $master->update(['recurrence_exceptions' => array_values($exceptions)]);
            }

            $master->overrides()
                ->where('recurrence_id', $recurrenceId)
                ->update(['is_cancelled' => true]);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Kejadian agenda pada tanggal tersebut dibatalkan.',
            ]);

            return back();
        }

        $master->delete();
        $this->reminderSynchronizer->cancelForCalendarEvent($master);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Agenda berhasil dihapus.',
        ]);

        return back();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(string $view, CarbonImmutable $activeDate): array
    {
        if ($view === 'day') {
            return [$activeDate->startOfDay(), $activeDate->endOfDay()];
        }

        if ($view === 'month') {
            $monthStart = $activeDate->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
            $monthEnd = $activeDate->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

            return [$monthStart, $monthEnd];
        }

        return [
            $activeDate->startOfWeek(CarbonImmutable::MONDAY),
            $activeDate->endOfWeek(CarbonImmutable::SUNDAY),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectConflictsForEvent(int $userId, CalendarEvent $event, string $timezone): array
    {
        if ($event->is_all_day || $event->starts_at === null || $event->ends_at === null) {
            return [];
        }

        $rangeStartLocal = $event->starts_at->setTimezone($timezone)->startOfDay();
        $rangeEndLocal = $event->ends_at->setTimezone($timezone)->endOfDay();

        $rawEvents = CalendarEvent::query()
            ->where('user_id', $userId)
            ->whereNull('parent_event_id')
            ->with(['overrides'])
            ->get();

        $occurrences = $this->expander->expand($rawEvents, $rangeStartLocal, $rangeEndLocal, $timezone);

        return $this->conflictDetector->findConflictsForInterval(
            $event->starts_at,
            $event->ends_at,
            $occurrences,
            $event->id,
        );
    }
}
