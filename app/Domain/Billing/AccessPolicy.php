<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Subscription;

/** RB-029/RB-031/A13: bloqueio de servicos pagos acontece no backend, antes do LLM. */
final class AccessPolicy
{
    public function allowsPaidServices(Subscription $subscription): bool
    {
        return $subscription->status === 'active' && $subscription->access_status === 'active';
    }

    public function label(Subscription $subscription): string
    {
        if ($subscription->status === 'canceled') {
            return 'Cancelado';
        }

        if ($subscription->status === 'past_due' && $subscription->access_status === 'suspended') {
            return 'Suspenso por inadimplencia';
        }

        if ($subscription->access_status === 'suspended' && $subscription->status === 'active') {
            return 'Aguardando conciliacao';
        }

        if ($this->allowsPaidServices($subscription)) {
            return 'Em dia';
        }

        return 'Sem acesso';
    }
}
