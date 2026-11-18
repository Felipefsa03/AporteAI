<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

/** Limites aplicados antes, durante o download e durante a descompressao. */
final readonly class PayloadLimits
{
    public function __construct(
        public int $maxBytes,
        public int $maxUncompressedBytes,
        public int $maxEntries,
        public int $maxRecords,
        public int $maxRatio,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            max(1, (int) config('market_data.limits.max_bytes', 20_971_520)),
            max(1, (int) config('market_data.limits.max_uncompressed_bytes', 33_554_432)),
            max(1, (int) config('market_data.limits.max_entries', 4)),
            max(1, (int) config('market_data.limits.max_records', 50_000)),
            max(1, (int) config('market_data.limits.max_ratio', 100)),
        );
    }
}
