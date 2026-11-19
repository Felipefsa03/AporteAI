<?php

declare(strict_types=1);

namespace App\Domain\Aporte;

use App\Domain\Allocation\AllocationEngine;
use App\Domain\Explanation\ProposalExplainer;
use App\Domain\Portfolio\PortfolioLedger;
use App\Domain\Review\ReviewService;
use App\Models\AporteSession;
use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\ReviewCase;
use App\Models\SuitabilityAssessment;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A2/A4/A6/RB-002: Fase 1 obrigatória, proposta validada e execução confirmada separadas. */
final class AporteFlowService
{
    public const QUESTIONS = ['objective', 'horizon', 'tolerance', 'financial_changes'];

    public function __construct(
        private readonly AllocationEngine $engine,
        private readonly ProposalExplainer $explainer,
        private readonly PortfolioLedger $ledger,
        private readonly ReviewService $reviews,
    ) {
    }

    public function start(Investor $investor, int $amountCents, DateTimeImmutable $now): AporteSession
    {
        if ($amountCents <= 0) {
            throw new AporteException('Valor de aporte inválido.');
        }

        return AporteSession::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'amount_cents' => $amountCents,
            'phase' => 'PHASE1',
            'answers' => [],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{session: AporteSession, complete: bool, pending: list<string>} */
    public function answer(
        AporteSession $session,
        string $question,
        string $value,
        string $idempotencyKey,
        DateTimeImmutable $now,
    ): array {
        if (! in_array($question, self::QUESTIONS, true)) {
            throw new AporteException('Pergunta de Fase 1 desconhecida.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($session, $question, $value, $idempotencyKey, $now): array {
            $session = AporteSession::query()->lockForUpdate()->findOrFail($session->id);
            $answers = is_array($session->answers) ? $session->answers : [];
            if (($answers['_idempotency'] ?? null) === $idempotencyKey
                || in_array($idempotencyKey, $answers['_processed_keys'] ?? [], true)) {
                return $this->progress($session, $answers);
            }

            $answers[$question] = $value;
            if (isset($answers['_idempotency'])) {
                $answers['_processed_keys'][] = $answers['_idempotency'];
            }
            $answers['_processed_keys'][] = $idempotencyKey;
            $answers['_processed_keys'] = array_values(array_unique($answers['_processed_keys']));
            $answers['_idempotency'] = $idempotencyKey;
            $complete = count(array_intersect(self::QUESTIONS, array_keys($answers))) === count(self::QUESTIONS);

            $session->forceFill([
                'answers' => $answers,
                'phase' => $complete ? 'PHASE2_READY' : 'PHASE1',
                'updated_at' => $now,
            ])->save();

            return $this->progress($session, $answers);
        });
    }

    /**
     * @param list<array{instrument: Instrument, quote: \App\Models\MarketQuote}> $candidates
     * @param array<string, mixed> $context
     * @return array{allocation: array<string, mixed>, explanation: array<string, mixed>, positions: array<string, int>}
     */
    public function propose(
        AporteSession $session,
        array $candidates,
        array $context,
        DateTimeImmutable $now,
    ): array {
        if ($session->phase !== 'PHASE2_READY') {
            throw new AporteException('Fase 1 incompleta: nenhuma recomendação pode ser publicada.');
        }

        $assessment = $context['assessment'] ?? null;
        if (! $assessment instanceof SuitabilityAssessment) {
            throw new AporteException('Suitability válido é obrigatório para a Fase 2.');
        }

        if ($assessment->investor_id !== $session->investor_id) {
            throw new AporteException('Suitability de outro investidor não pode orientar esta sessão.');
        }

        $this->assertNoCriticalBlock($session->investor_id);

        $allocation = $this->engine->allocate($assessment, $session->amount_cents, $candidates, $context, $now);

        // Proposta bloqueada nao chega ao LLM, nao gera explicacao e nao entra no livro de recomendacoes.
        if ($allocation->status !== 'invest') {
            throw new AporteException('Proposta bloqueada por compliance: '.$allocation->reason.'.');
        }

        $explanation = $this->explainer->explain($session->investor, $allocation, $now);
        $portfolio = $this->ledger->getOrCreate($session->investor);
        $recommendation = $this->ledger->recordRecommendation(
            $portfolio,
            $allocation->toArray(),
            $now,
        );

        $payload = [
            'allocation' => $allocation->toArray(),
            'explanation' => $explanation,
            'recommendation_id' => $recommendation['recommendation']->id,
        ];
        $session->forceFill(['proposal' => $payload, 'updated_at' => $now])->save();

        return $payload + ['positions' => $recommendation['positions']];
    }

    /** @return array{applied: bool, positions: array<string, int>} */
    public function confirm(
        AporteSession $session,
        string $externalId,
        ?Instrument $instrument,
        int $amountCents,
        DateTimeImmutable $now,
    ): array {
        if ($session->proposal === null) {
            throw new AporteException('Nenhuma proposta validada para confirmar.');
        }

        $proposalAllocation = $session->proposal['allocation'] ?? null;
        if (! is_array($proposalAllocation) || ($proposalAllocation['status'] ?? null) !== 'invest') {
            throw new AporteException('Somente propostas validadas podem gerar ordem.');
        }

        if ($amountCents <= 0 || $amountCents > $session->amount_cents) {
            throw new AporteException('Valor da ordem fora do orçamento validado da sessão.');
        }

        $this->assertNoCriticalBlock($session->investor_id);

        $portfolio = $this->ledger->getOrCreate($session->investor);
        $result = $this->ledger->recordExecution(
            $portfolio,
            $externalId,
            'ORDER_CONFIRMED',
            $instrument,
            $amountCents,
            $now,
            ['aporte_session_id' => $session->id],
        );

        if ($result['applied']) {
            $session->forceFill(['phase' => 'COMPLETED', 'updated_at' => $now])->save();
        }

        return $result;
    }

    /** CHAT: recusa explícita encerra a sessão sem recomendação, ordem ou posição. */
    public function refuse(AporteSession $session, string $reason, DateTimeImmutable $now): AporteSession
    {
        $session->forceFill(['phase' => 'REFUSED', 'updated_at' => $now])->save();

        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'investor',
            'actor_id' => (string) $session->investor_id,
            'action' => 'aporte.refused',
            'subject_type' => 'aporte_session',
            'subject_id' => $session->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => ['reason' => $reason],
            'occurred_at' => $now,
        ]);

