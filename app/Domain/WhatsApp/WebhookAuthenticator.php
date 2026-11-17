<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

/** RB-016: webhooks assinados; nenhuma mensagem e processada sem HMAC valido. */
final class WebhookAuthenticator
{
    public function verify(string $rawBody, ?string $signature): bool
    {
        $secret = config('whatsapp.webhook.app_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature)) {
            return false;
        }

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}
