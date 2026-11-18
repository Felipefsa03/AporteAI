<?php

declare(strict_types=1);

namespace App\Domain\Aporte;

use App\Domain\Allocation\AllocationEngine;
use App\Domain\Explanation\ProposalExplainer;
use App\Domain\Portfolio\PortfolioLedger;
use App\Models\AporteSession;
use App\Models\Instrument;
use App\Models\Investor;
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
        if (! $assessment instanceof \App\Models\SuitabilityAssessment) {
            throw new AporteException('Suitability válido é obrigatório para a Fase 2.');
        }

        $allocation = $this->engine->allocate($assessment, $session->amount_cents, $candidates, $context, $now);
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
