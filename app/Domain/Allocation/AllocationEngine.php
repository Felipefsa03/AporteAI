<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\MarketData\FreshnessPolicy;
use App\Domain\MarketData\MarketDataException;
use App\Domain\MarketData\SourceCatalog;
use App\Domain\Onboarding\AdvisoryAuthorization;
use App\Domain\Onboarding\ComplianceException;
use App\Domain\Onboarding\SuitabilityPolicy;
use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\MarketQuote;
use App\Models\SuitabilityAssessment;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A3/A4/A5/RB-005–RB-010: motor determinístico, sem IA e sem ponto flutuante. */
final class AllocationEngine
{
    private const RULE_VERSION = 'allocation-2026.11-v1';

    public function __construct(
        private readonly AdvisoryAuthorization $authorization,
        private readonly FreshnessPolicy $freshness,
        private readonly SourceCatalog $sources,
    ) {
    }

    /**
     * @param list<array{instrument: Instrument, quote: MarketQuote}> $candidates
     * @param array<string, mixed> $context
     */
    public function allocate(
        SuitabilityAssessment $assessment,
        int $budgetCents,
        array $candidates,
        array $context,
        DateTimeImmutable $now,
    ): Allocation {
        if ($budgetCents <= 0) {
            return $this->cashOnly('invalid_budget', $budgetCents);
        }

        if (! $this->authorization->canRecommend($assessment, $now)) {
            try {
                $this->authorization->assertCanRecommend($assessment, $now);
            } catch (ComplianceException) {
                return $this->cashOnly('compliance_blocked', $budgetCents);
            }
        }

        $reserve = max(0, (int) ($context['reserve_cents'] ?? 0));
        $debts = max(0, (int) ($context['debts_cents'] ?? 0));
        $availableCents = $budgetCents - $reserve - $debts;
        $profile = (string) $assessment->classification;

        if (! in_array($profile, SuitabilityPolicy::PROFILES, true)) {
            return $this->cashOnly('unknown_profile', $budgetCents);
        }

        if ($availableCents <= 0 || ($context['financial_changes'] ?? false) === true) {
            return $this->cashOnly('liquidity_or_reserve_first', $budgetCents);
        }

        $investedBps = (int) config('allocation.invested_bps_by_profile.'.$profile, 0);
        $maxRiskBps = (int) config('allocation.max_risk_bps_by_profile.'.$profile, 0);
        $eligible = [];

        foreach ($candidates as $candidate) {
            $instrument = $candidate['instrument'];
            $quote = $candidate['quote'];
            $rule = config('allocation.universe.'.$instrument->symbol);

            if ($instrument->status !== 'active'
                || ! is_array($rule)
                || ! isset($rule['risk_bps'], $rule['source'])
                || ! is_numeric($rule['risk_bps'])
                || ! is_string($rule['source'])
                || (int) $rule['risk_bps'] > $maxRiskBps) {
                continue;
            }

            // Fonte ausente, sem licenca ou invalida reduz o universo; nunca expoe excecao ao caller.
            try {
                $source = $this->sources->get($rule['source']);
            } catch (MarketDataException) {
                $this->audit($assessment, 'allocation.source_invalid', ['symbol' => $instrument->symbol]);
                continue;
            }

            if ($quote->source_id !== $source['id'] || $quote->quality_status !== 'approved') {
                continue;
            }

            $maxAge = (int) ($source['max_age_seconds'] ?? 0);
            if ($this->freshness->status($quote->as_of, $maxAge, $now) !== 'valid') {
                $this->audit($assessment, 'allocation.source_stale', ['symbol' => $instrument->symbol]);
                continue;
            }

            $eligible[] = [
                'symbol' => $instrument->symbol,
                'risk_bps' => (int) $rule['risk_bps'],
                'source' => $source['provider'].'/'.$source['dataset'],
                'as_of' => $quote->as_of->format(DATE_ATOM),
                'max_age_seconds' => $maxAge,
            ];
        }

        usort($eligible, static fn (array $a, array $b): int => strcmp($a['symbol'], $b['symbol']));

        if ($eligible === []) {
            $this->audit($assessment, 'allocation.no_reliable_universe', []);
            return $this->cashOnly('no_reliable_universe', $budgetCents);
        }

        $perInstrument = intdiv($investedBps, count($eligible));
        $targets = [];
        $allocatedBps = 0;
        $investedCents = 0;
        $previousCumulative = 0;

        foreach ($eligible as $index => $candidate) {
            $bps = $perInstrument + ($index === count($eligible) - 1 ? $investedBps % count($eligible) : 0);
            $cumulative = $previousCumulative + $bps;
            // A reserva e as dividas permanecem em CASH: somente o disponivel pode ser investido.
            $amount = intdiv($availableCents * $cumulative, 10000)
                - intdiv($availableCents * $previousCumulative, 10000);
            $previousCumulative = $cumulative;
            $allocatedBps += $bps;
            $investedCents += $amount;

            $targets[] = [
                'symbol' => $candidate['symbol'],
                'bps' => $bps,
                'amount_cents' => $amount,
                'risk_bps' => $candidate['risk_bps'],
                'source' => $candidate['source'],
                'as_of' => $candidate['as_of'],
                'max_age_seconds' => $candidate['max_age_seconds'],
                'rule_version' => self::RULE_VERSION,
            ];
        }

        $cashBps = 10000 - $allocatedBps;
        $targets[] = [
            'symbol' => 'CASH',
            'bps' => $cashBps,
            'amount_cents' => $budgetCents - $investedCents,
            'risk_bps' => 0,
            'source' => 'internal/rule',
            'as_of' => $now->format(DATE_ATOM),
            'rule_version' => self::RULE_VERSION,
        ];

        $this->audit($assessment, 'allocation.generated', [
            'profile' => $profile,
            'budget_cents' => $budgetCents,
            'reserve_cents' => $reserve,
            'debts_cents' => $debts,
            'available_cents' => $availableCents,
            'invested_cents' => $investedCents,
        ]);

        return Allocation::make('invest', 'deterministic_allocation', self::RULE_VERSION, $targets);
    }

    private function cashOnly(string $reason, int $budgetCents): Allocation
    {
        return Allocation::make('blocked', $reason, self::RULE_VERSION, [[
            'symbol' => 'CASH',
            'bps' => 10000,
            'amount_cents' => $budgetCents,
            'risk_bps' => 0,
            'source' => 'internal/rule',
            'as_of' => null,
            'rule_version' => self::RULE_VERSION,
        ]]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(SuitabilityAssessment $assessment, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'allocation-engine',
            'action' => $action,
            'subject_type' => 'suitability_assessment',
            'subject_id' => $assessment->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => now()->toDateTimeImmutable(),
        ]);
    }
}
