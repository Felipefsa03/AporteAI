<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\WhatsApp\WhatsAppOutboundService;
use Illuminate\Console\Command;

final class DispatchWhatsAppOutboxCommand extends Command
{
    protected $signature = 'whatsapp:dispatch-outbox';

    protected $description = 'Despacha mensagens pendentes da outbox do bot WhatsApp';

    public function handle(WhatsAppOutboundService $service): int
    {
        $count = $service->dispatchPending(now()->toDateTimeImmutable());
        $this->info('Mensagens despachadas: '.$count);

        return self::SUCCESS;
    }
}
