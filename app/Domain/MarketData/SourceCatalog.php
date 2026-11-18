<?php

declare(strict_types=1);

namespace App\Domain\MarketData;

use App\Models\DataSource;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** C07: somente fontes com licenca de uso explicita entram no hub. */
final class SourceCatalog
{
    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $config = config('market_data.sources.'.$key);

        if (! is_array($config)) {
            throw new MarketDataException('Fonte de dados desconhecida: '.$key);
        }

        if (($config['usage_allowed'] ?? false) !== true) {
            throw new MarketDataException('Fonte sem licenca de uso autorizada: '.$key);
        }

        $source = DataSource::query()->firstOrCreate(
            [
                'provider' => $config['provider'],
                'dataset' => $config['dataset'],
            ],
            [
                'id' => (string) Str::uuid(),
                'license' => $config['license'],
                'usage_allowed' => true,
                'redistribution_allowed' => (bool) ($config['redistribution_allowed'] ?? false),
                'freshness_policy' => ['max_age_seconds' => (int) ($config['max_age_seconds'] ?? 0)],
            ],
        );

        return $config + ['id' => $source->id];
    }

    /** @return list<string> */
    public function keys(): array
    {
        $sources = config('market_data.sources', []);

        return is_array($sources) ? array_keys($sources) : [];
    }

    /**
     * Fontes elegiveis para sincronizacao automatica: licenca autorizada e endpoint configurado.
     * Fontes comerciais opt-in e sem endpoint ficam de fora ate decisao humana (ADR003/C07).
     *
     * @return list<string>
     */
    public function enabledKeys(): array
    {
        $sources = config('market_data.sources', []);
        if (! is_array($sources)) {
            return [];
        }

        $keys = [];
        foreach ($sources as $key => $config) {
            if (! is_array($config)
                || ($config['usage_allowed'] ?? false) !== true
                || ! is_string($config['endpoint'] ?? null)
                || $config['endpoint'] === '') {
                continue;
            }

            $keys[] = (string) $key;
        }

        return $keys;
    }

    public function markChecked(string $key, DateTimeImmutable $now): void
    {
        $source = $this->get($key);

        DataSource::query()
            ->where('id', $source['id'])
            ->update(['checked_at' => $now, 'updated_at' => now()]);
    }
}
