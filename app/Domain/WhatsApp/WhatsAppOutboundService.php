<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use App\Jobs\SendWhatsAppOutbox;
use App\Models\ActivationRequest;
use App\Models\Investor;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppDeadLetter;
use App\Models\WhatsAppOutboxMessage;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Throwable;

/** RB-023/RB-027/A12: toda saida passa por outbox, politicas e retry auditavel. */
final class WhatsAppOutboundService
{
    public function __construct(
        private readonly WhatsAppGateway $gateway,
        private readonly OptInPolicy $optIn,
        private readonly NumberRegistry $numbers,
        private readonly WhatsAppReceiptService $receipts,
    ) {
    }

    public function queueText(
        Investor $investor,
        string $text,
        DateTimeImmutable $now,
        ?WhatsAppConversation $conversation = null,
        ?string $inboxEventId = null,
    ): WhatsAppOutboxMessage {
        return $this->create(
            $investor,
            'text',
            ['text' => $text],
            $now,
            'reply:'.($inboxEventId ?? (string) Str::uuid()),
            $conversation,
            $inboxEventId,
        );
    }

    public function queueImageBytes(
        Investor $investor,
        string $pngBytes,
        DateTimeImmutable $now,
        ?string $caption = null,
        ?WhatsAppConversation $conversation = null,
        ?string $idempotencyKey = null,
    ): WhatsAppOutboxMessage {
        return $this->create(
            $investor,
            'image',
            [
                'bytes_base64' => base64_encode($pngBytes),
                'caption' => $caption,
            ],
            $now,
            'png:'.($idempotencyKey ?? (string) Str::uuid()),
            $conversation,
            null,
        );
    }

    public function queueTemplate(
        Investor $investor,
        string $templateName,
        string $botPhoneNumberId,
        DateTimeImmutable $now,
        ?string $activationRequestId = null,
    ): WhatsAppOutboxMessage {
        return $this->create(
            $investor,
            'template',
            [
                'template' => $templateName,
                'activation_request_id' => $activationRequestId,
            ],
            $now,
            'activation:'.($activationRequestId ?? (string) Str::uuid()),
            null,
            null,
            $botPhoneNumberId,
        );
    }

    public function dispatchPending(DateTimeImmutable $now): int
    {
        $pending = WhatsAppOutboxMessage::query()
            ->where('status', 'queued')
            ->where('available_at', '<=', $now)
            ->orderBy('available_at')
            ->limit(100)
            ->get();

        foreach ($pending as $outbox) {
            SendWhatsAppOutbox::dispatch($outbox->id)->onQueue((string) config('whatsapp.queue'));
        }

        return $pending->count();
    }

    public function deliver(string $outboxId, DateTimeImmutable $now): WhatsAppOutboxMessage
    {
        $outbox = WhatsAppOutboxMessage::query()->findOrFail($outboxId);

        if (! in_array($outbox->status, ['queued', 'sending'], true)) {
            return $outbox;
        }

        $investor = $outbox->investor_id === null
            ? null
            : Investor::withTrashed()->find($outbox->investor_id);
        $conversation = $outbox->conversation_id === null
            ? null
            : WhatsAppConversation::query()->find($outbox->conversation_id);
        $reason = $investor === null
            ? 'recipient_unknown'
            : $this->blockReason($investor, $outbox->type, is_array($outbox->payload) ? $outbox->payload : [], $conversation, $now);

        if ($reason !== null) {
            $outbox->forceFill([
                'status' => 'blocked',
                'last_error' => $reason,
            ])->save();

            return $outbox;
        }

        if ($investor === null) {
            throw new WhatsAppPermanentException('Destinatario ausente na outbox.', 'recipient_unknown');
        }

        $outbox->forceFill([
            'status' => 'sending',
            'attempts' => (int) $outbox->attempts + 1,
            'last_error' => null,
        ])->save();

        $payload = is_array($outbox->payload) ? $outbox->payload : [];

        try {
            $providerMessageId = $this->send($outbox, $investor, $payload);
        } catch (WhatsAppTransientException $exception) {
            $delay = max(1, $exception->retryAfterSeconds ?? 10);
            $outbox->forceFill([
                'status' => 'queued',
                'last_error' => 'transient:'.$exception->getCode(),
                'available_at' => $now->modify('+'.$delay.' seconds'),
            ])->save();

            throw $exception;
        } catch (WhatsAppPermanentException $exception) {
            $outbox->forceFill([
                'status' => 'blocked',
                'last_error' => $exception->reason,
            ])->save();

            return $outbox;
        }

        if ($providerMessageId === '') {
            throw new WhatsAppPermanentException('Provider nao devolveu identificador.', 'missing_provider_id');
        }

        $outbox->forceFill([
            'status' => 'sent',
            'provider_message_id' => $providerMessageId,
            'sent_at' => $now,
            'last_error' => null,
        ])->save();

        $this->receipts->record($providerMessageId, 'sent', $payload, $now, $outbox->id);

        if ($outbox->type === 'template') {
            $requestId = $payload['activation_request_id'] ?? null;
            if (is_string($requestId) && $requestId !== '') {
                ActivationRequest::query()
                    ->where('id', $requestId)
                    ->whereNull('provider_message_id')
                    ->update([
                        'status' => 'template_sent',
                        'provider_message_id' => $providerMessageId,
                    ]);
            }
        }

        $conversation?->forceFill(['last_outbound_at' => $now])->save();

        return $outbox->refresh();
    }

