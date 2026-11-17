<?php

declare(strict_types=1);

namespace App\Domain\MarketData;

use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\MarketQuote;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Str;

/**
 * RB-010/A5: nunca inventa preco. Registros invalidos sao rejeitados e dados vencidos
 * sao armazenados com quality_status=stale, impedidos de alimentar recomendacoes.
 */
final class MarketDataIngestor
{
    public function __construct(
        private readonly SourceCatalog $catalog,
        private readonly FreshnessPolicy $freshness,
    ) {
    }

    /**
     * @param array<mixed> $payload
     * @return array{stored: int, stale: int, rejected: int}
     */
    public function ingest(string $sourceKey, array $payload, DateTimeImmutable $now): array
    {
        $source = $this->catalog->get($sourceKey);
        $stats = ['stored' => 0, 'stale' => 0, 'rejected' => 0];

        foreach ($payload as $item) {
            try {
                [$symbol, $asOf, $price] = $this->normalize($source, $item);
            } catch (MarketDataException) {
                $stats['rejected']++;

                continue;
            }

            $status = $this->freshness->status($asOf, (int) ($source['max_age_seconds'] ?? 0), $now);

            $instrument = Instrument::query()->firstOrCreate(
                ['symbol' => $symbol],
                [
                    'id' => (string) Str::uuid(),
                    'instrument_type' => $this->instrumentType((string) $source['provider']),
                    'status' => 'active',
                ],
            );

            MarketQuote::query()->updateOrCreate(
                [
                    'instrument_id' => $instrument->id,
                    'source_id' => $source['id'],
                    'as_of' => $asOf,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'price' => $price,
                    'quality_status' => $status,
                    'captured_at' => $now,
                    'metadata' => [
                        'provider' => $source['provider'],
                        'dataset' => $source['dataset'],
                        'license' => $source['license'],
                    ],
                ],
            );

            $stats[$status === 'valid' ? 'stored' : 'stale']++;
        }

        $this->catalog->markChecked($sourceKey, $now);
        $this->audit((string) $source['id'], $stats);

        return $stats;
    }

    /**
     * @param array<string, mixed> $source
     * @return array{0: string, 1: DateTimeImmutable, 2: string}
     */
    private function normalize(array $source, mixed $item): array
    {
        if (! is_array($item)) {
            throw new MarketDataException('Registro de mercado invalido.');
        }

        $symbol = $source['adapter'] === 'bcb'
            ? (string) ($source['symbol'] ?? '')
            : (string) ($item[$source['symbol_field']] ?? '');
        $rawDate = (string) ($item[$source['date_field']] ?? '');
        $rawPrice = $item[$source['price_field']] ?? null;

        if ($symbol === '' || $rawDate === '') {
            throw new MarketDataException('Registro de mercado sem simbolo ou data.');
        }

        try {
            $asOf = DateTimeImmutable::createFromFormat((string) $source['date_format'], $rawDate);
            if ($asOf === false) {
                $asOf = new DateTimeImmutable($rawDate);
            } elseif (! str_contains((string) $source['date_format'], 'H')) {
                $asOf = $asOf->setTime(0, 0);
            }
        } catch (Exception) {
            throw new MarketDataException('Data de mercado invalida.');
        }

        if (is_string($rawPrice)) {
            $rawPrice = str_replace(',', '.', $rawPrice);
        }

        if (! is_numeric($rawPrice) || (float) $rawPrice <= 0) {
            throw new MarketDataException('Preco de mercado invalido.');
        }

        return [$symbol, $asOf, (string) (float) $rawPrice];
    }

    private function instrumentType(string $provider): string
    {
        return match ($provider) {
            'bcb' => 'indicator',
            'tesouro' => 'treasury',
            'cvm' => 'fund',
            default => 'security',
        };
    }

    /** @param array{stored: int, stale: int, rejected: int} $stats */
    private function audit(string $sourceKey, array $stats): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'market-data-ingestor',
            'action' => 'market.ingested',
            'subject_type' => 'data_source',
            'subject_id' => $sourceKey,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $stats,
            'occurred_at' => now(),
        ]);
    }
}
