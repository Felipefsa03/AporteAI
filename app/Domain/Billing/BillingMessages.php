<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Subscription;
use DateTimeImmutable;

/** FL-05: textos determinísticos. Nenhum valor, vencimento ou link vem da IA. */
final class BillingMessages
{
    public function overdue(string $firstName, Subscription $subscription, ?string $paymentUrl): string
    {
        $due = $subscription->due_at?->format('d/m/Y') ?? 'data a confirmar';
        $product = (string) config('billing.product.name', 'AporteAI');

        $line = $paymentUrl !== null
            ? "Para regularizar, acesse seu link seguro de pagamento: **{$paymentUrl}**."
            : 'Responda **Regularizar pagamento** e enviaremos as orientações para quitar sua assinatura.';

        return "Olá, {$firstName}! 👋\n"
            ."Identificamos que sua assinatura da **{$product}** venceu em **{$due}** e o pagamento ainda está pendente.\n"
            ."Por isso, os serviços de análises, aportes e relatórios estão temporariamente pausados.\n"
            .$line."\n"
            ."Se você já pagou, responda **Já paguei** para verificarmos. Se precisar de ajuda, envie **Falar com atendimento**.\n"
            .'Seu histórico permanece preservado conforme nossas políticas e obrigações legais.';
    }

    public function verification(string $firstName): string
    {
        return "Recebemos sua solicitação, {$firstName}. Seu pagamento ainda está em verificação. "
            .'Para evitar informações incorretas, vamos conferir o status antes de reativar os serviços. '
            .'Se precisar, digite **Falar com atendimento**.';
    }

    public function grace(string $firstName, Subscription $subscription): string
    {
        $due = $subscription->due_at?->format('d/m/Y') ?? 'data a confirmar';

        return "Olá, {$firstName}! Sua assinatura tem vencimento em {$due} e está dentro do prazo contratual. "
            .'O atendimento segue disponível; responda **Meu plano** para conferir os detalhes.';
    }

    public function command(string $command, Subscription $subscription, ?string $commercialNumber): string
    {
        $label = (string) config('billing.plans.'.$subscription->plan_code.'.label', $subscription->plan_code);
        $due = $subscription->due_at?->format('d/m/Y') ?? 'a confirmar';

        return match ($command) {
            'plan' => "Plano: {$label}. Vencimento: {$due}. Status: ".$subscription->access_status.'.',
            'reconcile' => 'Vamos verificar o pagamento com o gateway. Nenhuma quitação é presumida.',
            'handoff' => $commercialNumber === null
                ? 'Encaminhando ao atendimento humano.'
                : "Atendimento humano pelo WhatsApp comercial: {$commercialNumber}.",
            'privacy' => 'Seus direitos de titular serão atendidos; históricos sujeitos a retenção legal permanecem preservados.',
            'cancellation' => 'Solicitação de cancelamento registrada para atendimento humano; nenhum serviço pago será executado.',
            default => 'Comando recebido.',
        };
    }

    public function noSubscription(): string
    {
        return 'Não localizei uma assinatura vinculada a este número. '
            .'Envie **Falar com atendimento** para conferir seu cadastro.';
    }
}
