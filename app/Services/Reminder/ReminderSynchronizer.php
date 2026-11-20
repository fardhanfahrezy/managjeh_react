<?php

namespace App\Services\Reminder;

use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\RecurrenceExpanderService;
use Carbon\CarbonImmutable;

class ReminderSynchronizer
{
    public function __construct(
        private QuietHoursAdjuster $quietHoursAdjuster,
        private RecurrenceExpanderService $recurrenceExpander,
    ) {}

    public function syncForTask(Task $task): void
    {
        $this->cancelForTask($task);

        if (
            $task->trashed()
            || $task->due_at === null
            || in_array($task->status, [TaskStatus::Completed, TaskStatus::Cancelled], true)
        ) {
            return;
        }

        $user = $task->user;
        $dueAtUtc = CarbonImmutable::instance($task->due_at)->utc();
        $nowUtc = CarbonImmutable::now('UTC');
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $localDueLabel = $dueAtUtc->setTimezone($timezone)->format('d M Y H:i');
        $statusLabel = $task->status === TaskStatus::InProgress ? 'Sedang dikerjakan' : 'Belum selesai';
        $tasksUrl = rtrim((string) config('app.url', 'http://localhost:8000'), '/').'/tasks';

        $windows = [
            'before_24h' => $dueAtUtc->subDay(),
            'before_1h' => $dueAtUtc->subHour(),
        ];

        foreach ($windows as $type => $rawScheduledUtc) {
            $adjustedUtc = $this->quietHoursAdjuster->adjust($rawScheduledUtc, $user);

            if ($adjustedUtc->lte($nowUtc->subMinute()) || $adjustedUtc->gte($dueAtUtc)) {
                continue;
            }

            $prefix = $type === 'before_24h' ? 'Pengingat H-1 hari' : 'Pengingat H-1 jam';
            $suggestion = $type === 'before_24h'
                ? 'Luangkan 30 menit hari ini agar tugas tidak menumpuk mendekati tenggat.'
                : 'Segera finalisasi bagian tersisa dan pastikan sudah siap dikumpulkan.';

            $message = implode("\n\n", [
                "Pengingat Managjeh ({$prefix}):",
                sprintf('Tugas "%s" jatuh tempo pada %s (%s).', $task->title, $localDueLabel, $timezone),
                "Status tugas: {$statusLabel}.",
                "Saran: {$suggestion}",
                "Buka Managjeh untuk melihat detail tugas: {$tasksUrl}",
            ]);

            $this->upsertPendingReminder([
                'user_id' => $task->user_id,
                'task_id' => $task->id,
                'calendar_event_id' => null,
                'reminder_type' => $type,
                'scheduled_at' => $adjustedUtc,
                'message' => $message,
                'idempotency_key' => sprintf('task:%d:%s:%d', $task->id, $type, $adjustedUtc->timestamp),
            ]);
        }
    }

    public function syncForCalendarEvent(CalendarEvent $event): void
    {
        $this->cancelForCalendarEvent($event);

        if (
            $event->trashed()
            || $event->is_cancelled
            || $event->is_all_day
            || $event->starts_at === null
        ) {
            return;
        }

        $user = $event->user;
        $startsAtUtc = CarbonImmutable::instance($event->starts_at)->utc();
        $nowUtc = CarbonImmutable::now('UTC');
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $notifPrefs = $user->resolvedNotificationPreferences();

        $windows = $this->buildEventWindows($startsAtUtc, $notifPrefs['event_prep_enabled']);

        foreach ($windows as $type => $rawScheduledUtc) {
            $adjustedUtc = $this->quietHoursAdjuster->adjust($rawScheduledUtc, $user);

            if ($adjustedUtc->lte($nowUtc->subMinute())) {
                continue;
            }

            $message = $this->formatEventReminderMessage(
                title: $event->title,
                type: $type,
                startsAtUtc: $startsAtUtc,
                timezone: $timezone,
            );

            $this->upsertPendingReminder([
                'user_id' => $event->user_id,
                'task_id' => null,
                'calendar_event_id' => $event->id,
                'reminder_type' => $type,
                'scheduled_at' => $adjustedUtc,
                'message' => $message,
                'idempotency_key' => sprintf('event:%d:%s:%d', $event->id, $type, $adjustedUtc->timestamp),
            ]);
        }
    }

