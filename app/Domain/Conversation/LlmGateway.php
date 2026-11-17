<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

interface LlmGateway
{
    /** Chamado somente após BillingGate::decide() permitir serviços pagos. */
    public function complete(string $prompt): string;
}
