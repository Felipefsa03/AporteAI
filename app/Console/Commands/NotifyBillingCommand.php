<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\BillingNotificationService;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class NotifyBillingCommand extends Command
{
    protected $signature = 'billing:notify {--days=3 : Dias de antecedencia para lembrete}';

    protected $description = 'Envia lembretes e confirmacoes de cobranca via WhatsApp';

    public function handle(BillingNotificationService $service): int
    {
        $days = (int) $this->option('days');
        $threshold = CarbonImmutable::now()->addDays($days);

        $due = Subscription::query()
            ->where('access_status', 'active')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $threshold)
            ->get();

        foreach ($due as $subscription) {
            $service->reminder($subscription, now()->toDateTimeImmutable());
        }

        $this->info('Lembretes enviados: '.$due->count());

        return self::SUCCESS;
    }
}