    public function deadLetter(string $outboxId, ?Throwable $exception): void
    {
        $outbox = WhatsAppOutboxMessage::query()->find($outboxId);
        if ($outbox === null) {
            return;
        }

        $outbox->forceFill([
            'status' => 'dead',
            'last_error' => $exception === null ? 'retry_exhausted' : get_class($exception),
        ])->save();

        $failedAt = now()->toDateTimeImmutable();
        WhatsAppDeadLetter::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'dedupe_key' => 'outbox:'.$outboxId,
            'reference_type' => 'outbox',
            'reference_id' => $outboxId,
            'reason' => $exception?->getMessage() !== '' && $exception !== null
                ? $exception->getMessage()
                : 'retry_exhausted',
            'attempts' => (int) $outbox->attempts,
            'payload' => json_encode(is_array($outbox->payload) ? $outbox->payload : [], JSON_THROW_ON_ERROR),
            'failed_at' => $failedAt,
            'created_at' => $failedAt,
            'updated_at' => $failedAt,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function create(
        Investor $investor,
        string $type,
        array $payload,
        DateTimeImmutable $now,
        string $idempotencyKey,
        ?WhatsAppConversation $conversation,
        ?string $inboxEventId,
        ?string $botPhoneNumberId = null,
    ): WhatsAppOutboxMessage {
        $bot = $botPhoneNumberId ?? $this->numbers->botPhoneNumberId();
        $reason = $this->blockReason($investor, $type, $payload, $conversation, $now);

        $attributes = [
            'id' => (string) Str::uuid(),
            'inbox_event_id' => $inboxEventId,
            'conversation_id' => $conversation?->id,
            'investor_id' => $investor->id,
            'idempotency_key' => $idempotencyKey,
            'to_number_e164' => $investor->phone_e164,
            'bot_phone_number_id' => $bot,
            'type' => $type,
            'payload' => $payload,
            'status' => $reason === null ? 'queued' : 'blocked',
            'attempts' => 0,
            'available_at' => $now,
            'last_error' => $reason,
        ];

        $attributes['payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $attributes['created_at'] = $now;
        $attributes['updated_at'] = $now;

        WhatsAppOutboxMessage::query()->insertOrIgnore($attributes);

        return WhatsAppOutboxMessage::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function blockReason(
        Investor $investor,
        string $type,
        array $payload,
        ?WhatsAppConversation $conversation,
        DateTimeImmutable $now,
    ): ?string {
        if ($this->optIn->isOptedOut($investor)) {
            return 'opt_out';
        }

        if ($type === 'template') {
            $template = $payload['template'] ?? '';
            $allowed = config('whatsapp.templates.allowed', []);

            if (! is_string($template) || ! is_array($allowed) || ! in_array($template, $allowed, true)) {
                return 'template_not_allowed';
            }

            return $this->optIn->isEligible($investor, $now) ? null : 'opt_in_required';
        }

        if ($type !== 'text' && $type !== 'image') {
            return 'unsupported_type';
        }

        $hours = max(1, (int) config('whatsapp.delivery.window_hours', 24));
        $lastInbound = $conversation?->last_inbound_at?->getTimestamp();
        $windowStart = $now->modify('-'.$hours.' hours')->getTimestamp();

        if ($lastInbound === null || $lastInbound < $windowStart) {
            return 'window_closed';
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function send(WhatsAppOutboxMessage $outbox, Investor $investor, array $payload): string
    {
        $to = $outbox->to_number_e164;
        $bot = $outbox->bot_phone_number_id;

        return match ($outbox->type) {
            'template' => $this->gateway->sendTemplate($to, (string) ($payload['template'] ?? ''), $bot),
            'text' => $this->gateway->sendText($to, (string) ($payload['text'] ?? ''), $bot),
            'image' => $this->sendImage($to, $bot, $payload),
            default => throw new WhatsAppPermanentException('Tipo de saida nao suportado.', 'unsupported_type'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function sendImage(string $to, string $bot, array $payload): string
    {
        $caption = isset($payload['caption']) && is_string($payload['caption']) ? $payload['caption'] : null;

        if (isset($payload['bytes_base64']) && is_string($payload['bytes_base64'])) {
            $bytes = base64_decode($payload['bytes_base64'], true);
            if ($bytes === false || $bytes === '') {
                throw new WhatsAppPermanentException('PNG invalido na outbox.', 'invalid_png');
            }

            return $this->gateway->sendImageBytes($to, $bot, $bytes, $caption);
        }

        $media = isset($payload['media_id']) && is_string($payload['media_id']) ? $payload['media_id'] : '';
        if ($media === '') {
            throw new WhatsAppPermanentException('Midia ausente na outbox.', 'missing_media');
        }

        return $this->gateway->sendImage($to, $bot, $media, $caption);
    }
}
