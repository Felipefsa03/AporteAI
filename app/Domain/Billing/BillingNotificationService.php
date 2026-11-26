<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\WhatsApp\WhatsAppGateway;
use App\Models\Investor;
use App\Models\Subscription;
use DateTimeImmutable;

/** Envia notificacoes de cobranca (lembrete e confirmacao) via WhatsApp. */
final class BillingNotificationService
{
    public function __construct(private readonly WhatsAppGateway $whatsapp)
    {
    }

    public function reminder(Subscription $subscription, DateTimeImmutable $now): void
    {
        $investor = Investor::query()->find($subscription->investor_id);
        if ($investor === null) {
            return;
        }

        $botPhoneNumberId = (string) config('whatsapp.bot.phone_number_id');
        $due = $subscription->due_at?->format('d/m/Y') ?? 'em breve';
        $message = sprintf(
            "Olá %s! 👋\n\nLembrete de cobrança AporteAI.\n\nSua assinatura (%s) vence em %s.\n\nPara manter o acesso, regularize o pagamento. Em caso de dúvida, responda 'ajuda'.",
            $investor->full_name,
            $subscription->plan_code,
            $due,
        );

        $this->whatsapp->sendText($investor->phone_e164, $message, $botPhoneNumberId);
    }

    public function confirmation(Subscription $subscription, DateTimeImmutable $now): void
    {
        $investor = Investor::query()->find($subscription->investor_id);
        if ($investor === null) {
            return;
        }

        $botPhoneNumberId = (string) config('whatsapp.bot.phone_number_id');
        $message = sprintf(
            "Olá %s! 👋\n\nPagamento confirmado. ✅\n\nSua assinatura AporteAI (%s) está ativa. Obrigado!",
            $investor->full_name,
            $subscription->plan_code,
        );

        $this->whatsapp->sendText($investor->phone_e164, $message, $botPhoneNumberId);
    }
}