<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Messaging\WelcomeMessageTemplate;
use App\Events\InvestorCreated;
use App\Support\WhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class SendInvestorWelcomeMessage implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly WhatsAppGateway $whatsapp)
    {}

    public function handle(InvestorCreated $event): void
    {
        $investor = $event->investor;

        $message = WelcomeMessageTemplate::forInvestor($investor->full_name);

        $this->whatsapp->send(
            toNumber: $investor->phone_e164,
            message: $message,
        );
    }
}
