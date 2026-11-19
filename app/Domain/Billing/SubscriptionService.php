<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\AuditEvent;
use App\Models\PaymentRecord;
use App\Models\Subscription;
use App\Models\SubscriptionAccessEvent;
use App\Models\WebhookEvent;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class SubscriptionService
{
    public function __construct(
        private readonly BillingCatalog $catalog,
        private readonly AccessPolicy $access,
    ) {
    }

    public function activate(Subscription $subscription, DateTimeImmutable $now): Subscription
    {
        $this->catalog->assertConsistent();

        $fromStatus = $subscription->status;
        $fromAccess = $subscription->access_status;

        $subscription->forceFill([
            'status' => 'active',
            'access_status' => 'active',
            'started_at' => $subscription->started_at ?? $now,
        ])->save();

        $this->accessEvent($subscription, $fromStatus, $fromAccess, 'contract_confirmed', $now);
        $this->audit('billing.activated', $subscription, $now, ['plan' => $subscription->plan_code]);

        return $subscription;
    }

    /** A16: atraso de parcela nunca encerra o contrato anual. */
    public function markOverdue(Subscription $subscription, DateTimeImmutable $now): string
    {
        if ($subscription->due_at === null) {
            return 'unchanged';
        }

        if (in_array($subscription->status, ['canceled', 'expired', 'past_due'], true)) {
            return 'unchanged';
        }

        $deadline = $subscription->due_at->modify('+'.(int) $subscription->grace_days.' days');
        if ($now < $deadline) {
            return 'unchanged';
        }

        $settled = PaymentRecord::query()
            ->where('subscription_id', $subscription->id)
            ->where('due_at', $subscription->due_at)
            ->where('status', 'paid')
            ->whereNotNull('reconciled_at')
            ->exists();

        if ($settled) {
            return 'unchanged';
        }

        $fromStatus = $subscription->status;
        $fromAccess = $subscription->access_status;

        $subscription->forceFill([
            'status' => 'past_due',
            'access_status' => 'suspended',
        ])->save();

        $this->accessEvent($subscription, $fromStatus, $fromAccess, 'due_without_reconciliation', $now);
        $this->audit('billing.overdue', $subscription, $now, ['access' => 'suspended']);

        return 'suspended';
    }

    /** A15: webhook duplicado nao reativa duas vezes; processing nunca e quitação. */
    public function reconcile(
        Subscription $subscription,
        PaymentRecord $payment,
        string $externalId,
        string $webhookStatus,
        DateTimeImmutable $now,
    ): bool {
        if (WebhookEvent::query()
            ->where('provider', 'gateway')
            ->where('external_id', $externalId)
            ->exists()) {
            return false;
        }

        WebhookEvent::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => 'gateway',
            'external_id' => $externalId,
            'payload_hash' => hash('sha256', $externalId.'|'.$webhookStatus),
            'processed_at' => $now,
        ]);

        if ($webhookStatus !== 'paid') {
            return false;
        }

        if ($payment->status === 'paid' && $payment->reconciled_at !== null) {
            return false;
        }

        $payment->forceFill([
            'status' => 'paid',
            'paid_at' => $now,
            'reconciled_at' => $now,
        ])->save();

        if (! $this->access->allowsPaidServices($subscription) && $subscription->status !== 'canceled') {
            $fromStatus = $subscription->status;
            $fromAccess = $subscription->access_status;
            $nextDue = $subscription->due_at === null
                ? $now->modify('+1 month')
                : $subscription->due_at->modify('+1 month');

            $subscription->forceFill([
                'status' => 'active',
                'access_status' => 'active',
                'due_at' => $nextDue,
            ])->save();

            $this->accessEvent($subscription, $fromStatus, $fromAccess, 'payment_reconciled', $now);
        }

        $this->audit('billing.reconciled', $subscription, $now, ['event' => $externalId]);

        return true;
    }

    /** RB-031/A14: "Já paguei" nunca desbloqueia; apenas registra verificação auditada. */
    public function requestVerification(
        Subscription $subscription,
        PaymentRecord $payment,
        DateTimeImmutable $now,
    ): bool {
        if ($payment->status === 'paid' && $payment->reconciled_at !== null) {
            return false;
        }

        if ($payment->status === 'processing') {
            return false;
        }

        $payment->forceFill(['status' => 'processing'])->save();
        $this->audit('billing.verification_requested', $subscription, $now, [
            'payment_id' => $payment->id,
        ]);

        return true;
    }

    /**
     * CHAT/A14: pedido de cancelamento pelo bot é ação real e auditada, mas nunca
     * altera access_status: a decisão comercial permanece com atendimento humano.
     */
    public function requestCancellation(
        Subscription $subscription,
        string $sourceEventId,
        DateTimeImmutable $now,
    ): bool {
        if ($sourceEventId === '') {
            throw new BillingException('Pedido de cancelamento exige evento de origem.');
        }

        if (SubscriptionAccessEvent::query()
            ->where('subscription_id', $subscription->id)
            ->where('reason', 'bot_cancellation_requested')
            ->where('source_event_id', $sourceEventId)
            ->exists()) {
            return false;
        }

        $this->accessEvent(
            $subscription,
            $subscription->status,
            $subscription->access_status,
            'bot_cancellation_requested',
            $now,
            $sourceEventId,
        );
        $this->audit('billing.cancellation_requested', $subscription, $now, [
            'source_event_id' => $sourceEventId,
            'access_status_unchanged' => $subscription->access_status,
        ]);

        return true;
    }

    /** RB-031: baixa manual exige evidência e nunca aceita apenas o comprovante do cliente. */
    public function recordManualSettlement(
        Subscription $subscription,
        PaymentRecord $payment,
        string $evidenceUri,
        DateTimeImmutable $now,
    ): bool {
        if (trim($evidenceUri) === '') {
            throw new BillingException('Baixa manual exige evidência auditável.');
        }

        if ($payment->status === 'paid' && $payment->reconciled_at !== null) {
            return false;
        }

        $payment->forceFill([
            'status' => 'paid',
            'paid_at' => $now,
            'reconciled_at' => $now,
        ])->save();

        if (! $this->access->allowsPaidServices($subscription) && $subscription->status !== 'canceled') {
            $fromStatus = $subscription->status;
            $fromAccess = $subscription->access_status;
            $nextDue = $subscription->due_at === null
                ? $now->modify('+1 month')
                : $subscription->due_at->modify('+1 month');

            $subscription->forceFill([
                'status' => 'active',
                'access_status' => 'active',
                'due_at' => $nextDue,
            ])->save();

            $this->accessEvent($subscription, $fromStatus, $fromAccess, 'manual_settlement: '.$evidenceUri, $now);
        }

        $this->audit('billing.manual_settlement', $subscription, $now, ['evidence_uri' => $evidenceUri]);

        return true;
    }

    public function suspend(Subscription $subscription, string $reason, DateTimeImmutable $now): void
    {
        if (trim($reason) === '') {
            throw new BillingException('Suspensao exige justificativa.');
        }

        $fromStatus = $subscription->status;
        $fromAccess = $subscription->access_status;

        $subscription->forceFill(['access_status' => 'suspended'])->save();
        $this->accessEvent($subscription, $fromStatus, $fromAccess, 'admin_suspension: '.$reason, $now);
        $this->audit('billing.access_suspended', $subscription, $now, []);
    }

    public function reactivate(Subscription $subscription, string $reason, DateTimeImmutable $now): void
    {
        if (trim($reason) === '') {
            throw new BillingException('Reativacao exige justificativa.');
        }

        $settled = $subscription->due_at !== null && PaymentRecord::query()
            ->where('subscription_id', $subscription->id)
            ->where('due_at', $subscription->due_at)
            ->where('status', 'paid')
            ->whereNotNull('reconciled_at')
            ->exists();

        if (! $settled) {
            throw new BillingException('Pagamento nao conciliado; reativacao exige evidencia do gateway.');
        }

        $fromStatus = $subscription->status;
        $fromAccess = $subscription->access_status;

        $subscription->forceFill(['status' => 'active', 'access_status' => 'active'])->save();
        $this->accessEvent($subscription, $fromStatus, $fromAccess, 'admin_reactivation: '.$reason, $now);
        $this->audit('billing.access_reactivated', $subscription, $now, []);
    }

    private function accessEvent(
        Subscription $subscription,
        string $fromStatus,
        string $fromAccess,
        string $reason,
        DateTimeImmutable $now,
        ?string $sourceEventId = null,
    ): void {
        SubscriptionAccessEvent::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'investor_id' => $subscription->investor_id,
            'previous_status' => $fromStatus.'/'.$fromAccess,
            'new_status' => $subscription->status.'/'.$subscription->access_status,
            'reason' => $reason,
            'source_event_id' => $sourceEventId,
            'occurred_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $action, Subscription $subscription, DateTimeImmutable $now, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'billing-service',
            'action' => $action,
            'subject_type' => 'subscription',
            'subject_id' => $subscription->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => $now,
        ]);
    }
}
