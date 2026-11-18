<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

use DateTimeImmutable;

/** Registro ja decodificado pelo adaptador da fonte oficial. */
final readonly class NormalizedRecord
{
    public function __construct(
        public string $symbol,
        public DateTimeImmutable $asOf,
        public string $price,
    ) {
    }
}
