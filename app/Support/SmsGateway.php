<?php

declare(strict_types=1);

namespace App\Support;

interface SmsGateway
{
    /** Envia o texto ao destinatário já validado. Nenhuma credencial é guardada no repositório. */
    public function send(string $toE164, string $message): void;
}
