<?php

declare(strict_types=1);

namespace App\Domain\MarketData;

use DateTimeImmutable;

/** A5: dado vencido ou futuro nunca e considerado valido para recomendacao. */
final class FreshnessPolicy
{
    public function status(DateTimeImmutable $asOf, int $maxAgeSeconds, DateTimeImmutable $now): string
    {
        $age = $now->getTimestamp() - $asOf->getTimestamp();

        if ($age < 0 || $age > $maxAgeSeconds) {
            return 'stale';
        }

        return 'valid';
    }

    /** Dados com data futura sao rejeitados na ingestao, nao apenas marcados como vencidos. */
    public function isFuture(DateTimeImmutable $asOf, DateTimeImmutable $now): bool
    {
        return $asOf > $now;
    }
}
