<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Portfolio\PortfolioLedger;
use App\Models\AdminUser;
use App\Models\Instrument;
use App\Models\Investor;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortfolioLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function testA6RecommendationDoesNotMovePositionsAndDuplicateExecutionIsIgnored(): void
    {
        $ledger = app(PortfolioLedger::class);
        $investor = $this->investor();
        $portfolio = $ledger->getOrCreate($investor);
        $instrument = $this->instrument('TESOURO-IPCA');
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        $recommendation = $ledger->recordRecommendation(
            $portfolio,
            ['TESOURO-IPCA' => 10000, 'CASH' => 0],
            $now,
        );
        self::assertSame([], $recommendation['positions']);

        $first = $ledger->recordExecution($portfolio, 'buy-1', 'ORDER_CONFIRMED', $instrument, 80000, $now);
        self::assertTrue($first['applied']);
        self::assertSame([$instrument->id => 80000], $first['positions']);

        $duplicate = $ledger->recordExecution($portfolio, 'buy-1', 'ORDER_CONFIRMED', $instrument, 80000, $now);
        self::assertFalse($duplicate['applied']);
        self::assertSame([$instrument->id => 80000], $duplicate['positions']);
    }

    public function testPartialFillAndReversalRecalculateNetPosition(): void
    {
        $ledger = app(PortfolioLedger::class);
        $portfolio = $ledger->getOrCreate($this->investor());
        $instrument = $this->instrument('CDB-LIQUIDEZ');
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        $ledger->recordExecution($portfolio, 'order-1', 'ORDER_CONFIRMED', $instrument, 80000, $now);
        $partial = $ledger->recordExecution($portfolio, 'fill-1', 'PARTIAL_FILL', $instrument, 30000, $now);
        self::assertSame([$instrument->id => 110000], $partial['positions']);

        $reversed = $ledger->recordExecution($portfolio, 'reversal-1', 'REVERSAL', $instrument, 30000, $now, [
            'reverts' => 'fill-1',
        ]);
        self::assertSame([$instrument->id => 80000], $reversed['positions']);
        self::assertSame(3, \App\Models\PortfolioEvent::query()->where('portfolio_id', $portfolio->id)->count());
    }

    private function instrument(string $symbol): Instrument
    {
        return Instrument::query()->create([
            'id' => (string) Str::uuid(),
            'symbol' => $symbol,
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
            'full_name' => 'Carla Dias',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'carla-'.Str::random(6).'@example.test',
        ]);
    }
}
