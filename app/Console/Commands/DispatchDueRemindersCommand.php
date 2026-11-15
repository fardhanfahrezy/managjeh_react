<?php

namespace App\Console\Commands;

use App\Enums\ReminderStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Reminder;
use Illuminate\Console\Command;

class DispatchDueRemindersCommand extends Command
{
    protected $signature = 'reminders:dispatch-due';

    protected $description = 'Dispatch due WhatsApp reminders to the queue';

    public function handle(): int
    {
        $dueIds = Reminder::query()
            ->where('status', ReminderStatus::Pending->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->pluck('id');

        $dispatched = 0;

        foreach ($dueIds as $reminderId) {
            $claimed = Reminder::query()
                ->where('id', $reminderId)
                ->where('status', ReminderStatus::Pending->value)
                ->update(['status' => ReminderStatus::Processing->value]);

            if ($claimed === 1) {
                SendWhatsAppMessageJob::dispatch($reminderId);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} due reminder(s).");

        return self::SUCCESS;
    }
}