    /**
     * Rolling sync for upcoming recurring calendar event occurrences in the next $hoursAhead hours.
     * Uses deterministic idempotency keys (`event:{series_id}:{occurrence_key}:{type}`) to avoid duplicates.
     */
    public function syncUpcomingRecurringEventsForUser(User $user, int $hoursAhead = 48): int
    {
        $timezone = $user->timezone ?: 'Asia/Jakarta';
        $nowUtc = CarbonImmutable::now('UTC');
        $horizonUtc = $nowUtc->addHours(max(1, $hoursAhead));
        $rangeStartLocal = $nowUtc->setTimezone($timezone)->startOfDay();
        $rangeEndLocal = $horizonUtc->setTimezone($timezone)->endOfDay();

        $events = $user->calendarEvents()
            ->forDisplayRange($rangeStartLocal, $rangeEndLocal)
            ->whereNotNull('recurrence_rule')
            ->where('recurrence_rule', '!=', '')
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        $this->recurrenceExpander->loadRelationsForRange($events, $rangeStartLocal, $rangeEndLocal);
        $occurrences = $this->recurrenceExpander->expand($events, $rangeStartLocal, $rangeEndLocal, $timezone);
        $notifPrefs = $user->resolvedNotificationPreferences();

        $scheduledCount = 0;

        foreach ($occurrences as $occ) {
            if (($occ['is_all_day'] ?? false) || empty($occ['starts_at'])) {
                continue;
            }

            $startsAtUtc = CarbonImmutable::parse((string) $occ['starts_at'])->utc();

            if ($startsAtUtc->lte($nowUtc->subMinute()) || $startsAtUtc->gt($horizonUtc)) {
                continue;
            }

            $seriesId = (int) ($occ['series_id'] ?? $occ['id'] ?? 0);
            $eventId = (int) ($occ['id'] ?? $seriesId);
            $occurrenceKey = is_string($occ['occurrence_date'] ?? null) && $occ['occurrence_date'] !== ''
                ? $occ['occurrence_date']
                : $startsAtUtc->format('Y-m-d');
            $title = (string) ($occ['title'] ?? 'Agenda');

            $windows = $this->buildEventWindows($startsAtUtc, $notifPrefs['event_prep_enabled']);

            foreach ($windows as $type => $rawScheduledUtc) {
                $adjustedUtc = $this->quietHoursAdjuster->adjust($rawScheduledUtc, $user);

                if ($adjustedUtc->lte($nowUtc->subMinute())) {
                    continue;
                }

                $idempotencyKey = sprintf('event:%d:%s:%s', $seriesId, $occurrenceKey, $type);
                $created = $this->upsertPendingReminder([
                    'user_id' => $user->id,
                    'task_id' => null,
                    'calendar_event_id' => $eventId > 0 ? $eventId : null,
                    'reminder_type' => $type,
                    'scheduled_at' => $adjustedUtc,
                    'message' => $this->formatEventReminderMessage($title, $type, $startsAtUtc, $timezone),
                    'idempotency_key' => $idempotencyKey,
                ]);

                if ($created) {
                    $scheduledCount++;
                }
            }
        }

        return $scheduledCount;
    }

    public function cancelForTask(Task $task): void
    {
        Reminder::query()
            ->where('task_id', $task->id)
            ->where('status', ReminderStatus::Pending->value)
            ->update(['status' => ReminderStatus::Cancelled->value]);
    }

    public function cancelForCalendarEvent(CalendarEvent $event): void
    {
        Reminder::query()
            ->where('calendar_event_id', $event->id)
            ->where('status', ReminderStatus::Pending->value)
            ->update(['status' => ReminderStatus::Cancelled->value]);
    }

    /**
     * @return array<string, CarbonImmutable>
     */
    private function buildEventWindows(CarbonImmutable $startsAtUtc, bool $includeEventPrep): array
    {
        $windows = [
            'before_1h' => $startsAtUtc->subHour(),
        ];

        if ($includeEventPrep) {
            $windows['event_prep'] = $startsAtUtc->subMinutes(15);
        }

        $windows['event_start'] = $startsAtUtc;

        return $windows;
    }

    private function formatEventReminderMessage(
        string $title,
        string $type,
        CarbonImmutable $startsAtUtc,
        string $timezone,
    ): string {
        $localStartLabel = $startsAtUtc->setTimezone($timezone)->format('d M Y H:i');
        $calendarUrl = rtrim((string) config('app.url', 'http://localhost:8000'), '/').'/calendar';

        [$prefix, $suggestion] = match ($type) {
            'before_1h' => [
                'Agenda 1 jam lagi',
                'Siapkan materi, catatan, atau perlengkapan yang dibutuhkan.',
            ],
            'event_prep' => [
                'Persiapan kegiatan (15 menit lagi)',
                'Saatnya bersiap dan menuju lokasi atau membuka tautan kelas/rapat.',
            ],
            default => [
                'Agenda dimulai sekarang',
                'Semoga kegiatan berjalan lancar dan fokus.',
            ],
        };

        return implode("\n\n", [
            "Pengingat Managjeh ({$prefix}):",
            sprintf('"%s" pada %s (%s).', $title, $localStartLabel, $timezone),
            "Saran: {$suggestion}",
            "Lihat kalender di Managjeh: {$calendarUrl}",
        ]);
    }

    /**
     * @param  array{
     *     user_id: int,
     *     task_id: int|null,
     *     calendar_event_id: int|null,
     *     reminder_type: string,
     *     scheduled_at: CarbonImmutable,
     *     message: string,
     *     idempotency_key: string,
     * }  $data
     */
    private function upsertPendingReminder(array $data): bool
    {
        $existing = Reminder::query()
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing !== null) {
            if ($existing->status === ReminderStatus::Cancelled) {
                $existing->update([
                    'status' => ReminderStatus::Pending,
                    'scheduled_at' => $data['scheduled_at'],
                    'message' => $data['message'],
                ]);

                return true;
            }

            return false;
        }

        Reminder::query()->create([
            'user_id' => $data['user_id'],
            'task_id' => $data['task_id'],
            'calendar_event_id' => $data['calendar_event_id'],
            'channel' => 'whatsapp',
            'reminder_type' => $data['reminder_type'],
            'scheduled_at' => $data['scheduled_at'],
            'status' => ReminderStatus::Pending,
            'message' => $data['message'],
            'idempotency_key' => $data['idempotency_key'],
        ]);

        return true;
    }
}
