<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

final class WhatsAppTransientException extends WhatsAppException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
