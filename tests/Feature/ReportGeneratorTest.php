<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Portfolio\PortfolioLedger;
use App\Domain\Report\ReportGenerator;
use App\Models\AdminUser;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\Report;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\PngInspector;

final class ReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function testA7RealizedReportUsesCorrectPortfolioTimestampAndLabel(): void
    {
        $now = new DateTimeImmutable('2026-11-17T15:30:00+00:00');
        $investor = $this->investor();
        $ledger = app(PortfolioLedger::class);
        $portfolio = $ledger->getOrCreate($investor);
        $instrument = $this->instrument();
        $ledger->recordExecution($portfolio, 'buy-report', 'ORDER_CONFIRMED', $instrument, 80000, $now);

        $report = app(ReportGenerator::class)->generate(
            $investor,
            $portfolio,
            'REALIZADO',
            ['returns_cents' => 1250, 'market_value_cents' => 81250],
            $now,
        );

        self::assertSame("\x89PNG\r\n\x1a\n", substr($report->png, 0, 8));
        self::assertSame('REALIZADO', $report->label);
        self::assertSame('2026-11-17T15:30:00+00:00', $report->payload['timestamp']);
        self::assertSame([$instrument->id => 80000], $report->payload['positions_cents']);
        self::assertSame(80000, $report->payload['contributions_cents']);
        self::assertSame(81250, $report->payload['market_value_cents']);
        self::assertTrue($report->payload['complete']);
        self::assertSame('COMPLETA', $report->payload['coverage']);
        self::assertContains('Sem histórico suficiente para evolução.', $report->payload['warnings']);
        self::assertStringContainsString('AporteAI REALIZADO', $report->png);
        self::assertStringContainsString('2026-11-17T15:30:00+00:00', $report->png);
        self::assertSame(1, Report::query()->count());
        $persisted = $report->fresh();
        self::assertSame($report->png, $persisted->png);
        self::assertSame($report->png, $persisted->png); // Repeated reads rewind the LOB.

        $image = PngInspector::decode($report->png);
        self::assertSame([640, 640], $image->dimensions());
        self::assertTrue($image->hasText('REALIZADO', 18, 10, 3, true));
        self::assertTrue($image->hasText('DATA 2026-11-17T15:30:00+00:00', 18, 72, 2));
        self::assertTrue($image->hasText('FONTE LED/DATA BASE 2026-11-17', 18, 92, 2));
        self::assertTrue($image->hasText('PATRIMONIO R$ 812,50', 18, 112, 2));
        self::assertTrue($image->hasText('APORTE LIQUIDO R$ 800,00', 18, 132, 2));
        self::assertTrue($image->hasText('RENDIMENTOS R$ 12,50', 18, 152, 2));
        self::assertTrue($image->hasText('COBERTURA COMPLETA', 18, 172, 2));
        self::assertTrue($image->hasText('LEGENDA', 18, 206, 2));
        self::assertTrue($image->hasText('SEM HISTORICO SUFICIENTE', 18, 474, 2));
        self::assertGreaterThan(20, $image->inkCount(460, 230, 590, 360));
    }

    public function testA7ProposalAndRealizedAreVisuallyDistinct(): void
    {
        $now = new DateTimeImmutable('2026-11-17T15:30:00+00:00');
        $investor = $this->investor();
        $ledger = app(PortfolioLedger::class);
        $portfolio = $ledger->getOrCreate($investor);
        $instrument = $this->instrument();
        $generator = app(ReportGenerator::class);

        $proposal = $generator->generate(
            $investor,
            $portfolio,
            'proposta',
            [
                'proposal_positions' => [$instrument->id => 50000],
                'proposal_contributions_cents' => 50000,
                'source' => 'RULE-ENGINE/V1',
            ],
            $now,
        );
        $realized = $generator->generate($investor, $portfolio, 'REALIZADO', [], $now);

        self::assertSame('PROPOSTA', $proposal->payload['label']);
        self::assertSame('PROPOSTA', $proposal->payload['coverage']);
        self::assertSame(50000, $proposal->payload['contributions_cents']);
        self::assertNotSame($proposal->png, $realized->png);
        self::assertTrue(PngInspector::decode($proposal->png)->hasText('PROPOSTA', 18, 10, 3, true));
        self::assertTrue(PngInspector::decode($proposal->png)->hasText('FONTE RULE-ENGINE/V1', 18, 92, 2));
        self::assertTrue(PngInspector::decode($realized->png)->hasText('REALIZADO', 18, 10, 3, true));
    }

    public function testA7EvolutionUsesOnlyVerifiedHistoryAndWarnsWhenAbsent(): void
    {
        $now = new DateTimeImmutable('2026-11-17T15:30:00+00:00');
        $investor = $this->investor();
        $portfolio = app(PortfolioLedger::class)->getOrCreate($investor);
        $instrument = $this->instrument();
        app(PortfolioLedger::class)->recordExecution($portfolio, 'buy-evolution', 'ORDER_CONFIRMED', $instrument, 80000, $now);

        $this->priorReport($investor, 11000, $now->modify('-2 days'));
        $this->priorReport($investor, 12000, $now->modify('-1 day'));

        $report = app(ReportGenerator::class)->generate(
            $investor,
            $portfolio,
            'REALIZADO',
            ['returns_cents' => 100, 'market_value_cents' => 13000],
            $now,
        );

        self::assertSame([11000, 12000, 13000], $report->payload['evolution_cents']);
        self::assertSame([], $report->payload['warnings']);
        self::assertTrue($report->payload['complete']);

        $image = PngInspector::decode($report->png);
        self::assertTrue($image->hasText('EVOLUCAO', 18, 450, 2));
        self::assertGreaterThan(50, $image->inkCount(18, 470, 600, 540));
    }

    public function testA7IncompleteDataGetsWarningInsteadOfFalsePrecision(): void
    {
        $now = new DateTimeImmutable('2026-11-17T15:30:00+00:00');
        $investor = $this->investor();
        $portfolio = app(PortfolioLedger::class)->getOrCreate($investor);

        $report = app(ReportGenerator::class)->generate($investor, $portfolio, 'realizado', [], $now);

        self::assertSame('REALIZADO', $report->label);
        self::assertFalse($report->payload['complete']);
        self::assertNull($report->payload['market_value_cents']);
        self::assertSame('SEM POSICOES', $report->payload['coverage']);
        self::assertNotEmpty($report->payload['warnings']);
        self::assertStringContainsString('Dados incompletos', $report->payload['warnings'][0]);
        self::assertStringContainsString('indisponivel', $report->png);

        $image = PngInspector::decode($report->png);
        self::assertTrue($image->hasText('PATRIMONIO INDISPONIVEL', 18, 112, 2));
        self::assertTrue($image->hasText('APORTE LIQUIDO R$ 0,00', 18, 132, 2));
        self::assertTrue($image->hasText('RENDIMENTOS INDISPONIVEL', 18, 152, 2));
        self::assertTrue($image->hasText('SEM POSICOES INDISPONIVEL', 18, 228, 2));
        self::assertFalse($image->hasText('PATRIMONIO R$ 0,00', 18, 112, 2));
    }

    private function priorReport(Investor $investor, int $marketValue, DateTimeImmutable $createdAt): void
    {
        Report::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'label' => 'REALIZADO',
            'payload' => [
                'complete' => true,
                'market_value_cents' => $marketValue,
                'warnings' => [],
            ],
            'png' => "\x89PNG\r\n\x1a\n",
            'created_at' => $createdAt,
        ]);
    }

    private function instrument(): Instrument
    {
        return Instrument::query()->create([
            'id' => (string) Str::uuid(),
            'symbol' => 'TESOURO-'.Str::random(4),
            'instrument_type' => 'renda_fixa',
            'status' => 'active',
        ]);
    }

    private function investor(): Investor
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);

        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Rafaela Nunes',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'rafaela-'.Str::random(6).'@example.test',
        ]);
    }
}
