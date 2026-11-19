<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use RuntimeException;

/** Limite de chamadas, tokens ou custo foi atingido antes da chamada ao provedor. */
final class LlmLimitException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Limite do provedor de IA atingido: '.$reason);
    }
}