        return $session;
    }

    /** CHAT: revisão crítica humana; nenhuma proposta ou ordem pode avançar mentre pendente. */
    public function requestReview(AporteSession $session, string $reason, DateTimeImmutable $now): ReviewCase
    {
        $case = $this->reviews->openCritical(
            $session->investor,
            'aporte_session',
            $session->id,
            'aporte_review_requested',
            ['session_id' => $session->id, 'reason' => $reason],
            ['bot_journey'],
            ['A2', 'A8', 'RB-002'],
            $now,
        );

        $session->forceFill(['phase' => 'REVIEW', 'updated_at' => $now])->save();

        return $case;
    }

    private function assertNoCriticalBlock(string $investorId): void
    {
        if (ReviewCase::query()
            ->where('investor_id', $investorId)
            ->where('critical', true)
            ->where('status', 'pending')
            ->exists()) {
            throw new AporteException('Revisão crítica pendente: nenhuma proposta ou ordem pode avançar.');
        }
    }

    /** @param array<string, mixed> $answers */
    private function progress(AporteSession $session, array $answers): array
    {
        $pending = array_values(array_diff(self::QUESTIONS, array_keys($answers)));

        return [
            'session' => $session,
            'complete' => $pending === [],
            'pending' => $pending,
        ];
    }
}
