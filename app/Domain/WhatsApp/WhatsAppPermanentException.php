<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

final class WhatsAppPermanentException extends WhatsAppException
{
    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
