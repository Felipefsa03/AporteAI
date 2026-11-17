<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use App\Domain\Billing\AccessDecision;
use App\Domain\Billing\BillingGate;
use App\Models\Investor;
use DateTimeImmutable;

/**
 * FL-05: ordem obrigatória — autenticar, deduplicar, classificar, responder
 * bloqueio/comandos e somente depois consultar o LLM.
 */
final class ConversationOrchestrator
{
    public function __construct(
        private readonly BillingGate $gate,
        private readonly ?LlmGateway $llm = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function handle(Investor $investor, string $message, DateTimeImmutable $now): array
    {
        $decision = $this->gate->decide($investor, $message, $now);
        $calls = 0;
        $reply = $decision->reply;

        if ($decision->allowLlm) {
            if ($this->llm !== null) {
                $reply = $this->llm->complete($message);
                $calls = 1;
            } else {
                $reply = 'Atendimento disponível. Nenhum serviço pago foi executado neste ambiente.';
            }
        }

        return $decision->toArray() + ['reply' => $reply, 'llm_calls' => $calls];
    }

    public function decide(Investor $investor, string $message, DateTimeImmutable $now): AccessDecision
    {
        return $this->gate->decide($investor, $message, $now);
    }
}
