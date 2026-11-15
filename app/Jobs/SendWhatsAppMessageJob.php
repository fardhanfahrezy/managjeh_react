<?php

namespace App\Jobs;

use App\Contracts\WhatsAppGatewayInterface;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
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

    public function handle(WhatsAppGatewayInterface $gateway): void
    {
        $reminder = Reminder::query()->with('user')->find($this->reminderId);

        if ($reminder === null || $reminder->status !== ReminderStatus::Processing) {
            return;
        }

        $user = $reminder->user;

        if (! $user->canReceiveWhatsAppReminders() || $user->whatsapp_number === null) {
            $reminder->update([
                'status' => ReminderStatus::Cancelled,
                'failure_reason' => 'WhatsApp reminders are disabled or number is unverified.',
            ]);

            return;
        }

        $result = $gateway->sendMessage(
            phoneNumber: $user->whatsapp_number,
            message: $reminder->message,
            idempotencyKey: $reminder->idempotency_key,
        );

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
