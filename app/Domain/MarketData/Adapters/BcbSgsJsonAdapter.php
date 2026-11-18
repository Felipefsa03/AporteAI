<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

use App\Domain\MarketData\MarketDataException;
use JsonException;
use Throwable;

/**
 * BCB SGS (api.bcb.gov.br), serie 432: JSON com "data" em dd/mm/yyyy e "valor"
 * decimal. Indicador macroeconomico: nunca vira cotacao de instrumento.
 */
final class BcbSgsJsonAdapter implements SourceAdapter
{
    public function __construct(
        private readonly string $symbol,
        private readonly string $dateFormat,
        private readonly string $timezone,
        private readonly PayloadLimits $limits,
    ) {
    }

    public function decode(string $payload): array
    {
        try {
            $document = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MarketDataException('JSON invalido no feed BCB SGS.');
        }

        if (! is_array($document) || ! array_is_list($document)) {
            throw new MarketDataException('Feed BCB SGS nao e uma lista de observacoes.');
        }

        $records = [];
        foreach ($document as $row) {
            if (! is_array($row)) {
                throw new MarketDataException('Observacao BCB SGS invalida.');
            }

            $records[] = new NormalizedRecord(
                $this->symbol,
                PayloadParsers::date((string) ($row['data'] ?? ''), $this->dateFormat, $this->timezone),
                PayloadParsers::decimal($row['valor'] ?? null),
            );

            if (count($records) > $this->limits->maxRecords) {
                throw new MarketDataException('Feed BCB SGS excede o numero maximo de registros.');
            }
        }

        return $records;
    }
}
