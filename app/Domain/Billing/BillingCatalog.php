<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/** Produto unico com duas condicoes; os recursos sao identicos em qualquer plano. */
final class BillingCatalog
{
    /** @return array<string, mixed> */
    public function plan(string $code): array
    {
        $plan = config('billing.plans.'.$code);

        if (! is_array($plan)) {
            throw new BillingException('Plano inexistente: '.$code);
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    public function plans(): array
    {
        $plans = config('billing.plans', []);

        return is_array($plans) ? $plans : [];
    }

    /** @return list<string> */
    public function features(): array
    {
        $features = config('billing.features', []);

        return is_array($features) ? array_values($features) : [];
    }

    public function assertConsistent(): void
    {
        foreach ($this->plans() as $code => $plan) {
            $installments = (int) ($plan['installments'] ?? 0);
            $amount = (int) ($plan['amount_cents'] ?? 0);
            $total = (int) ($plan['total_cents'] ?? 0);

            if ($installments < 1 || $amount < 1 || $installments * $amount !== $total) {
                throw new BillingException('Total do plano '.(string) $code.' divergente das parcelas.');
            }
        }
    }
}
