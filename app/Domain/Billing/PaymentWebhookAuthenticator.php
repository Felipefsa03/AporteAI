<?php

declare(strict_types=1);

namespace App\Domain\Billing;

final class PaymentWebhookAuthenticator
{
    public function verify(string $rawBody, ?string $signature): bool
    {
        $secret = config('billing.gateway.webhook_secret');

        if (! is_string($secret) || $secret === '' || $signature === null) {
            return false;
        }

        return hash_equals(
            'sha256='.hash_hmac('sha256', $rawBody, $secret),
            $signature,
        );
    }
}
