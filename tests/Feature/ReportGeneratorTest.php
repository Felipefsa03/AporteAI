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
        self::assertSame([], $report->payload['warnings']);
        self::assertStringContainsString('AporteAI REALIZADO', $report->png);
        self::assertStringContainsString('2026-11-17T15:30:00+00:00', $report->png);
        self::assertSame(1, Report::query()->count());
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
        self::assertNotEmpty($report->payload['warnings']);
        self::assertStringContainsString('Dados incompletos', $report->payload['warnings'][0]);
        self::assertStringContainsString('indisponivel', $report->png);
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
