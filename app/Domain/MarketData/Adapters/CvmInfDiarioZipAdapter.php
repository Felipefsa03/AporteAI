<?php

declare(strict_types=1);

namespace App\Domain\MarketData\Adapters;

use App\Domain\MarketData\MarketDataException;
use ZipArchive;

/**
 * CVM INF_DIARIO: ZIP com um CSV ';' de cotas diarias de fundos.
 * Colunas oficiais: TP_FUNDO_CLASSE;CNPJ_FUNDO_CLASSE;ID_SUBCLASSE;DT_COMPTC;...
 * O limite de descompressao e checado antes da leitura (statIndex) e durante o
 * stream, protegendo contra ZIP bomb e entradas inesperadas.
 */
final class CvmInfDiarioZipAdapter implements SourceAdapter
{
    private const REQUIRED_COLUMNS = ['CNPJ_FUNDO_CLASSE', 'DT_COMPTC', 'VL_QUOTA'];

    public function __construct(
        private readonly string $timezone,
        private readonly PayloadLimits $limits,
    ) {
    }

    public function decode(string $payload): array
    {
        if (strlen($payload) > $this->limits->maxBytes) {
            throw new MarketDataException('ZIP da CVM excede o limite de download.');
        }

        $temporary = tempnam('/tmp/opencode', 'cvm-inf-diario-');
        if ($temporary === false) {
            throw new MarketDataException('Nao foi possivel preparar o arquivo temporario da CVM.');
        }
        file_put_contents($temporary, $payload);

        $zip = new ZipArchive();
        if ($zip->open($temporary) !== true) {
            @unlink($temporary);

            throw new MarketDataException('Arquivo ZIP da CVM invalido.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > $this->limits->maxEntries) {
                throw new MarketDataException('ZIP da CVM com numero de entradas inesperado.');
            }

            $records = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw new MarketDataException('Nao foi possivel inspecionar entrada do ZIP da CVM.');
                }

                $name = (string) ($stat['name'] ?? '');
                if (! str_ends_with(strtolower($name), '.csv')) {
                    throw new MarketDataException('ZIP da CVM contem entrada nao-CSV.');
                }

                $compressed = (int) ($stat['comp_size'] ?? 0);
                $uncompressed = (int) ($stat['size'] ?? 0);
                if ($compressed > $this->limits->maxBytes
                    || $uncompressed > $this->limits->maxUncompressedBytes
                    || ($compressed > 0 && ($uncompressed / $compressed) > $this->limits->maxRatio)) {
                    throw new MarketDataException('Entrada do ZIP da CVM excede limites de tamanho/compressao.');
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new MarketDataException('Nao foi possivel ler a entrada do ZIP da CVM.');
                }

                $buffer = '';
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        break;
                    }
                    if (strlen($buffer) + strlen($chunk) > $this->limits->maxUncompressedBytes) {
                        fclose($stream);

                        throw new MarketDataException('Descompressao da CVM excedeu o limite de tamanho.');
                    }
                    $buffer .= $chunk;
                }
                fclose($stream);

                $records = array_merge($records, $this->parseCsv($buffer));
            }

            if ($records === []) {
                throw new MarketDataException('ZIP da CVM sem cotas validas.');
            }
            if (count($records) > $this->limits->maxRecords) {
                throw new MarketDataException('ZIP da CVM excede o numero maximo de registros.');
            }

            return $records;
        } finally {
            $zip->close();
            @unlink($temporary);
        }
    }

    /** @return list<NormalizedRecord> */
    private function parseCsv(string $payload): array
    {
        $rows = PayloadParsers::csv($payload);
        if ($rows === []) {
            throw new MarketDataException('CSV interno da CVM vazio.');
        }

        $header = array_shift($rows);
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! in_array($column, $header, true)) {
                throw new MarketDataException('CSV da CVM sem a coluna oficial: '.$column);
            }
        }

        $records = [];
        foreach ($rows as $row) {
            if (count($row) !== count($header)) {
                throw new MarketDataException('Linha do CSV da CVM com colunas inconsistentes.');
            }

            /** @var array<string, string> $item */
            $item = array_combine($header, $row);
            $subclass = $item['ID_SUBCLASSE'] ?? '';
            $records[] = new NormalizedRecord(
                $item['CNPJ_FUNDO_CLASSE'].($subclass === '' ? '' : '-'.$subclass),
                PayloadParsers::date($item['DT_COMPTC'], 'Y-m-d', $this->timezone),
                PayloadParsers::decimal($item['VL_QUOTA']),
            );
        }

        return $records;
    }
}
