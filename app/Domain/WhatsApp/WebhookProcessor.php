<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use App\Domain\Conversation\ConversationOrchestrator;
use App\Models\ActivationRequest;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** RB-024/RB-027: entrada autenticada, deduplicada e sem segredos no log. */
final class WebhookProcessor
{
    public function __construct(
        private readonly WebhookDeduplicator $deduplicator,
        private readonly OptInPolicy $optIn,
        private readonly NumberRegistry $numbers,
        private readonly WebhookEventParser $parser,
        private readonly ConversationOrchestrator $orchestrator,
        private readonly WhatsAppOutboundService $outbound,
        private readonly HumanHandoff $handoff,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{external_id: string, phone: string, body: string, phone_number_id: string}|null
     */
    public function extract(array $payload): ?array
    {
        $events = $this->parser->parse($payload, now()->toDateTimeImmutable());
        $event = $events[0] ?? null;
        if ($event === null || ($event['kind'] ?? '') !== 'message') {
            return null;
        }

        return [
            'external_id' => (string) ($event['external_id'] ?? ''),
            'phone' => (string) ($event['from_number_e164'] ?? ''),
            'body' => (string) ($event['body'] ?? ''),
            'phone_number_id' => (string) ($event['phone_number_id'] ?? ''),
        ];
    }

    /** Compatibilidade com chamadas diretas; o controller usa claim()+ingest(). */
    public function process(string $externalId, array $payload, DateTimeImmutable $receivedAt): string
    {
        $events = $this->parser->parse($payload, $receivedAt);
        $event = null;

        foreach ($events as $candidate) {
            if (($candidate['external_id'] ?? '') === $externalId) {
                $event = $candidate;
                break;
            }
        }

        if ($event === null) {
            $event = $events[0] ?? null;
        }

        if ($event === null) {
            return 'ignored';
        }

        $inboxEventId = $this->deduplicator->claim($event, $receivedAt);
        if ($inboxEventId === null) {
            return 'duplicate';
        }

        $event['inbox_event_id'] = $inboxEventId;
        $result = $this->ingest($event, $receivedAt, false);

        return (string) ($result['status'] ?? 'ignored');
    }

    /**
     * @param array<string, mixed> $event
     * @return array{status: string, reply?: array<string, mixed>}
     */
    public function ingest(array $event, DateTimeImmutable $receivedAt, bool $queueReply = true): array
    {
        if (($event['kind'] ?? '') !== 'message') {
            return ['status' => 'ignored'];
        }

        $expected = $this->numbers->botPhoneNumberId();
        if (($event['phone_number_id'] ?? '') !== $expected) {
            return ['status' => 'ignored'];
        }

        $externalId = (string) ($event['external_id'] ?? '');
        if ($externalId === '') {
            return ['status' => 'ignored'];
        }

        $from = (string) ($event['from_number_e164'] ?? '');
        $body = (string) ($event['body'] ?? '');
        $investor = $from === '' ? null : $this->investorForPhone($from);

        $conversation = WhatsAppConversation::query()->firstOrCreate(
            [
                'bot_phone_number_id' => $expected,
                'investor_id' => $investor?->id,
            ],
            [
                'id' => (string) Str::uuid(),
                'state' => 'active',
                'last_message_at' => $receivedAt,
                'last_inbound_at' => $receivedAt,
            ],
        );

        $occurredAt = $event['occurred_at'] instanceof DateTimeImmutable ? $event['occurred_at'] : $receivedAt;
        $inserted = WhatsAppMessage::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'external_id' => $externalId,
            'direction' => 'inbound',
            'type' => $this->messageType($event),
            'encrypted_content' => 'sha256:'.hash('sha256', $body),
            'occurred_at' => $occurredAt,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        if (! $inserted) {
            return ['status' => 'duplicate'];
        }

        $conversation->forceFill([
            'state' => 'active',
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $receivedAt,
        ])->save();

        $this->markFirstReply($investor?->id, $receivedAt);

        $keyword = strtoupper(trim($body));
        $optOut = config('whatsapp.keywords.opt_out', []);
        $handoffKeywords = config('whatsapp.keywords.human_handoff', []);

        if ($investor !== null && is_array($optOut) && in_array($keyword, $optOut, true)) {
            $this->optIn->revokeActive($investor, $receivedAt);

            return ['status' => 'opt_out'];
        }

        if (is_array($handoffKeywords) && in_array($keyword, $handoffKeywords, true)) {
            $this->auditHandoff($investor, $event, $receivedAt);
            if ($queueReply && $investor !== null) {
                $instructions = $this->handoff->instructions();
                $this->outbound->queueText(
                    $investor,
                    'Encaminhando para atendimento humano. Fale pelo '.$instructions['number_e164'].'.',
                    $receivedAt,
                    $conversation,
                    $event['inbox_event_id'] ?? null,
                );
            }

            return ['status' => 'human_handoff'];
        }

        if (! $queueReply || $investor === null) {
            return ['status' => 'stored'];
        }

        $reply = $this->orchestrator->handle($investor, $body, $receivedAt, [
            'external_id' => $externalId,
            'inbox_event_id' => $event['inbox_event_id'] ?? null,
            'occurred_at' => $occurredAt,
            'body' => $body,
        ]);
        $text = isset($reply['reply']) && is_string($reply['reply']) ? $reply['reply'] : '';

        if ($text !== '') {
            $this->outbound->queueText(
                $investor,
                $text,
                $receivedAt,
                $conversation,
                $event['inbox_event_id'] ?? null,
            );
        }

        return ['status' => 'stored', 'reply' => $reply];
    }

    /** @param array<string, mixed> $event */
    private function auditHandoff(?Investor $investor, array $event, DateTimeImmutable $receivedAt): void
    {
        $inboxId = (string) ($event['inbox_event_id'] ?? '');
        if ($investor === null || $inboxId === '') {
            return;
        }

        if (AuditEvent::query()
            ->where('action', 'journey.command')
            ->where('metadata->inbox_event_id', $inboxId)
            ->exists()) {
            return;
        }

        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'investor',
            'actor_id' => (string) $investor->id,
            'action' => 'journey.command',
            'subject_type' => 'investor',
            'subject_id' => (string) $investor->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => [
                'inbox_event_id' => $inboxId,
                'external_id' => (string) ($event['external_id'] ?? ''),
                'command' => 'handoff',
                'action' => 'human_handoff',
            ],
            'occurred_at' => $receivedAt,
        ]);
    }

    public function investorForPhone(string $phone): ?Investor
    {
        if ($phone === '') {
            return null;
        }

        return Investor::query()
            ->where('phone_e164', $this->normalizePhone($phone))
            ->first();
    }

    /** @param array<string, mixed> $event */
    private function messageType(array $event): string
    {
        $type = (string) ($event['message_type'] ?? 'text');

        return $type === '' ? 'text' : $type;
    }

    private function normalizePhone(string $phone): string
    {
        return str_starts_with($phone, '+') ? $phone : '+'.$phone;
    }

    private function markFirstReply(?string $investorId, DateTimeImmutable $receivedAt): void
    {
        if ($investorId === null) {
            return;
        }

        ActivationRequest::query()
            ->where('investor_id', $investorId)
            ->whereNull('first_reply_at')
            ->update(['first_reply_at' => $receivedAt]);
    }
}
