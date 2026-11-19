<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use App\Domain\Billing\AccessDecision;
use App\Domain\Billing\BillingGate;
use App\Models\Investor;
use DateTimeImmutable;

/**
 * FL-05: ordem obrigatória — autenticar, deduplicar, classificar e responder
 * bloqueio/comandos. Texto livre nunca dispara LLM: apenas a proposta validada
 * pode gerar explicação, pelo ProposalExplainer.
 */
final class ConversationOrchestrator
{
    public function __construct(
        private readonly BillingGate $gate,
        private readonly InvestorJourneyService $journey,
    ) {
    }

    /**
     * @param array<string, mixed> $event evento normalizado do webhook; quando ausente,
     *                                    mantém o comportamento legado de gate + texto livre.
     * @return array<string, mixed>
     */
    public function handle(
        Investor $investor,
        string $message,
        DateTimeImmutable $now,
        array $event = [],
    ): array {
        $decision = $this->gate->decide($investor, $message, $now);

        if ($event !== []) {
            $event['body'] = $message;

            return array_replace($decision->toArray(), $this->journey->handle($investor, $event, $decision, $now));
        }

        $calls = 0;
        $reply = $decision->reply;

        if ($decision->allowLlm) {
            $reply = (string) config(
                'llm.free_text_reply',
                'Assinatura ativa. Nenhuma recomendação é gerada por texto livre; solicite a explicação da proposta validada pelo fluxo dedicado.',
            );
        }

        return array_replace($decision->toArray(), ['reply' => $reply, 'llm_calls' => $calls]);
    }

    public function decide(Investor $investor, string $message, DateTimeImmutable $now): AccessDecision
    {
        return $this->gate->decide($investor, $message, $now);
    }
}
