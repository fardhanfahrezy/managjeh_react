<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGatewayInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MetaCloudWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function __construct(
        private string $phoneNumberId,
        private string $accessToken,
        private string $apiVersion = 'v21.0',
        private int $timeoutSeconds = 10,
    ) {}

    public function sendMessage(string $phoneNumber, string $message, string $idempotencyKey): WhatsAppSendResult
    {
        return $this->dispatch($phoneNumber, $message);
    }

    public function sendVerificationOtp(string $phoneNumber, string $otpCode): WhatsAppSendResult
    {
        $message = "Kode verifikasi WhatsApp Managjeh Anda: {$otpCode}. Berlaku selama 5 menit.";

        return $this->dispatch($phoneNumber, $message);
    }

    private function dispatch(string $phoneNumber, string $message): WhatsAppSendResult
    {
        if ($this->phoneNumberId === '' || $this->accessToken === '') {
            return new WhatsAppSendResult(
                success: false,
                errorMessage: 'Meta WhatsApp Cloud API phone_number_id or access_token is not configured.',
            );
        }

        $recipient = preg_replace('/\D+/', '', trim($phoneNumber)) ?? '';

        if ($recipient === '') {
            return new WhatsAppSendResult(
                success: false,
                errorMessage: 'Invalid WhatsApp recipient number.',
            );
        }

        $endpoint = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            trim($this->apiVersion, '/'),
            trim($this->phoneNumberId, '/'),
        );

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->accessToken)
                ->acceptJson()
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ]);

            if (! $response->successful()) {
                $errorMessage = $response->json('error.message');

                return new WhatsAppSendResult(
                    success: false,
                    errorMessage: is_string($errorMessage) && $errorMessage !== ''
                        ? $errorMessage
                        : sprintf('Meta WhatsApp Cloud API returned HTTP %d.', $response->status()),
                );
            }

            $providerMessageId = $response->json('messages.0.id');

            return new WhatsAppSendResult(
                success: true,
                providerMessageId: is_string($providerMessageId) && $providerMessageId !== '' ? $providerMessageId : null,
            );
        } catch (ConnectionException) {
            return new WhatsAppSendResult(
                success: false,
                errorMessage: 'Unable to reach Meta WhatsApp Cloud API.',
            );
        }
    }
}
