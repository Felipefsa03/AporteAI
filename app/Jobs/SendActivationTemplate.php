<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\WhatsApp\ActivationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class SendActivationTemplate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $activationRequestId)
    {
    }

    public function handle(ActivationService $service): void
    {
        $service->deliver(
            \App\Models\ActivationRequest::query()->findOrFail($this->activationRequestId),
            now()->toDateTimeImmutable(),
        );
    }
}
