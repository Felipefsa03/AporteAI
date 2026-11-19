<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppInboxEvent;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** RB-016/RB-027: claim atômico antes de qualquer efeito; replay é apenas leitura. */
final class WebhookDeduplicator
{
    /** @param array<string, mixed> $event */
    public function claim(array $event, DateTimeImmutable $receivedAt): ?string
    {
        $dedupeKey = isset($event['dedupe_key']) ? (string) $event['dedupe_key'] : '';
        if ($dedupeKey === '') {
            return null;
        }

        $payloadJson = json_encode($event['payload'] ?? [], JSON_THROW_ON_ERROR);

        $inserted = WhatsAppInboxEvent::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'dedupe_key' => $dedupeKey,
            'external_id' => isset($event['external_id']) ? (string) $event['external_id'] : null,
            'kind' => isset($event['kind']) ? (string) $event['kind'] : 'unknown',
            'phone_number_id' => isset($event['phone_number_id']) ? (string) $event['phone_number_id'] : null,
            'from_number_e164' => isset($event['from_number_e164']) ? (string) $event['from_number_e164'] : null,
            'message_type' => isset($event['message_type']) ? (string) $event['message_type'] : null,
            'provider_status' => isset($event['provider_status']) ? (string) $event['provider_status'] : null,
            'payload_hash' => hash('sha256', $payloadJson),
            'payload' => $payloadJson,
            'state' => 'processed',
            'received_at' => $receivedAt,
            'processed_at' => $receivedAt,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        if (! $inserted) {
            return null;
        }

        $id = WhatsAppInboxEvent::query()
            ->where('dedupe_key', $dedupeKey)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    public function markProcessed(string $dedupeKey, DateTimeImmutable $processedAt): void
    {
        WhatsAppInboxEvent::query()
            ->where('dedupe_key', $dedupeKey)
            ->update([
                'state' => 'processed',
                'processed_at' => $processedAt,
            ]);
    }

    /** Compatibilidade com chamadas legadas; o claim moderno usa dedupe_key. */
    public function isNew(string $externalId, string $payloadHash, DateTimeImmutable $receivedAt): bool
    {
        $event = [
            'dedupe_key' => 'legacy:'.$externalId,
            'external_id' => $externalId,
            'kind' => 'message',
            'payload' => ['hash' => $payloadHash],
        ];

        return $this->claim($event, $receivedAt) !== null;
    }
}
