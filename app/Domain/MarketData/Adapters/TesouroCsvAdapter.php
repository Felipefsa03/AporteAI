<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

use App\Domain\MarketData\MarketDataException;

/**
 * Tesouro Transparente (CKAN): CSV delimitado por ';' com colunas oficiais
 * "Tipo Titulo;Data Vencimento;Data Base;...;PU Venda Manha".
 * Data Base em dd/mm/yyyy e PU em decimal com virgula. Usa PU (preco), nao Taxa.
 */
final class TesouroCsvAdapter implements SourceAdapter
{
    private const REQUIRED_COLUMNS = ['Tipo Titulo', 'Data Vencimento', 'Data Base', 'PU Venda Manha'];

    public function __construct(
        private readonly string $timezone,
        private readonly PayloadLimits $limits,
    ) {
    }

    public function decode(string $payload): array
    {
        $rows = PayloadParsers::csv($payload);
        if ($rows === []) {
            throw new MarketDataException('CSV do Tesouro Transparente vazio.');
        }

        $header = array_shift($rows);
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! in_array($column, $header, true)) {
                throw new MarketDataException('CSV do Tesouro sem a coluna oficial: '.$column);
            }
        }

        $records = [];
        foreach ($rows as $row) {
            if (count($row) !== count($header)) {
                throw new MarketDataException('Linha do CSV do Tesouro com colunas inconsistentes.');
            }

            /** @var array<string, string> $item */
            $item = array_combine($header, $row);
            $records[] = new NormalizedRecord(
                $item['Tipo Titulo'].' '.$item['Data Vencimento'],
                PayloadParsers::date($item['Data Base'], 'd/m/Y', $this->timezone),
                PayloadParsers::decimal($item['PU Venda Manha']),
            );

            if (count($records) > $this->limits->maxRecords) {
                throw new MarketDataException('CSV do Tesouro excede o numero maximo de registros.');
            }
        }

        if ($records === []) {
            throw new MarketDataException('CSV do Tesouro sem linhas de preco.');
        }

        return $records;
    }
}
