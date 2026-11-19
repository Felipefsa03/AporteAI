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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A6/RB-007/RB-016: posições são projeção do livro de eventos; recomendações não movem carteira. */
final class PortfolioLedger
{
    private const OPENING_POSITION = 'OPENING_POSITION';

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
     * Saldo inicial com quantidade, preço unitário, custo e data-base.
     *
     * @param array<string, mixed> $metadata
     * @return array{applied: bool, positions: array<string, int>, details: array<string, array<string, mixed>>}
     */
    public function recordOpeningPosition(
        Portfolio $portfolio,
        string $externalId,
        ?Instrument $instrument,
        string $quantity,
        int $unitPriceCents,
        int $costCents,
        DateTimeImmutable $valueDate,
        DateTimeImmutable $now,
        array $metadata = [],
    ): array {
        if ($externalId === '' || ! $this->isPositiveDecimal($quantity)
            || $unitPriceCents <= 0 || $costCents <= 0) {
            throw new PortfolioException('Saldo inicial exige chave, quantidade, preço e custo positivos.');
        }

        return DB::transaction(function () use (
            $portfolio, $externalId, $instrument, $quantity, $unitPriceCents,
            $costCents, $valueDate, $now, $metadata,
        ): array {
            $this->lockPortfolio($portfolio);

            if ($this->externalIdExists($portfolio, $externalId)) {
                return $this->result($portfolio, false);
            }

            $this->createEvent($portfolio, self::OPENING_POSITION, $externalId, $instrument, $costCents, $now, [
                'quantity' => $this->normalizeDecimal($quantity),
                'unit_price_cents' => $unitPriceCents,
                'cost_cents' => $costCents,
                'value_date' => $valueDate->format(DATE_ATOM),
            ] + $metadata);

            $this->audit($portfolio, 'portfolio.opening_position', $now, [
                'external_id' => $externalId,
                'cost_cents' => $costCents,
            ]);

            return $this->result($portfolio, true);
        });
    }

    /**
     * Evento de execução é idempotente por (carteira, external_id) e serializado por carteira.
     * Quando `order_id` está presente, parciais e confirmação total da mesma ordem não se somam.
     *
     * @param array<string, mixed> $metadata
     * @return array{applied: bool, positions: array<string, int>, details: array<string, array<string, mixed>>}
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

        return DB::transaction(function () use (
            $portfolio, $externalId, $eventType, $instrument, $amountCents, $now, $metadata,
        ): array {
            $this->lockPortfolio($portfolio);

            if ($this->externalIdExists($portfolio, $externalId)) {
                return $this->result($portfolio, false);
            }

            $orderId = $this->metadataString($metadata, 'order_id');
            $effectiveAmount = $this->effectiveAmount($portfolio, $eventType, $amountCents, $orderId);
            if ($effectiveAmount === null) {
                return $this->result($portfolio, false);
            }

            $reversesEventId = $eventType === 'REVERSAL'
                ? $this->assertReversal($portfolio, $instrument, $amountCents, $metadata)
                : null;

            $eventMetadata = $metadata;
            if ($effectiveAmount !== $amountCents) {
                $eventMetadata['requested_amount_cents'] = $amountCents;
            }
            if ($reversesEventId !== null) {
                $eventMetadata['reverses_event_id'] = $reversesEventId;
            }

            $this->createEvent(
                $portfolio,
                $eventType,
                $externalId,
                $instrument,
                $effectiveAmount,
                $now,
                $eventMetadata,
                $orderId,
                $reversesEventId,
            );

            $this->audit($portfolio, 'portfolio.'.$eventType, $now, [
                'external_id' => $externalId,
                'amount_cents' => $effectiveAmount,
                'order_id' => $orderId,
            ]);

            return $this->result($portfolio, true);
        });
    }

    /** @return array<string, int> */
    public function positions(Portfolio $portfolio): array
    {
        $positions = [];
        foreach ($this->orderedEvents($portfolio) as $event) {
            $key = $event->instrument_id ?? 'CASH';
            $positions[$key] = ($positions[$key] ?? 0)
                + ($this->sign($event->event_type) * $event->amount_cents);
        }

        return $positions;
    }

    /**
     * @return array<string, array{amount_cents: int, quantity: string, cost_cents: int, value_date: ?string}>
     */
    public function positionsDetailed(Portfolio $portfolio): array
    {
        $positions = [];
        foreach ($this->orderedEvents($portfolio) as $event) {
            $key = $event->instrument_id ?? 'CASH';
            $sign = $this->sign($event->event_type);
            $current = $positions[$key] ?? [
                'amount_cents' => 0,
                'quantity' => '0.000000',
                'cost_cents' => 0,
                'value_date' => null,
            ];

            $quantity = $event->quantity === null
                ? $current['quantity']
                : $this->addDecimal($current['quantity'], $event->quantity, $sign);
            $date = is_string($event->metadata['value_date'] ?? null)
                ? $event->metadata['value_date']
                : ($event->value_date?->format(DATE_ATOM) ?? $event->occurred_at->format(DATE_ATOM));
            if ($current['value_date'] === null || $date > $current['value_date']) {
                $current['value_date'] = $date;
            }

            $positions[$key] = [
                'amount_cents' => $current['amount_cents'] + ($sign * $event->amount_cents),
                'quantity' => $quantity,
                'cost_cents' => $current['cost_cents']
                    + ($sign * (int) ($event->cost_cents ?? $event->amount_cents)),
                'value_date' => $current['value_date'],
            ];
        }

        return $positions;
    }

