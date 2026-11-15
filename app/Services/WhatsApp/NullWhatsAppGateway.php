<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGatewayInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NullWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function sendMessage(string $phoneNumber, string $message, string $idempotencyKey): WhatsAppSendResult
    {
        // D-15: log metadata only without exposing raw phone numbers or credentials.
        Log::info('NullWhatsAppGateway: simulated message dispatch.', [
            'idempotency_key' => $idempotencyKey,
            'phone_suffix' => substr($phoneNumber, -4),
            'message_length' => strlen($message),
        ]);

        return new WhatsAppSendResult(
            success: true,
            providerMessageId: 'null-'.Str::uuid()->toString(),
        );
    }

    public function sendVerificationOtp(string $phoneNumber, string $otpCode): WhatsAppSendResult
    {
        // D-15: never log raw OTP code.
        Log::info('NullWhatsAppGateway: simulated OTP dispatch.', [
            'phone_suffix' => substr($phoneNumber, -4),
        ]);

        return new WhatsAppSendResult(
            success: true,
            providerMessageId: 'null-otp-'.Str::uuid()->toString(),
        );
    }
}
