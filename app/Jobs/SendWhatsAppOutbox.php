<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\WhatsApp\WhatsAppOutboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class SendWhatsAppOutbox implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public function __construct(public readonly string $outboxId)
    {
        $this->tries = max(1, (int) config('whatsapp.delivery.tries', 5));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $backoff = config('whatsapp.delivery.backoff', [10, 30, 60, 120, 300]);

        return is_array($backoff) ? array_values(array_map('intval', $backoff)) : [10, 30, 60, 120, 300];
    }

    public function handle(WhatsAppOutboundService $service): void
    {
        $service->deliver($this->outboxId, now()->toDateTimeImmutable());
    }

    public function failed(Throwable $exception): void
    {
        app(WhatsAppOutboundService::class)->deadLetter($this->outboxId, $exception);
    }
}
