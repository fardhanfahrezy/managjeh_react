<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGatewayInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class FonnteWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function __construct(
        private string $token,
        private string $endpoint = 'https://api.fonnte.com/send',
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
        if ($this->token === '' || $this->endpoint === '') {
            return new WhatsAppSendResult(
                success: false,
                errorMessage: 'Fonnte token or endpoint is not configured.',
            );
        }

        $target = ltrim(trim($phoneNumber), '+');

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders([
                    'Authorization' => $this->token,
                ])
                ->asForm()
                ->post($this->endpoint, [
                    'target' => $target,
                    'message' => $message,
                    'countryCode' => '0',
                ]);

            if (! $response->successful()) {
                return new WhatsAppSendResult(
                    success: false,
                    errorMessage: sprintf('Fonnte returned HTTP %d.', $response->status()),
                );
            }

            if ($response->json('status') === true) {
                $ids = $response->json('id');
                $providerMessageId = null;

                if (is_array($ids) && isset($ids[0]) && (is_string($ids[0]) || is_int($ids[0]))) {
                    $providerMessageId = (string) $ids[0];
                } elseif (is_string($ids) || is_int($ids)) {
                    $providerMessageId = (string) $ids;
                }

                return new WhatsAppSendResult(
                    success: true,
                    providerMessageId: $providerMessageId,
                );
            }

            $reason = $response->json('reason');

            return new WhatsAppSendResult(
                success: false,
                errorMessage: is_string($reason) && $reason !== ''
                    ? $reason
                    : 'Fonnte rejected the message request.',
            );
        } catch (ConnectionException) {
            return new WhatsAppSendResult(
                success: false,
                errorMessage: 'Unable to reach Fonnte API.',
            );
        }
    }
}
