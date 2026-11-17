<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppWebhookEvent;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class WebhookDeduplicator
{
    public function isNew(string $externalId, string $payloadHash, DateTimeImmutable $receivedAt): bool
    {
        if (WhatsAppWebhookEvent::query()->where('external_id', $externalId)->exists()) {
            return false;
        }

        try {
            WhatsAppWebhookEvent::query()->create([
                'id' => (string) Str::uuid(),
                'external_id' => $externalId,
                'payload_hash' => $payloadHash,
                'received_at' => $receivedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
