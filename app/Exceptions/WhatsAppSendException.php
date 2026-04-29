<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class WhatsAppSendException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $metaErrorCode = null,
        public readonly ?array  $metaResponse   = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
