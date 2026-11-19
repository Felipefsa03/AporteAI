<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Normaliza TODAS as entradas de um webhook da Cloud API.
 *
 * @phpstan-type Event array{
 *     dedupe_key: string,
 *     external_id: string,
 *     kind: string,
 *     phone_number_id: string,
 *     from_number_e164: string,
 *     message_type: string,
 *     body: string,
 *     media_id: string,
 *     provider_status: string,
 *     occurred_at: DateTimeImmutable,
 *     payload: array<string, mixed>
 * }
 */
final class WebhookEventParser
{
    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    public function parse(array $payload, DateTimeImmutable $receivedAt): array
    {
        $events = [];
        $entries = $payload['entry'] ?? [];

        if (! is_array($entries)) {
            return $events;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_array($entry['changes'] ?? null)) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                if (! is_array($change) || ! is_array($change['value'] ?? null)) {
                    continue;
                }

                $value = $change['value'];
                $phoneNumberId = $this->string($value['metadata']['phone_number_id'] ?? '');

                if (is_array($value['messages'] ?? null)) {
                    foreach ($value['messages'] as $message) {
                        if (is_array($message)) {
                            $events[] = $this->message($message, $phoneNumberId, $receivedAt);
                        }
                    }
                }

                if (is_array($value['statuses'] ?? null)) {
                    foreach ($value['statuses'] as $status) {
                        if (is_array($status)) {
                            $events[] = $this->status($status, $phoneNumberId, $receivedAt);
                        }
                    }
                }
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    private function message(array $message, string $phoneNumberId, DateTimeImmutable $receivedAt): array
    {
        $id = $this->string($message['id'] ?? '');
        $type = $this->string($message['type'] ?? 'text');
        $from = $this->string($message['from'] ?? '');
        $body = $this->string($message['text']['body'] ?? '');
        $mediaId = $this->string($message['image']['id'] ?? $message['document']['id'] ?? '');

        if ($body === '') {
            $body = $this->string(
                $message['image']['caption']
                ?? $message['video']['caption']
                ?? $message['document']['caption']
                ?? $message['button']['text']
                ?? $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? '',
            );
        }

        return [
            'dedupe_key' => 'message:'.($id !== '' ? $id : hash('sha256', json_encode($message, JSON_THROW_ON_ERROR))),
            'external_id' => $id,
            'kind' => 'message',
            'phone_number_id' => $phoneNumberId,
            'from_number_e164' => $this->normalizePhone($from),
            'message_type' => $type,
            'body' => $body,
            'media_id' => $mediaId,
            'provider_status' => '',
            'occurred_at' => $this->timestamp($message['timestamp'] ?? null, $receivedAt),
            'payload' => $message,
        ];
    }

    /** @param array<string, mixed> $status @return array<string, mixed> */
    private function status(array $status, string $phoneNumberId, DateTimeImmutable $receivedAt): array
    {
        $id = $this->string($status['id'] ?? '');
        $providerStatus = $this->string($status['status'] ?? '');
        $occurredAt = $this->timestamp($status['timestamp'] ?? null, $receivedAt);

        return [
            'dedupe_key' => 'status:'.$id.':'.$providerStatus.':'.$occurredAt->format(DATE_ATOM),
            'external_id' => $id,
            'kind' => 'status',
            'phone_number_id' => $phoneNumberId,
            'from_number_e164' => $this->normalizePhone($this->string($status['recipient_id'] ?? '')),
            'message_type' => '',
            'body' => '',
            'media_id' => '',
            'provider_status' => $providerStatus,
            'occurred_at' => $occurredAt,
            'payload' => $status,
        ];
    }

    private function timestamp(mixed $raw, DateTimeImmutable $fallback): DateTimeImmutable
    {
        if (! is_numeric($raw) || (int) $raw <= 0) {
            return $fallback;
        }

        return (new DateTimeImmutable('@'.(int) $raw))->setTimezone(new DateTimeZone('UTC'));
    }

    private function normalizePhone(string $phone): string
    {
        if ($phone === '') {
            return '';
        }

        return str_starts_with($phone, '+') ? $phone : '+'.$phone;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
