<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

use App\Domain\MarketData\MarketDataException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Conversoes comuns aos formatos oficiais: datas locais e decimais com virgula. */
final class PayloadParsers
{
    public static function date(string $raw, string $format, string $timezone): DateTimeImmutable
    {
        try {
            $parsed = DateTimeImmutable::createFromFormat(
                '!'.$format,
                trim($raw),
                new DateTimeZone($timezone),
            );
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false) {
            throw new MarketDataException('Data invalida no feed oficial: '.$raw);
        }

        return $parsed;
    }

    public static function decimal(mixed $raw): string
    {
        if (is_int($raw) || is_float($raw)) {
            $value = (string) $raw;
        } elseif (is_string($raw)) {
            // BCB/Tesouro usam virgula decimal; CVM/OData usam ponto.
            $value = str_replace(',', '.', trim($raw));
        } else {
            throw new MarketDataException('Valor numerico ausente no feed oficial.');
        }

        if (! is_numeric($value) || (float) $value <= 0) {
            throw new MarketDataException('Valor numerico invalido no feed oficial.');
        }

        return (string) (float) $value;
    }

    /**
     * @return list<list<string>>
     *
     * @param non-empty-string $delimiter
     */
    public static function csv(string $payload, string $delimiter = ';'): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketDataException('Nao foi possivel preparar o parser CSV.');
        }

        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $payload) ?? $payload);
        rewind($handle);

        $rows = [];
        // escape='' explicito: PHP 8.4 mudara o default; explicitar evita deprecacao.
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $rows[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
        }

        fclose($handle);

        return $rows;
    }
}
