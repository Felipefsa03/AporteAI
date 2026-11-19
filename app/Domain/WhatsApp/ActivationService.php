<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use App\Domain\Billing\AccessPolicy;
use App\Jobs\SendActivationTemplate;
use App\Models\ActivationRequest;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\Subscription;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class ActivationService
{
    public function __construct(
        private readonly NumberRegistry $numbers,
        private readonly OptInPolicy $optIn,
        private readonly AccessPolicy $access,
        private readonly WhatsAppOutboundService $outbound,
    ) {
    }

    /** RB-022/023/024/A12: so o proprietario ativa, apos opt-in e cobranca elegiveis. */
    public function requestActivation(Investor $investor, AdminUser $admin, DateTimeImmutable $now): ActivationRequest
    {
        $this->numbers->assertDistinct();

        if (! $this->optIn->isEligible($investor, $now)) {
            throw new WhatsAppException('Opt-in ausente, revogado ou sem evidencia.');
        }

        $subscription = Subscription::query()
            ->where('investor_id', $investor->id)
            ->where('status', 'active')
            ->latest('started_at')
            ->first();

        if ($subscription === null || ! $this->access->allowsPaidServices($subscription)) {
            throw new WhatsAppException('Assinatura nao elegivel para ativacao.');
        }

        $key = hash('sha256', $investor->id.'|'.$subscription->id.'|'.$now->format('Y-m-d'));
        $existing = ActivationRequest::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $request = ActivationRequest::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'requested_by_admin_id' => $admin->id,
            'idempotency_key' => $key,
            'status' => 'queued',
            'template_name' => (string) config('whatsapp.templates.activation'),
            'bot_phone_number_id' => $this->numbers->botPhoneNumberId(),
            'requested_at' => $now,
        ]);

        SendActivationTemplate::dispatch($request->id)->onQueue((string) config('whatsapp.queue'));
        $this->audit($investor, $request, 'whatsapp.activation_queued', $now);

        return $request;
    }

    public function deliver(ActivationRequest $request, DateTimeImmutable $now): void
    {
        $investor = Investor::query()->findOrFail($request->investor_id);

        if (! $this->optIn->isEligible($investor, $now)) {
            $request->forceFill(['status' => 'blocked'])->save();
            return;
        }

        $outbox = $this->outbound->queueTemplate(
            $investor,
            $request->template_name,
            $request->bot_phone_number_id,
            $now,
            $request->id,
        );

        $this->outbound->deliver($outbox->id, $now);
        $outbox->refresh();

        if ($outbox->status !== 'sent') {
            $request->forceFill(['status' => 'blocked'])->save();

            return;
        }

        $request->refresh();
        $this->audit($investor, $request, 'whatsapp.template_sent', $now);
    }

    /** @return array<string, mixed> */
    public function status(Investor $investor): array
    {
        $request = ActivationRequest::query()
            ->where('investor_id', $investor->id)
            ->latest('requested_at')
            ->first();

        if ($request === null) {
            return ['status' => 'none'];
        }

        return [
            'status' => $request->status,
            'template_name' => $request->template_name,
            'requested_at' => $request->requested_at?->format(DATE_ATOM),
            'delivered_at' => $request->delivered_at?->format(DATE_ATOM),
            'first_reply_at' => $request->first_reply_at?->format(DATE_ATOM),
            'bot_phone_number_id' => $request->bot_phone_number_id,
        ];
    }

    private function audit(Investor $investor, ActivationRequest $request, string $action, DateTimeImmutable $now): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'admin',
            'actor_id' => $request->requested_by_admin_id,
            'action' => $action,
            'subject_type' => 'investor',
            'subject_id' => $investor->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => ['activation_request_id' => $request->id],
            'occurred_at' => $now,
        ]);
    }
}
