<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use App\Models\ActivationRequest;
use App\Models\Investor;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** RB-024/RB-027: entrada autenticada, deduplicada e sem conter segredos no log. */
final class WebhookProcessor
{
    public function __construct(
        private readonly WebhookDeduplicator $deduplicator,
        private readonly OptInPolicy $optIn,
        private readonly NumberRegistry $numbers,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function process(string $externalId, array $payload, DateTimeImmutable $receivedAt): string
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        if (! $this->deduplicator->isNew($externalId, $hash, $receivedAt)) {
            return 'duplicate';
        }

        $message = $payload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
        $phoneNumberId = $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
        $expected = $this->numbers->botPhoneNumberId();

        if (! is_array($message) || (string) $phoneNumberId !== $expected) {
            return 'ignored';
        }

        $from = isset($message['from']) ? (string) $message['from'] : '';
        $body = isset($message['text']['body']) ? (string) $message['text']['body'] : '';
        $investor = $from === ''
            ? null
            : Investor::query()->where('phone_e164', $this->normalizePhone($from))->first();

        $conversation = WhatsAppConversation::query()->firstOrCreate(
            [
                'bot_phone_number_id' => $expected,
                'investor_id' => $investor?->id,
            ],
            [
                'id' => (string) Str::uuid(),
                'state' => 'active',
                'last_message_at' => $receivedAt,
            ],
        );

        WhatsAppMessage::query()->create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'external_id' => $externalId,
            'direction' => 'inbound',
            'type' => 'text',
            'encrypted_content' => 'sha256:'.hash('sha256', $body),
            'occurred_at' => $receivedAt,
        ]);

        $conversation->forceFill(['last_message_at' => $receivedAt])->save();
        $this->markFirstReply($investor?->id, $receivedAt);

        $keyword = strtoupper(trim($body));
        $optOut = config('whatsapp.keywords.opt_out', []);
        $handoff = config('whatsapp.keywords.human_handoff', []);

        if ($investor !== null && is_array($optOut) && in_array($keyword, $optOut, true)) {
            $this->optIn->revokeActive($investor, $receivedAt);
            return 'opt_out';
        }

        if (is_array($handoff) && in_array($keyword, $handoff, true)) {
            return 'human_handoff';
        }

        return 'stored';
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
