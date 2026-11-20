<?php

namespace App\Jobs;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Services\Notifications\OutboundChannelManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [15, 60, 180];

    public function __construct(
        public int $reminderId,
    ) {}

    public function handle(OutboundChannelManager $channels): void
    {
        $reminder = Reminder::query()->with('user')->find($this->reminderId);

        if ($reminder === null || $reminder->status !== ReminderStatus::Processing) {
            return;
        }

        $user = $reminder->user;
        $channel = $channels->resolve($reminder->channel);

        if ($channel === null) {
            $reminder->update([
                'status' => ReminderStatus::Cancelled,
                'failure_reason' => sprintf('Outbound notification channel "%s" is not registered.', $reminder->channel),
            ]);

            return;
        }

        if (! $channel->canDeliverTo($user)) {
            $reminder->update([
                'status' => ReminderStatus::Cancelled,
                'failure_reason' => 'WhatsApp reminders are disabled or number is unverified.',
            ]);

            return;
        }

        $result = $channel->send($user, $reminder);

        if ($result->success) {
            $reminder->update([
                'status' => ReminderStatus::Sent,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);

            return;
        }

        $reason = $result->errorMessage ?? 'Failed to send WhatsApp reminder.';

        if ($this->attempts() >= $this->tries) {
            $reminder->update([
                'status' => ReminderStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => $reason,
            ]);

            return;
        }

        throw new RuntimeException($reason);
    }
}
