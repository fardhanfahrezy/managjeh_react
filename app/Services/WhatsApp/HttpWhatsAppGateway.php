<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGatewayInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function __construct(
        private string $endpoint,
        private string $apiToken,
        private int $timeoutSeconds = 10,
    ) {}

    public function sendMessage(string $phoneNumber, string $message, string $idempotencyKey): WhatsAppSendResult
    {
        return $this->dispatchPayload([
            'to' => $phoneNumber,
            'message' => $message,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function sendVerificationOtp(string $phoneNumber, string $otpCode): WhatsAppSendResult
    {
        return $this->dispatchPayload([
            'to' => $phoneNumber,
            'message' => "Kode verifikasi WhatsApp Managjeh Anda: {$otpCode}. Berlaku selama 5 menit.",
            'type' => 'otp',
        ]);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function dispatchPayload(array $payload): WhatsAppSendResult
    {
        if ($this->endpoint === '' || $this->apiToken === '') {
            return new WhatsAppSendResult(
                success: false,
                errorMessage: 'WhatsApp gateway credentials are not configured.',
            );
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->apiToken)
                ->acceptJson()
                ->post($this->endpoint, $payload);

            if ($response->successful()) {
                $messageId = $response->json('id') ?? $response->json('message_id');

                return new WhatsAppSendResult(
                    success: true,
                    providerMessageId: is_string($messageId) ? $messageId : null,
                );
            }

            return new WhatsAppSendResult(
                success: false,
                errorMessage: sprintf('Gateway returned HTTP %d.', $response->status()),
            );
        } catch (ConnectionException) {
            return new WhatsAppSendResult(
                success: false,
                errorMessage: 'Unable to reach WhatsApp gateway.',
            );
        }
    }
}
