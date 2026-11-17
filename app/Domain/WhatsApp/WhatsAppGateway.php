<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

interface WhatsAppGateway
{
    /** Envia template aprovado exclusivamente pelo numero do bot. */
    public function sendTemplate(string $phoneE164, string $templateName, string $botPhoneNumberId): string;
}
