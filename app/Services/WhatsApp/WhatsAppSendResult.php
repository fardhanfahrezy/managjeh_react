<?php

namespace App\Services\WhatsApp;

readonly class WhatsAppSendResult
{
    public function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public ?string $errorMessage = null,
    ) {}
}
