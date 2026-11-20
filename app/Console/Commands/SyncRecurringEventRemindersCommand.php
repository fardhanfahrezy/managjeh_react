<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Reminder\ReminderSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SyncRecurringEventRemindersCommand extends Command
{
    protected $signature = 'reminders:sync-recurring {--hours=48 : Horizon in hours for rolling recurring event sync}';

    protected $description = 'Synchronize upcoming recurring calendar event reminders for the next 48 hours';

    public function handle(ReminderSynchronizer $synchronizer): int
    {
        $hoursAhead = max(1, min(168, (int) $this->option('hours')));
        $totalScheduled = 0;

        User::query()
            ->where('whatsapp_enabled', true)
            ->whereNotNull('whatsapp_number')
            ->whereNotNull('whatsapp_verified_at')
            ->chunkById(100, function (Collection $users) use ($synchronizer, $hoursAhead, &$totalScheduled): void {
                foreach ($users as $user) {
                    $totalScheduled += $synchronizer->syncUpcomingRecurringEventsForUser($user, $hoursAhead);
                }
            });

        $this->info(sprintf('Synchronized %d recurring event reminder(s).', $totalScheduled));

        return self::SUCCESS;
    }
}
