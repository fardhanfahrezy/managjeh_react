<?php

namespace App\Services\AiChat;

use App\Enums\AiActionProposalStatus;
use App\Mcp\McpToolExecutor;
use App\Models\AiActionProposal;
use App\Models\AiChatMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AiActionProposalService
{
    public function __construct(
        private McpToolExecutor $executor,
    ) {}

    /**
     * Decide a batch of pending AI action proposals attached to an assistant message.
     * Enforces atomic claim, 24-hour expiration, ownership, and explicit execution via MCP executor.
     *
     * @param  list<int>  $approvedIds
     * @return array{
     *     proposals: list<AiActionProposal>,
     *     summary: array{executed: int, rejected: int, failed: int, expired: int},
     * }
     */
    public function decideBatch(
        User $user,
        AiChatMessage $message,
        array $approvedIds,
        bool $rejectAll = false,
    ): array {
        $approvedSet = array_values(array_unique(array_map('intval', $approvedIds)));

        /** @var array{proposals: list<AiActionProposal>, summary: array{executed: int, rejected: int, failed: int, expired: int}} $outcome */
        $outcome = DB::transaction(function () use ($user, $message, $approvedSet, $rejectAll): array {
            $now = CarbonImmutable::now('UTC');
            $summary = [
                'executed' => 0,
                'rejected' => 0,
                'failed' => 0,
                'expired' => 0,
            ];

            $proposals = AiActionProposal::query()
                ->where('ai_chat_message_id', $message->id)
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($proposals as $proposal) {
                if ($proposal->status !== AiActionProposalStatus::Pending) {
                    continue;
                }

                if ($proposal->expires_at->isPast()) {
                    $proposal->update([
                        'status' => AiActionProposalStatus::Expired,
                        'decided_at' => $now,
                        'result_summary' => 'Usulan kedaluwarsa karena melewati batas waktu 24 jam.',
                    ]);
                    $summary['expired']++;

                    continue;
                }

                if ($rejectAll || ! in_array($proposal->id, $approvedSet, true)) {
                    $proposal->update([
                        'status' => AiActionProposalStatus::Rejected,
                        'decided_at' => $now,
                        'result_summary' => 'Ditolak oleh pengguna.',
                    ]);
                    $summary['rejected']++;

                    continue;
                }

                // Atomic claim check for strict idempotency.
                $claimed = AiActionProposal::query()
                    ->whereKey($proposal->id)
                    ->where('user_id', $user->id)
                    ->where('status', AiActionProposalStatus::Pending->value)
                    ->update(['decided_at' => $now]);

                if ($claimed !== 1) {
                    continue;
                }

                $result = $this->executor->executeApprovedMutation(
                    $user,
                    $proposal->tool_name,
                    $proposal->payload,
                );

                if ($result->success) {
                    $proposal->update([
                        'status' => AiActionProposalStatus::Executed,
                        'decided_at' => $now,
                        'executed_at' => $now,
                        'target_type' => $result->targetType,
                        'target_id' => $result->targetId,
                        'result_summary' => $result->summary ?? 'Aksi berhasil dieksekusi.',
                    ]);
                    $summary['executed']++;
                } else {
                    $proposal->update([
                        'status' => AiActionProposalStatus::Failed,
                        'decided_at' => $now,
                        'result_summary' => $result->error ?? 'Eksekusi aksi gagal.',
                    ]);
                    $summary['failed']++;
                }
            }

            $freshProposals = AiActionProposal::query()
                ->where('ai_chat_message_id', $message->id)
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->all();

            return [
                'proposals' => array_values($freshProposals),
                'summary' => $summary,
            ];
        });

        return $outcome;
    }
}
