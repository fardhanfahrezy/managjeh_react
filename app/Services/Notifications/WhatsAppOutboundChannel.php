<?php

namespace App\Services\Notifications;

use App\Contracts\OutboundChannelInterface;
use App\Contracts\WhatsAppGatewayInterface;
use App\Models\Reminder;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppSendResult;

class WhatsAppOutboundChannel implements OutboundChannelInterface
{
    public function __construct(
        private WhatsAppGatewayInterface $gateway,
    ) {}

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function canDeliverTo(User $user): bool
    {
        return $user->canReceiveWhatsAppReminders();
    }

    public function send(User $user, Reminder $reminder): WhatsAppSendResult
    {
        if (! $this->canDeliverTo($user) || $user->whatsapp_number === null) {
            return new WhatsAppSendResult(
                success: false,
                errorMessage: 'WhatsApp reminders are disabled or number is unverified.',
            );
        }

        return $this->gateway->sendMessage(
            phoneNumber: $user->whatsapp_number,
            message: $reminder->message,
            idempotencyKey: $reminder->idempotency_key,
        );
    }
}
