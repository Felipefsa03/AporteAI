<?php

declare(strict_types=1);

namespace App\Domain\MarketData;

use App\Domain\MarketData\Adapters\NormalizedRecord;
use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\MarketQuote;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * RB-010/A5: nunca inventa preco. Registros invalidos sao rejeitados e dados vencidos
 * sao armazenados com quality_status=stale, impedidos de alimentar recomendacoes.
 * Indicadores macroeconomicos nunca viram cotacao de instrumento (nao e CDB).
 */
final class MarketDataIngestor
{
    private const QUALITY_APPROVED = 'approved';

    private const QUALITY_STALE = 'stale';

    private const QUALITY_INDICATOR = 'indicator';

    public function __construct(
        private readonly SourceCatalog $catalog,
        private readonly FreshnessPolicy $freshness,
    ) {
    }

    /**
     * @param list<NormalizedRecord> $records
     * @return array{stored: int, stale: int, rejected: int}
     */
    public function ingest(string $sourceKey, array $records, DateTimeImmutable $now): array
    {
        $source = $this->catalog->get($sourceKey);
        $this->assertSchema($source);
        $stats = ['stored' => 0, 'stale' => 0, 'rejected' => 0];
        $reasons = [];

        foreach ($records as $record) {
            $reason = 'record';
            try {
                $this->assertRecord($record, $now, $reason);
            } catch (MarketDataException) {
                $stats['rejected']++;
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                continue;
            }

            $asOf = $record->asOf;
            $dataKind = (string) $source['data_kind'];
            $status = $this->freshness->status($asOf, (int) $source['max_age_seconds'], $now);
            $quality = $dataKind === 'indicator'
                ? self::QUALITY_INDICATOR
                : ($status === 'valid' ? self::QUALITY_APPROVED : self::QUALITY_STALE);

            $instrument = Instrument::query()->firstOrCreate(
                ['symbol' => $record->symbol],
                [
                    'id' => (string) Str::uuid(),
                    'instrument_type' => $this->instrumentType($source),
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
                    'price' => $record->price,
                    'quality_status' => $quality,
                    'captured_at' => $now,
                    'metadata' => [
                        'provider' => $source['provider'],
                        'dataset' => $source['dataset'],
                        'adapter' => $source['adapter'],
                        'license' => $source['license'],
                        'license_evidence' => $source['license_evidence'],
                        'redistribution_allowed' => (bool) $source['redistribution_allowed'],
                        'source_key' => $sourceKey,
                        'data_kind' => $dataKind,
                        'unit' => $source['unit'],
                        'timezone' => $source['timezone'],
                    ],
                ],
            );

            $stats[$quality === self::QUALITY_STALE ? 'stale' : 'stored']++;
        }

        $this->catalog->markChecked($sourceKey, $now);
        $this->audit((string) $source['id'], 'market.ingested', $stats + ['data_kind' => $source['data_kind']]);
        if ($reasons !== []) {
            $this->audit((string) $source['id'], 'market.ingestion_rejected', $reasons);
        }

        return $stats;
    }

    private function assertRecord(NormalizedRecord $record, DateTimeImmutable $now, string &$reason): void
    {
        if (trim($record->symbol) === '') {
            $reason = 'record';

            throw new MarketDataException('Registro oficial sem simbolo.');
        }

        if ($this->freshness->isFuture($record->asOf, $now)) {
            $reason = 'future';

            throw new MarketDataException('Data futura nao pode ser ingerida.');
        }

        if (! is_numeric($record->price) || (float) $record->price <= 0) {
            $reason = 'price';

            throw new MarketDataException('Preco invalido no registro oficial.');
        }
    }

    /** @param array<string, mixed> $source */
    private function assertSchema(array $source): void
    {
        if (! in_array($source['data_kind'] ?? null, ['indicator', 'price'], true)) {
            throw new MarketDataException('Fonte sem natureza de dado declarada (indicator/price).');
        }

        if (! in_array($source['unit'] ?? null, ['percent', 'brl'], true)) {
            throw new MarketDataException('Fonte sem unidade declarada (percent/brl).');
        }

        if (! is_string($source['timezone'] ?? null)
            || ! in_array($source['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new MarketDataException('Fonte sem timezone explicita e valida.');
        }

        if (! is_string($source['license_evidence'] ?? null) || trim($source['license_evidence']) === '') {
            throw new MarketDataException('Fonte sem evidencia de licenca documentada.');
        }

        if (! is_string($source['date_format'] ?? null) || $source['date_format'] === '') {
            throw new MarketDataException('Fonte sem formato de data declarado.');
        }
    }

    /** @param array<string, mixed> $source */
    private function instrumentType(array $source): string
    {
        if ($source['data_kind'] === 'indicator') {
            return 'indicator';
        }

        return match ($source['provider']) {
            'tesouro' => 'treasury',
            'cvm' => 'fund',
            default => 'security',
        };
    }

    /** @param array<string, int|string> $metadata */
    private function audit(string $sourceKey, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'market-data-ingestor',
            'action' => $action,
            'subject_type' => 'data_source',
            'subject_id' => $sourceKey,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
