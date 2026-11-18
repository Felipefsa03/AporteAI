<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Allocation\AllocationEngine;
use App\Domain\MarketData\SourceCatalog;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\MarketQuote;
use App\Models\SuitabilityAssessment;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AllocationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('compliance.advisory_authorized', true);
    }

    public function testA4AllocationSumsExactlyTenThousandBpsAndEightHundredReais(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $assessment = $this->assessment('moderate');
        $candidates = [
            $this->candidate('TESOURO-IPCA', $now),
            $this->candidate('CDB-LIQUIDEZ', $now),
        ];

        $allocation = app(AllocationEngine::class)->allocate($assessment, 80000, $candidates, [], $now);

        self::assertSame('invest', $allocation->status);
        self::assertSame(10000, $allocation->totalBps);
        self::assertSame(80000, $allocation->totalCents);
        self::assertSame(4000, $this->bpsFor('CASH', $allocation->targets));
        self::assertNotNull($this->targetFor('TESOURO-IPCA', $allocation->targets)['as_of']);
        self::assertNotEmpty($this->targetFor('CDB-LIQUIDEZ', $allocation->targets)['source']);
        self::assertNotEmpty($this->targetFor('CDB-LIQUIDEZ', $allocation->targets)['rule_version']);
    }

    public function testA3UnauthorizedAdvisoryProducesNoSpecificInstrument(): void
    {
        config()->set('compliance.advisory_authorized', false);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $assessment = $this->assessment('moderate');
        $candidates = [$this->candidate('TESOURO-IPCA', $now)];

        $allocation = app(AllocationEngine::class)->allocate($assessment, 80000, $candidates, [], $now);

        self::assertSame('blocked', $allocation->status);
        self::assertSame('compliance_blocked', $allocation->reason);
        self::assertSame([['symbol' => 'CASH', 'bps' => 10000, 'amount_cents' => 80000, 'risk_bps' => 0, 'source' => 'internal/rule', 'as_of' => null, 'rule_version' => $allocation->ruleVersion]], $allocation->targets);
        self::assertTrue(AuditEvent::query()->where('action', 'compliance.recommendation_blocked')->exists());
    }

    public function testReserveOrDebtProducesDoNotInvestWithFullCash(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $assessment = $this->assessment('conservative');

        $allocation = app(AllocationEngine::class)->allocate(
            $assessment,
            80000,
            [$this->candidate('TESOURO-IPCA', $now)],
            ['reserve_cents' => 90000],
            $now,
        );

        self::assertSame('blocked', $allocation->status);
        self::assertSame('liquidity_or_reserve_first', $allocation->reason);
        self::assertSame(80000, $allocation->totalCents);
    }

    public function testStaleSourceCannotEnterRecommendation(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $stale = $now->modify('-10 days');
        $assessment = $this->assessment('moderate');

        $allocation = app(AllocationEngine::class)->allocate(
            $assessment,
            80000,
            [$this->candidate('TESOURO-IPCA', $stale)],
            [],
            $now,
        );

        self::assertSame('blocked', $allocation->status);
        self::assertSame('no_reliable_universe', $allocation->reason);
        self::assertTrue(AuditEvent::query()->where('action', 'allocation.source_stale')->exists());
    }

    /** @return array{instrument: Instrument, quote: MarketQuote} */
    private function candidate(string $symbol, DateTimeImmutable $asOf): array
    {
        $source = app(SourceCatalog::class)->get((string) config('allocation.universe.'.$symbol.'.source'));
        $instrument = Instrument::query()->firstOrCreate(
            ['symbol' => $symbol],
            ['id' => (string) Str::uuid(), 'instrument_type' => 'renda_fixa', 'status' => 'active'],
        );
        $quote = MarketQuote::query()->create([
            'id' => (string) Str::uuid(),
            'instrument_id' => $instrument->id,
            'source_id' => $source['id'],
            'as_of' => $asOf,
            'price' => '100.0000',
            'quality_status' => 'approved',
            'captured_at' => $asOf,
        ]);

        return ['instrument' => $instrument, 'quote' => $quote];
    }

    private function assessment(string $classification): SuitabilityAssessment
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);
        $investor = Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Diego Alves',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'diego-'.Str::random(6).'@example.test',
        ]);

        return SuitabilityAssessment::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'version' => 1,
            'answers' => ['restrictions' => []],
            'classification' => $classification,
            'status' => 'valid',
            'assessed_at' => new DateTimeImmutable('2026-11-17T10:00:00+00:00'),
            'expires_at' => new DateTimeImmutable('2027-05-17T10:00:00+00:00'),
        ]);
    }

    /** @param list<array<string, mixed>> $targets */
    private function bpsFor(string $symbol, array $targets): int
    {
        return (int) $this->targetFor($symbol, $targets)['bps'];
    }

    /** @param list<array<string, mixed>> $targets */
    private function targetFor(string $symbol, array $targets): array
    {
        foreach ($targets as $target) {
            if ($target['symbol'] === $symbol) {
                return $target;
            }
        }

        self::fail('Destino não encontrado: '.$symbol);
    }
}
