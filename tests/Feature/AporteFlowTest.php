<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Aporte\AporteException;
use App\Domain\Aporte\AporteFlowService;
use App\Domain\MarketData\SourceCatalog;
use App\Models\AdminUser;
use App\Models\AporteSession;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\MarketQuote;
use App\Models\PortfolioEvent;
use App\Models\SuitabilityAssessment;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AporteFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('compliance.advisory_authorized', true);
    }

    public function testA2PhaseOneIsMandatoryAndNeedsFourAnswers(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $service = app(AporteFlowService::class);
        $assessment = $this->assessment();
        $session = $service->start($assessment->investor, 80000, $now);

        self::assertSame('PHASE1', $session->phase);

        $this->expectException(AporteException::class);
        $service->propose($session, [], ['assessment' => $assessment], $now);
    }

    public function testA2AnsweringFourQuestionsMovesToPhaseTwoAndIsIdempotent(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $service = app(AporteFlowService::class);
        $session = $service->start($this->assessment()->investor, 80000, $now);

        $staleSession = clone $session;

        foreach (['objective' => 'renda', 'horizon' => 'longo', 'tolerance' => 'moderada', 'financial_changes' => 'nao'] as $question => $value) {
            $result = $service->answer($session, $question, $value, 'key-'.$question, $now);
            $session = $result['session'];
        }

        self::assertTrue($result['complete']);
        self::assertSame([], $result['pending']);
        self::assertSame('PHASE2_READY', $session->phase);

        $repeated = $service->answer($staleSession, 'objective', 'alterado', 'key-objective', $now);
        self::assertTrue($repeated['complete']);
        self::assertSame('PHASE2_READY', $repeated['session']->phase);
        self::assertSame('renda', $repeated['session']->answers['objective']);
    }

    public function testA4A6ProposalIsExactDoesNotMovePositionsAndConfirmationAppliesOnce(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $service = app(AporteFlowService::class);
        $assessment = $this->assessment();
        $session = $service->start($assessment->investor, 80000, $now);

        foreach (['objective' => 'renda', 'horizon' => 'longo', 'tolerance' => 'moderada', 'financial_changes' => 'nao'] as $question => $value) {
            $session = $service->answer($session, $question, $value, 'key-'.$question, $now)['session'];
        }

        $proposal = $service->propose(
            $session,
            [$this->candidate('TESOURO-IPCA', $now), $this->candidate('CDB-LIQUIDEZ', $now)],
            ['assessment' => $assessment],
            $now,
        );

        self::assertSame(10000, $proposal['allocation']['total_bps']);
        self::assertSame(80000, $proposal['allocation']['total_cents']);
        self::assertSame([], $proposal['positions']);

        $instrument = Instrument::query()->where('symbol', 'TESOURO-IPCA')->firstOrFail();
        $first = $service->confirm($session, 'order-aporte-1', $instrument, 80000, $now);
        self::assertTrue($first['applied']);
        self::assertSame([$instrument->id => 80000], $first['positions']);

        $duplicate = $service->confirm($session, 'order-aporte-1', $instrument, 80000, $now);
        self::assertFalse($duplicate['applied']);
        self::assertSame([$instrument->id => 80000], $duplicate['positions']);
        self::assertSame(1, PortfolioEvent::query()->where('external_id', 'order-aporte-1')->count());
        self::assertSame('COMPLETED', AporteSession::query()->findOrFail($session->id)->phase);
    }

    /** @return array{instrument: Instrument, quote: MarketQuote} */
    private function candidate(string $symbol, DateTimeImmutable $asOf): array
    {
        $key = (string) config('allocation.universe.'.$symbol.'.source', 'bcb_selic');
        $source = app(SourceCatalog::class)->get($key);
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

    private function assessment(): SuitabilityAssessment
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
            'full_name' => 'Marina Costa',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'marina-'.Str::random(6).'@example.test',
        ]);

        return SuitabilityAssessment::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'version' => 1,
            'answers' => [
                'objective' => 'growth',
                'horizon' => 'long',
                'knowledge' => 'intermediate',
                'liquidity' => 'monthly',
                'restrictions' => [],
            ],
            'classification' => 'moderate',
            'status' => 'valid',
            'assessed_at' => new DateTimeImmutable('2026-11-17T10:00:00+00:00'),
            'expires_at' => new DateTimeImmutable('2027-05-17T10:00:00+00:00'),
        ]);
    }
}
