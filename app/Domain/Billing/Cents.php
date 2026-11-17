<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/** RB-008: toda aritmetica monetaria em centavos, sem ponto flutuante. */
final class Cents
{
    public static function fromBrl(string $value): int
    {
        $normalized = str_replace(['R$', ' ', '.'], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);

        if (preg_match('/^\d+(\.\d{1,2})?$/', $normalized) !== 1) {
            throw new BillingException('Valor em BRL invalido.');
        }

        $parts = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $parts[0] * 100) + (int) str_pad($parts[1], 2, '0');
    }

    public static function format(int $cents): string
    {
        $signal = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d,%02d', $signal, intdiv($absolute, 100), $absolute % 100);
    }
}
