<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Report\MonthlyReportService;
use App\Models\Investor;
use Illuminate\Console\Command;

final class SendMonthlyReportCommand extends Command
{
    protected $signature = 'report:monthly';

    protected $description = 'Envia resumo mensal de carteira e performance a cada investidor via WhatsApp';

    public function handle(MonthlyReportService $service): int
    {
        $count = 0;
        foreach (Investor::query()->get() as $investor) {
            $service->send($investor, now()->toDateTimeImmutable());
            ++$count;
        }

        $this->info('Relatórios enviados: '.$count);

        return self::SUCCESS;
    }
}