<?php

namespace App\Contracts;

use App\Models\Reminder;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppSendResult;

interface OutboundChannelInterface
{
    /**
     * Unique channel identifier (e.g. "whatsapp", "telegram").
     */
    public function channel(): string;

    /**
     * Verify whether the user has opted in and is eligible to receive notifications on this channel.
     */
    public function canDeliverTo(User $user): bool;

    /**
     * Deliver a reminder message to the user on this channel.
     */
    public function send(User $user, Reminder $reminder): WhatsAppSendResult;
}
