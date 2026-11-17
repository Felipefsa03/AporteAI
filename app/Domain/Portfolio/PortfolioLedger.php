<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\Portfolio;
use App\Models\PortfolioEvent;
use App\Models\PortfolioRecommendation;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A6/RB-007/RB-016: posições são projeção do livro de eventos; recomendações não movem carteira. */
final class PortfolioLedger
{
    private const EXECUTION_TYPES = ['ORDER_CONFIRMED', 'PARTIAL_FILL', 'REVERSAL'];

    public function getOrCreate(Investor $investor): Portfolio
    {
        return Portfolio::query()->firstOrCreate(
            ['investor_id' => $investor->id, 'name' => 'Carteira principal'],
            ['id' => (string) Str::uuid(), 'status' => 'active'],
        );
    }

    /**
     * @param array<string, mixed> $allocation
     * @return array{recommendation: PortfolioRecommendation, positions: array<string, int>}
     */
    public function recordRecommendation(Portfolio $portfolio, array $allocation, DateTimeImmutable $now): array
    {
        $recommendation = PortfolioRecommendation::query()->create([
            'id' => (string) Str::uuid(),
            'portfolio_id' => $portfolio->id,
            'investor_id' => $portfolio->investor_id,
            'allocation' => $allocation,
            'status' => 'proposed',
            'created_at' => $now,
        ]);

        $this->audit($portfolio, 'portfolio.recommendation_recorded', $now, [
            'recommendation_id' => $recommendation->id,
        ]);

        return ['recommendation' => $recommendation, 'positions' => $this->positions($portfolio)];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{applied: bool, positions: array<string, int>}
     */
    public function recordExecution(
        Portfolio $portfolio,
        string $externalId,
        string $eventType,
        ?Instrument $instrument,
        int $amountCents,
        DateTimeImmutable $now,
        array $metadata = [],
    ): array {
        if (! in_array($eventType, self::EXECUTION_TYPES, true)) {
            throw new PortfolioException('Tipo de evento de carteira desconhecido.');
        }

        if ($externalId === '' || $amountCents <= 0) {
            throw new PortfolioException('Evento de carteira exige external_id e valor positivo.');
        }

        if (PortfolioEvent::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('external_id', $externalId)
            ->exists()) {
            return ['applied' => false, 'positions' => $this->positions($portfolio)];
        }

        PortfolioEvent::query()->create([
            'id' => (string) Str::uuid(),
            'portfolio_id' => $portfolio->id,
            'investor_id' => $portfolio->investor_id,
            'event_type' => $eventType,
            'external_id' => $externalId,
            'instrument_id' => $instrument?->id,
            'amount_cents' => $amountCents,
            'affects_positions' => true,
            'metadata' => $metadata,
            'occurred_at' => $now,
        ]);

        $this->audit($portfolio, 'portfolio.'.$eventType, $now, [
            'external_id' => $externalId,
            'amount_cents' => $amountCents,
        ]);

        return ['applied' => true, 'positions' => $this->positions($portfolio)];
    }

    /** @return array<string, int> */
    public function positions(Portfolio $portfolio): array
    {
        $positions = [];

        PortfolioEvent::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('affects_positions', true)
            ->orderBy('occurred_at')
            ->each(function (PortfolioEvent $event) use (&$positions): void {
                $key = $event->instrument_id ?? 'CASH';
                $sign = $event->event_type === 'REVERSAL' ? -1 : 1;
                $positions[$key] = ($positions[$key] ?? 0) + ($sign * $event->amount_cents);
            });

        return $positions;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Portfolio $portfolio, string $action, DateTimeImmutable $now, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'portfolio-ledger',
            'action' => $action,
            'subject_type' => 'portfolio',
            'subject_id' => $portfolio->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => $now,
        ]);
    }
}
