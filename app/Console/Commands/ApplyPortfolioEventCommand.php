<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Portfolio\PortfolioLedger;
use App\Models\Instrument;
use App\Models\Portfolio;
use Illuminate\Console\Command;

/** Replay idempotente de eventos de carteira, util para filas e retries. */
final class ApplyPortfolioEventCommand extends Command
{
    protected $signature = 'portfolio:apply-event
        {portfolio : UUID da carteira}
        {external-id : Chave de idempotencia}
        {event-type : ORDER_CONFIRMED, PARTIAL_FILL ou REVERSAL}
        {amount-cents : Valor positivo em centavos}
        {--instrument= : UUID do instrumento (ausente = CASH)}
        {--order= : UUID da ordem para deducao parcial/total}
        {--reverts= : external_id do evento estornado}';

    public function handle(PortfolioLedger $ledger): int
    {
        $portfolio = Portfolio::query()->findOrFail($this->argument('portfolio'));
        $instrument = $this->option('instrument')
            ? Instrument::query()->findOrFail($this->option('instrument'))
            : null;
        $metadata = [];
        if ($this->option('order')) {
            $metadata['order_id'] = $this->option('order');
        }
        if ($this->option('reverts')) {
            $metadata['reverts'] = $this->option('reverts');
        }

        $result = $ledger->recordExecution(
            $portfolio,
            (string) $this->argument('external-id'),
            (string) $this->argument('event-type'),
            $instrument,
            (int) $this->argument('amount-cents'),
            now()->toDateTimeImmutable(),
            $metadata,
        );

        $this->line($result['applied'] ? 'applied=true' : 'applied=false');

        return self::SUCCESS;
    }
}
