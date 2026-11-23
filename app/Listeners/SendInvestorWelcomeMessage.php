<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Messaging\WelcomeMessageTemplate;
use App\Domain\WhatsApp\WhatsAppGateway;
use App\Events\InvestorCreated;

final class SendInvestorWelcomeMessage
{
    public function __construct(private readonly WhatsAppGateway $whatsapp)
    {}

    public function handle(InvestorCreated $event): void
    {
        $investor = $event->investor;
        $message = WelcomeMessageTemplate::forInvestor($investor->full_name);
        $botNumber = config('whatsapp.bot_phone_number_id');

        $this->whatsapp->sendText(
            phoneE164: $investor->phone_e164,
            text: $message,
            botPhoneNumberId: $botNumber,
        );
    }
}
