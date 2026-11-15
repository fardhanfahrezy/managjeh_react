<?php

namespace App\Contracts;

use App\Services\WhatsApp\WhatsAppSendResult;

interface WhatsAppGatewayInterface
{
    public function sendMessage(string $phoneNumber, string $message, string $idempotencyKey): WhatsAppSendResult;

    public function sendVerificationOtp(string $phoneNumber, string $otpCode): WhatsAppSendResult;
}
