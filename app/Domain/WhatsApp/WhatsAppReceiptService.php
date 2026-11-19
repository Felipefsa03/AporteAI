<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use App\Models\ActivationRequest;
use App\Models\WhatsAppDeliveryReceipt;
use App\Models\WhatsAppOutboxMessage;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class WhatsAppReceiptService
{
    /** @param array<string, mixed> $event */
    public function apply(array $event, DateTimeImmutable $receivedAt): string
    {
        $providerMessageId = isset($event['external_id']) ? (string) $event['external_id'] : '';
        $status = isset($event['provider_status']) ? (string) $event['provider_status'] : '';
        $occurredAt = $event['occurred_at'] instanceof DateTimeImmutable ? $event['occurred_at'] : $receivedAt;

        if ($providerMessageId === '' || ! in_array($status, ['sent', 'delivered', 'read'], true)) {
            return 'ignored';
        }

        $outbox = WhatsAppOutboxMessage::query()
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if ($this->record($providerMessageId, $status, $event['payload'] ?? [], $occurredAt, $outbox?->id) === null) {
            return 'duplicate';
        }

        if ($outbox !== null) {
            $this->advance($outbox, $status, $occurredAt);
        }

        return $status;
    }

    /** @param array<string, mixed> $payload */
    public function record(
        string $providerMessageId,
        string $status,
        array $payload,
        DateTimeImmutable $occurredAt,
        ?string $outboxId = null,
    ): ?WhatsAppDeliveryReceipt {
        if ($providerMessageId === '' || $status === '') {
            return null;
        }

        $dedupeKey = 'receipt:'.$providerMessageId.':'.$status;

        $inserted = WhatsAppDeliveryReceipt::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'dedupe_key' => $dedupeKey,
            'provider_message_id' => $providerMessageId,
            'outbox_id' => $outboxId,
            'status' => $status,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);

        if (! $inserted) {
            return null;
        }

        return WhatsAppDeliveryReceipt::query()->where('dedupe_key', $dedupeKey)->first();
    }

    private function advance(WhatsAppOutboxMessage $outbox, string $status, DateTimeImmutable $occurredAt): void
    {
        $attributes = ['status' => $status];

        if ($status === 'delivered') {
            $attributes['delivered_at'] = $occurredAt;
        }

        if ($status === 'read') {
            $attributes['read_at'] = $occurredAt;
        }

        $outbox->forceFill($attributes)->save();

        if ($outbox->type !== 'template') {
            return;
        }

        $activationRequestId = $outbox->payload['activation_request_id'] ?? null;
        if (! is_string($activationRequestId) || $activationRequestId === '') {
            return;
        }

        $request = ActivationRequest::query()->find($activationRequestId);
        if ($request === null) {
            return;
        }

        $updates = ['provider_message_id' => $outbox->provider_message_id];
        if (in_array($status, ['delivered', 'read'], true)) {
            $updates['status'] = 'delivered';
            $updates['delivered_at'] = $occurredAt;
        }

        $request->forceFill($updates)->save();
    }
}
