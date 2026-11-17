<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\WhatsApp\HumanHandoff;
use App\Models\Investor;
use App\Models\PaymentRecord;
use App\Models\Subscription;
use DateTimeImmutable;

/**
 * RB-029/RB-031/RB-033/A13/A14/FL-05: toda mensagem autenticada passa por aqui
 * antes de qualquer chamada ao LLM, aporte ou relatório pago.
 */
final class BillingGate
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly AccessPolicy $access,
        private readonly PaymentGateway $gateway,
        private readonly MessageClassifier $classifier,
        private readonly BillingMessages $messages,
        private readonly HumanHandoff $handoff,
    ) {
    }

    public function decide(Investor $investor, string $message, DateTimeImmutable $now): AccessDecision
    {
        $command = $this->classifier->command($message);
        $subscription = Subscription::query()
            ->where('investor_id', $investor->id)
            ->whereNotIn('status', ['canceled', 'expired'])
            ->latest('started_at')
            ->first();

        $commercial = $this->handoff->instructions()['number_e164'] ?? null;

        if ($subscription === null) {
            return $command !== null
                ? AccessDecision::command($this->messages->command($command, new Subscription(), $commercial), $command)
                : AccessDecision::blocked($this->messages->noSubscription());
        }

        if (in_array($command, ['privacy', 'handoff', 'cancellation'], true)) {
            return AccessDecision::command($this->messages->command($command, $subscription, $commercial), (string) $command);
        }

        if ($command === 'plan') {
            return AccessDecision::command($this->messages->command('plan', $subscription, $commercial), 'plan');
        }

        $payment = PaymentRecord::query()
            ->where('subscription_id', $subscription->id)
            ->latest('due_at')
            ->first();

        if ($command === 'reconcile') {
            if ($payment !== null) {
                $this->subscriptions->requestVerification($subscription, $payment, $now);
            }

            return AccessDecision::command(
                $this->messages->verification($this->firstName($investor->full_name)),
                'reconcile',
            );
        }

        if ($payment === null) {
            return AccessDecision::verification($this->messages->verification($this->firstName($investor->full_name)));
        }

        try {
            $providerStatus = $this->gateway->status($payment);
            $link = $this->gateway->validatedPaymentUrl($payment);
        } catch (PaymentGatewayException) {
            $this->escalate($subscription, 'gateway_unavailable', $now);

            return AccessDecision::verification($this->messages->verification($this->firstName($investor->full_name)));
        }

        if ($providerStatus === 'processing' || ($providerStatus === 'paid' && $payment->reconciled_at === null)) {
            return AccessDecision::verification($this->messages->verification($this->firstName($investor->full_name)));
        }

        if ($providerStatus === 'paid' && $payment->reconciled_at !== null && ! $this->access->allowsPaidServices($subscription)) {
            $this->escalate($subscription, 'reconciled_without_access', $now);

            return AccessDecision::verification($this->messages->verification($this->firstName($investor->full_name)));
        }

        if ($subscription->due_at !== null && $now >= $subscription->due_at) {
            $this->subscriptions->markOverdue($subscription, $now);
            $subscription->refresh();
        }

        if ($this->access->allowsPaidServices($subscription)) {
            if ($subscription->due_at !== null && $now >= $subscription->due_at) {
                return AccessDecision::grace($this->messages->grace($this->firstName($investor->full_name), $subscription));
            }

            return AccessDecision::eligible();
        }

        return AccessDecision::blocked(
            $this->messages->overdue($this->firstName($investor->full_name), $subscription, $link),
        );
    }

    private function firstName(string $name): string
    {
        return explode(' ', trim($name))[0] ?: 'investidor';
    }

    private function escalate(Subscription $subscription, string $reason, DateTimeImmutable $now): void
    {
        \App\Models\AuditEvent::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'billing-gate',
            'action' => 'billing.gate_escalation',
            'subject_type' => 'subscription',
            'subject_id' => $subscription->id,
            'trace_id' => (string) \Illuminate\Support\Str::uuid(),
            'metadata' => ['reason' => $reason],
            'occurred_at' => $now,
        ]);
    }
}
