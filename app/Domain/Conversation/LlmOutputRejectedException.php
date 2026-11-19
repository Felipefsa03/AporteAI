<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use RuntimeException;

/** A saída inventou fatos, expôs PII/URL ou violou o contrato de explicação. */
final class LlmOutputRejectedException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'llm_output_rejected')
    {
        parent::__construct('Saída do provedor de IA rejeitada pela validação.');
    }
}
