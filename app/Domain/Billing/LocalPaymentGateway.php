<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\PaymentRecord;

/** Implementação determinística sobre o registro conciliado; sem chamada de rede no MVP. */
final class LocalPaymentGateway implements PaymentGateway
{
    public function status(PaymentRecord $payment): string
    {
        $this->assertAvailable();

        return (string) $payment->status;
    }

    public function validatedPaymentUrl(PaymentRecord $payment): ?string
    {
        $this->assertAvailable();

        $url = $payment->payment_url;
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($host) || $host === '') {
            return null;
        }

        $allowed = config('billing.gateway.allowed_hosts', []);
        if (is_array($allowed) && $allowed !== [] && ! in_array($host, $allowed, true)) {
            return null;
        }

        return $url;
    }

    private function assertAvailable(): void
    {
        if (config('billing.gateway.available', true) === false) {
            throw new PaymentGatewayException('Gateway de pagamento indisponivel.');
        }
    }
}
