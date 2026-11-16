<?php

namespace App\Console\Commands;

use App\Enums\ReminderStatus;
use App\Models\AiPlannerRun;
use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Console\Command;

class PruneRetentionDataCommand extends Command
{
    protected $signature = 'app:prune-retention-data {--days=30 : Number of days to retain soft-deleted records and historical logs}';

    protected $description = 'Permanently purge soft-deleted records and prune old reminder/AI planner logs past retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $prunedReminders = Reminder::query()
            ->whereIn('status', [
                ReminderStatus::Sent->value,
                ReminderStatus::Failed->value,
                ReminderStatus::Cancelled->value,
            ])
            ->where('updated_at', '<', $cutoff)
            ->delete();

        $prunedAiRuns = AiPlannerRun::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $purgedTasks = Task::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->forceDelete();

        $purgedEvents = CalendarEvent::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->forceDelete();

        $purgedProjects = Project::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->forceDelete();

        $this->info(sprintf(
            'Pruned retention data older than %d day(s): %d reminder(s), %d AI run(s), %d task(s), %d event(s), %d project(s).',
            $days,
            $prunedReminders,
            $prunedAiRuns,
            $purgedTasks,
            $purgedEvents,
            $purgedProjects,
        ));

        return self::SUCCESS;
    }
}
