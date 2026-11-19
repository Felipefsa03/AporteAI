<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use RuntimeException;

/** Falha de transporte/provedor; nunca carrega prompt, resposta ou dados do investidor. */
final class LlmProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $reason = 'llm_provider_failed',
    ) {
        parent::__construct($message);
    }
}
