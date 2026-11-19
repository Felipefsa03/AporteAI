<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

/** Contrato opcional para adapters que informam consumo da última chamada. */
interface UsageAwareLlmGateway
{
    /** @return array{total_tokens: int, prompt_tokens: int, completion_tokens: int} */
    public function lastUsage(): array;
}
