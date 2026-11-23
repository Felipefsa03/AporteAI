<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Subscription;
use App\Models\WhatsAppDeadLetter;
use App\Models\WhatsAppOutboxMessage;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** OPS: métricas locais, sem PII e sem dependência de SaaS pago. */
final class OpsMetrics
{
    /** @param array<string, mixed> $metadata */
    public function alert(string $name, array $metadata = []): void
    {
        $occurredAt = new DateTimeImmutable();
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'ops',
            'action' => 'ops.alert.'.$name,
            'subject_type' => 'ops',
            'subject_id' => $name,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        $now = new DateTimeImmutable();
        $since = $now->modify('-24 hours');

        return [
            'ops_alerts_total_24h' => (int) AuditEvent::query()
                ->where('action', 'like', 'ops.alert.%')
                ->where('occurred_at', '>=', $since)
                ->count(),
            'queue_failed_jobs_total' => (int) DB::table('failed_jobs')->count(),
            'whatsapp_dead_letters_total' => (int) WhatsAppDeadLetter::query()->count(),
            'whatsapp_outbox_dead_total' => (int) WhatsAppOutboxMessage::query()
                ->where('status', 'dead')
                ->count(),
            'whatsapp_outbox_sent_total_24h' => (int) WhatsAppOutboxMessage::query()
                ->where('status', 'sent')
                ->where('sent_at', '>=', $since)
                ->count(),
            'active_subscriptions_total' => (int) Subscription::query()
                ->where('access_status', 'active')
                ->count(),
            'public_recommendations' => config('pilot.public_recommendations', false) ? 1 : 0,
        ];
    }
}
