<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

use App\Domain\MarketData\MarketDataException;

/** So adapters homologados sao construidos; fonte sem adapter e recusada (fail closed). */
final class AdapterFactory
{
    private const SUPPORTED = [
        'bcb_sgs_json',
        'bcb_focus_odata',
        'tesouro_csv',
        'cvm_inf_diario_zip',
    ];

    /** @param array<string, mixed> $source */
    public function make(array $source): SourceAdapter
    {
        $adapter = $source['adapter'] ?? null;
        if (! is_string($adapter) || ! in_array($adapter, self::SUPPORTED, true)) {
            // Fail closed antes de tocar qualquer configuracao ou payload: apenas
            // adaptadores homologados podem ser construidos.
            throw new MarketDataException(
                'Adapter de fonte nao homologado: '.(is_string($adapter) ? $adapter : ''),
            );
        }

        $timezone = (string) ($source['timezone'] ?? '');
        $limits = PayloadLimits::fromConfig();

        return match ($adapter) {
            'bcb_sgs_json' => new BcbSgsJsonAdapter(
                (string) ($source['symbol'] ?? ''),
                (string) ($source['date_format'] ?? 'd/m/Y'),
                $timezone,
                $limits,
            ),
            'bcb_focus_odata' => new BcbFocusODataAdapter($timezone, $limits),
            'tesouro_csv' => new TesouroCsvAdapter($timezone, $limits),
            'cvm_inf_diario_zip' => new CvmInfDiarioZipAdapter($timezone, $limits),
        };
    }
}
