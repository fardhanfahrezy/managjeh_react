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
use App\Models\SyncOperation;
use App\Models\SyncTombstone;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PruneRetentionDataCommand extends Command
{
    protected $signature = 'app:prune-retention-data {--days=30 : Number of days to retain soft-deleted records and historical logs}';

    protected $description = 'Permanently purge soft-deleted records and prune old reminder/AI logs, sync ledgers, and chat history past retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $tombstoneExpiresAt = now()->addDays(180);

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

        $prunedSyncOperations = SyncOperation::query()
            ->where('processed_at', '<', $cutoff)
            ->delete();

        $prunedTombstones = SyncTombstone::query()
            ->where('expires_at', '<', now())
            ->delete();

        $purgedTasks = $this->recordTombstonesAndForceDelete(
            'task',
            Task::onlyTrashed()->where('deleted_at', '<', $cutoff),
            $tombstoneExpiresAt,
        );

        $purgedEvents = $this->recordTombstonesAndForceDelete(
            'calendar_event',
            CalendarEvent::onlyTrashed()->where('deleted_at', '<', $cutoff),
            $tombstoneExpiresAt,
        );

        $purgedProjects = $this->recordTombstonesAndForceDelete(
            'project',
            Project::onlyTrashed()->where('deleted_at', '<', $cutoff),
            $tombstoneExpiresAt,
        );

        $this->info(sprintf(
            'Pruned retention data older than %d day(s): %d reminder(s), %d AI run(s), %d proposal(s) (%d newly expired), %d chat message(s), %d conversation(s), %d sync op(s), %d expired tombstone(s), %d task(s), %d event(s), %d project(s).',
            $days,
            $prunedReminders,
            $prunedAiRuns,
            $prunedProposals,
            $expiredProposals,
            $prunedChatMessages,
            $prunedConversations,
            $prunedSyncOperations,
            $prunedTombstones,
            $purgedTasks,
            $purgedEvents,
            $purgedProjects,
        ));

        return self::SUCCESS;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function recordTombstonesAndForceDelete(
        string $entityType,
        Builder $query,
        CarbonInterface $expiresAt,
    ): int {
        $purgedCount = 0;
        $now = now();

        $rows = (clone $query)
            ->select(['id', 'user_id', 'uuid', 'deleted_at'])
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $tombstones = [];
        foreach ($rows as $row) {
            $uuid = $row->getAttribute('uuid');
            $userId = $row->getAttribute('user_id');
            if (! is_string($uuid) || $uuid === '' || $userId === null) {
                continue;
            }

            $deletedAt = $row->getAttribute('deleted_at') ?? $now;
            $tombstones[] = [
                'user_id' => (int) $userId,
                'entity_type' => $entityType,
                'entity_uuid' => $uuid,
                'deleted_at' => $deletedAt,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($tombstones, 200) as $chunk) {
            SyncTombstone::query()->upsert(
                $chunk,
                ['user_id', 'entity_type', 'entity_uuid'],
                ['deleted_at', 'expires_at', 'updated_at'],
            );
        }

        $purgedCount = (int) $query->forceDelete();

        return $purgedCount;
    }
}
