<?php

namespace App\Console\Commands;

use App\Enums\AiActionProposalStatus;
use App\Enums\ReminderStatus;
use App\Models\AiActionProposal;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\AiPlannerRun;
use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Console\Command;

class PruneRetentionDataCommand extends Command
{
    protected $signature = 'app:prune-retention-data {--days=30 : Number of days to retain soft-deleted records and historical logs}';

    protected $description = 'Permanently purge soft-deleted records and prune old reminder/AI logs and chat history past retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $expiredProposals = AiActionProposal::query()
            ->where('status', AiActionProposalStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['status' => AiActionProposalStatus::Expired->value]);

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

        $prunedProposals = AiActionProposal::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $prunedChatMessages = AiChatMessage::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $prunedConversations = AiChatConversation::query()
            ->whereRaw('COALESCE(last_message_at, created_at) < ?', [$cutoff])
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
            'Pruned retention data older than %d day(s): %d reminder(s), %d AI run(s), %d proposal(s) (%d newly expired), %d chat message(s), %d conversation(s), %d task(s), %d event(s), %d project(s).',
            $days,
            $prunedReminders,
            $prunedAiRuns,
            $prunedProposals,
            $expiredProposals,
            $prunedChatMessages,
            $prunedConversations,
            $purgedTasks,
            $purgedEvents,
            $purgedProjects,
        ));

        return self::SUCCESS;
    }
}
