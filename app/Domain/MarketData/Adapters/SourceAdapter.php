<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

/** Contrato dos adaptadores homologados: bytes do feed -> registros normalizados. */
interface SourceAdapter
{
    /**
     * @return list<NormalizedRecord>
     *
     * @throws \App\Domain\MarketData\MarketDataException quando o formato nao e o esperado
     */
    public function decode(string $payload): array;
}