    /** @param array<string, mixed> $metadata */
    private function assertReversal(
        Portfolio $portfolio,
        ?Instrument $instrument,
        int $amountCents,
        array $metadata,
    ): string {
        $externalId = $this->metadataString($metadata, 'reverts');
        $eventId = $this->metadataString($metadata, 'reverses_event_id');

        $original = PortfolioEvent::query()
            ->where('portfolio_id', $portfolio->id)
            ->where(function ($query) use ($eventId, $externalId): void {
                if ($eventId !== null) {
                    $query->where('id', $eventId);
                }
                if ($externalId !== null) {
                    $query->orWhere('external_id', $externalId);
                }
            })
            ->where('event_type', '!=', 'REVERSAL')
            ->first();

        if ($original === null || ($eventId === null && $externalId === null)) {
            throw new PortfolioException('Estorno precisa referenciar evento existente na mesma carteira.');
        }
        if ((string) $original->instrument_id !== (string) $instrument?->id) {
            throw new PortfolioException('Estorno precisa manter o instrumento do evento original.');
        }

        $alreadyReversed = 0;
        foreach (PortfolioEvent::query()->where('portfolio_id', $portfolio->id)->get() as $event) {
            if ($event->reverses_event_id === $original->id
                || ($event->metadata['reverts'] ?? null) === $original->external_id) {
                $alreadyReversed += $event->amount_cents;
            }
        }

        $available = $original->amount_cents - $alreadyReversed;
        $balance = $this->positions($portfolio)[$instrument?->id ?? 'CASH'] ?? 0;
        if ($amountCents > $available || $amountCents > $balance) {
            throw new PortfolioException('Estorno excede o valor original ou o saldo disponível.');
        }

        return $original->id;
    }

    private function effectiveAmount(
        Portfolio $portfolio,
        string $eventType,
        int $amountCents,
        ?string $orderId,
    ): ?int {
        if ($orderId === null || ! in_array($eventType, ['ORDER_CONFIRMED', 'PARTIAL_FILL'], true)) {
            return $amountCents;
        }

        $events = PortfolioEvent::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('order_id', $orderId)
            ->get();
        $partials = (int) $events->where('event_type', 'PARTIAL_FILL')->sum('amount_cents');
        $confirmed = (int) $events->where('event_type', 'ORDER_CONFIRMED')->sum('amount_cents');

        if ($eventType === 'ORDER_CONFIRMED') {
            if ($confirmed > 0) {
                return null;
            }

            $remaining = $amountCents - $partials;

            return $remaining > 0 ? $remaining : null;
        }

        if ($confirmed > 0) {
            $remaining = $confirmed - $partials;

            return $remaining > 0 ? min($amountCents, $remaining) : null;
        }

        return $amountCents;
    }

    private function lockPortfolio(Portfolio $portfolio): void
    {
        Portfolio::query()->whereKey($portfolio->id)->lockForUpdate()->firstOrFail();
    }

    private function externalIdExists(Portfolio $portfolio, string $externalId): bool
    {
        return PortfolioEvent::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('external_id', $externalId)
            ->exists();
    }

    /** @param array<string, mixed> $attributes */
    private function createEvent(
        Portfolio $portfolio,
        string $eventType,
        string $externalId,
        ?Instrument $instrument,
        int $amountCents,
        DateTimeImmutable $now,
        array $attributes,
        ?string $orderId = null,
        ?string $reversesEventId = null,
    ): void {
        $quantity = $attributes['quantity'] ?? null;
        $valueDate = $attributes['value_date'] ?? null;

        PortfolioEvent::query()->create([
            'id' => (string) Str::uuid(),
            'portfolio_id' => $portfolio->id,
            'investor_id' => $portfolio->investor_id,
            'event_type' => $eventType,
            'external_id' => $externalId,
            'order_id' => $orderId,
            'reverses_event_id' => $reversesEventId,
            'instrument_id' => $instrument?->id,
            'amount_cents' => $amountCents,
            'quantity' => $quantity === null ? null : $this->normalizeDecimal((string) $quantity),
            'unit_price_cents' => $attributes['unit_price_cents'] ?? null,
            'cost_cents' => $attributes['cost_cents'] ?? null,
            'value_date' => $valueDate,
            'affects_positions' => true,
            'metadata' => $attributes,
            'occurred_at' => $now,
        ]);
    }

    /** @return list<PortfolioEvent> */
    private function orderedEvents(Portfolio $portfolio): array
    {
        return PortfolioEvent::query()
            ->where('portfolio_id', $portfolio->id)
            ->where('affects_positions', true)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return array{applied: bool, positions: array<string, int>, details: array<string, array<string, mixed>>} */
    private function result(Portfolio $portfolio, bool $applied): array
    {
        return [
            'applied' => $applied,
            'positions' => $this->positions($portfolio),
            'details' => $this->positionsDetailed($portfolio),
        ];
    }

    private function sign(string $eventType): int
    {
        return $eventType === 'REVERSAL' ? -1 : 1;
    }

    /** @param array<string, mixed> $metadata */
    private function metadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isPositiveDecimal(string $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }

    private function normalizeDecimal(string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    private function addDecimal(string $current, string $delta, int $sign): string
    {
        if (function_exists('bcadd')) {
            return bcadd($current, $sign > 0 ? $delta : '-'.$delta, 6);
        }

        return $this->normalizeDecimal((string) ((float) $current + ($sign * (float) $delta)));
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
