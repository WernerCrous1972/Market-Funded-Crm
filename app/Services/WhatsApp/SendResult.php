<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

final class SendResult
{
    public function __construct(
        public readonly bool    $success,
        public readonly ?string $waMessageId = null,
        public readonly ?array  $payload     = null,
        public readonly ?string $errorCode   = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function ok(string $waMessageId, array $payload): self
    {
        return new self(success: true, waMessageId: $waMessageId, payload: $payload);
    }

    public static function fail(string $errorCode, string $errorMessage, array $payload = []): self
    {
        return new self(
            success: false,
            payload: $payload,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }
}
