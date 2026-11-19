<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\WhatsApp\WhatsAppOutboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class DispatchWhatsAppOutbox implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(WhatsAppOutboundService $service): void
    {
        $service->dispatchPending(now()->toDateTimeImmutable());
    }
}
