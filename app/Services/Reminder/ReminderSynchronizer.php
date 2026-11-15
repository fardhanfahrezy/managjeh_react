<?php

namespace App\Services\Reminder;

use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use Carbon\CarbonImmutable;

class ReminderSynchronizer
{
    public function __construct(
        private QuietHoursAdjuster $quietHoursAdjuster,
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
            $message = sprintf('%s: Tugas "%s" jatuh tempo pada %s (%s).', $prefix, $task->title, $localDueLabel, $timezone);

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
        $localStartLabel = $startsAtUtc->setTimezone($timezone)->format('d M Y H:i');

        $windows = [
            'before_1h' => $startsAtUtc->subHour(),
            'event_start' => $startsAtUtc,
        ];

        foreach ($windows as $type => $rawScheduledUtc) {
            $adjustedUtc = $this->quietHoursAdjuster->adjust($rawScheduledUtc, $user);

            if ($adjustedUtc->lte($nowUtc->subMinute())) {
                continue;
            }

            $prefix = $type === 'before_1h' ? 'Agenda 1 jam lagi' : 'Agenda dimulai';
            $message = sprintf('%s: "%s" pada %s (%s).', $prefix, $event->title, $localStartLabel, $timezone);

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
    private function upsertPendingReminder(array $data): void
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
            }

            return;
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
    }
}
