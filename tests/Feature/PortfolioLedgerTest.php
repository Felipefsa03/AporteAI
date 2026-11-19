<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Portfolio\PortfolioException;
use App\Domain\Portfolio\PortfolioLedger;
use App\Models\AdminUser;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\PortfolioEvent;
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

    public function testOpeningPositionKeepsQuantityUnitPriceCostAndValueDate(): void
    {
        $ledger = app(PortfolioLedger::class);
        $portfolio = $ledger->getOrCreate($this->investor());
        $instrument = $this->instrument('TESOURO-SELIC-2029');
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $valueDate = new DateTimeImmutable('2026-11-16T00:00:00-03:00');

        $result = $ledger->recordOpeningPosition(
            $portfolio,
            'opening-1',
            $instrument,
            '3.500000',
            20000,
            70000,
            $valueDate,
            $now,
        );

        self::assertTrue($result['applied']);
        self::assertSame([$instrument->id => 70000], $result['positions']);
        self::assertSame('3.500000', $result['details'][$instrument->id]['quantity']);
        self::assertSame(70000, $result['details'][$instrument->id]['cost_cents']);
        self::assertSame('2026-11-16T00:00:00-03:00', $result['details'][$instrument->id]['value_date']);

        $event = PortfolioEvent::query()->where('external_id', 'opening-1')->firstOrFail();
        self::assertSame('OPENING_POSITION', $event->event_type);
        self::assertSame('3.500000', $event->quantity);
        self::assertSame(20000, $event->unit_price_cents);
        self::assertSame(70000, $event->cost_cents);
    }

    public function testPartialFillThenTotalOfSameOrderDoesNotDoubleCount(): void
    {
        $ledger = app(PortfolioLedger::class);
        $portfolio = $ledger->getOrCreate($this->investor());
        $instrument = $this->instrument('CDB-BANCO-X');
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $orderId = (string) Str::uuid();

        $partial = $ledger->recordExecution(
            $portfolio, 'fill-order-9', 'PARTIAL_FILL', $instrument, 30000, $now,
            ['order_id' => $orderId],
        );
        self::assertSame([$instrument->id => 30000], $partial['positions']);

        $total = $ledger->recordExecution(
            $portfolio, 'total-order-9', 'ORDER_CONFIRMED', $instrument, 80000, $now,
            ['order_id' => $orderId],
        );
        self::assertTrue($total['applied']);
        self::assertSame([$instrument->id => 80000], $total['positions']);

        $confirmed = PortfolioEvent::query()->where('external_id', 'total-order-9')->firstOrFail();
        self::assertSame(50000, $confirmed->amount_cents);
        self::assertSame(80000, $confirmed->metadata['requested_amount_cents']);
    }

    public function testReversalIsLinkedLimitedAndCannotBeRepeated(): void
    {
        $ledger = app(PortfolioLedger::class);
        $portfolio = $ledger->getOrCreate($this->investor());
        $instrument = $this->instrument('CDB-LIQUIDEZ-2');
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        $ledger->recordExecution($portfolio, 'original-2', 'ORDER_CONFIRMED', $instrument, 80000, $now);

        try {
            $ledger->recordExecution($portfolio, 'bad-reversal', 'REVERSAL', $instrument, 90000, $now, [
                'reverts' => 'original-2',
            ]);
            self::fail('Estorno acima do valor original nao pode ser aplicado.');
        } catch (PortfolioException) {
            self::assertFalse(PortfolioEvent::query()->where('external_id', 'bad-reversal')->exists());
        }

        $reversal = $ledger->recordExecution($portfolio, 'reversal-2', 'REVERSAL', $instrument, 50000, $now, [
            'reverts' => 'original-2',
        ]);
        self::assertSame([$instrument->id => 30000], $reversal['positions']);

        $reversalEvent = PortfolioEvent::query()->where('external_id', 'reversal-2')->firstOrFail();
        $original = PortfolioEvent::query()->where('external_id', 'original-2')->firstOrFail();
        self::assertSame($original->id, $reversalEvent->reverses_event_id);

        try {
            $ledger->recordExecution($portfolio, 'reversal-3', 'REVERSAL', $instrument, 40000, $now, [
                'reverts' => 'original-2',
            ]);
            self::fail('Soma de estornos nao pode exceder o evento original.');
        } catch (PortfolioException) {
            self::assertFalse(PortfolioEvent::query()->where('external_id', 'reversal-3')->exists());
        }
    }

    public function testSameExternalIdIsIsolatedPerPortfolio(): void
    {
        $ledger = app(PortfolioLedger::class);
        $first = $ledger->getOrCreate($this->investor());
        $second = $ledger->getOrCreate($this->investor());
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        self::assertTrue($ledger->recordExecution($first, 'shared-key', 'ORDER_CONFIRMED', null, 10000, $now)['applied']);
        self::assertTrue($ledger->recordExecution($second, 'shared-key', 'ORDER_CONFIRMED', null, 20000, $now)['applied']);
        self::assertSame(2, PortfolioEvent::query()->where('external_id', 'shared-key')->count());
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
        // RB-026: apenas uma conta ativa pode existir; reutiliza o admin do teste.
        $admin = AdminUser::query()->where('active', true)->first();
        if ($admin === null) {
            $admin = AdminUser::query()->create([
                'id' => (string) Str::uuid(),
                'email' => 'owner-'.Str::random(6).'@example.test',
                'password_hash' => Hash::make('x'),
                'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
                'mfa_enabled' => false,
                'active' => true,
            ]);
        }

        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Carla Dias',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'carla-'.Str::random(6).'@example.test',
        ]);
    }
}
