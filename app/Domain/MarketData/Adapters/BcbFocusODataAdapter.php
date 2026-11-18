<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

use App\Domain\MarketData\MarketDataException;
use JsonException;

/**
 * BCB Expectativas de Mercado (OData ExpectativasMercadoSelic): fonte verificada
 * no catalogo de dados abertos do BCB, distinta de uma serie SGS arbitraria.
 * Cada reuniao/reference gera um simbolo proprio; usa a mediana divulgada.
 */
final class BcbFocusODataAdapter implements SourceAdapter
{
    public function __construct(
        private readonly string $timezone,
        private readonly PayloadLimits $limits,
    ) {
    }

    public function decode(string $payload): array
    {
        try {
            $document = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MarketDataException('JSON invalido no OData de Expectativas do BCB.');
        }

        $rows = $document['value'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new MarketDataException('OData de Expectativas nao trouxe value[].');
        }

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['Indicador'] ?? null) !== 'Selic') {
                continue;
            }

            $meeting = preg_replace('/[^A-Za-z0-9]/', '', (string) ($row['Reuniao'] ?? ''));
            if ($meeting === null || $meeting === '') {
                throw new MarketDataException('Expectativa Selic sem reuniao identificavel.');
            }

            $records[] = new NormalizedRecord(
                'FOCUS_SELIC_'.strtoupper($meeting),
                PayloadParsers::date((string) ($row['Data'] ?? ''), 'Y-m-d', $this->timezone),
                PayloadParsers::decimal($row['Mediana'] ?? null),
            );

            if (count($records) > $this->limits->maxRecords) {
                throw new MarketDataException('OData de Expectativas excede o numero maximo de registros.');
            }
        }

        if ($records === []) {
            throw new MarketDataException('OData de Expectativas sem observacoes Selic.');
        }

        return $records;
    }
}
