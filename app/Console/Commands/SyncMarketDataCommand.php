<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\MarketData\MarketDataException;
use App\Domain\MarketData\MarketDataIngestor;
use App\Domain\MarketData\SourceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SyncMarketDataCommand extends Command
{
    protected $signature = 'market:sync {source? : Chave da fonte em config/market_data.php}';

    protected $description = 'Ingere dados de mercado com licenca, proveniencia e validade';

    public function handle(SourceCatalog $catalog, MarketDataIngestor $ingestor): int
    {
        $requested = $this->argument('source');
        $keys = is_string($requested) && $requested !== '' ? [$requested] : $catalog->keys();
        $failures = 0;

        if ($keys === []) {
            $this->error('Nenhuma fonte de dados configurada.');

            return self::FAILURE;
        }

        foreach ($keys as $key) {
            $endpoint = config('market_data.sources.'.$key.'.endpoint');

            if (! is_string($endpoint) || $endpoint === '') {
                $this->error($key.': endpoint nao configurado; nenhum dado foi inventado.');
                $failures++;

                continue;
            }

            try {
                $payload = Http::timeout(15)->get($endpoint)->throw()->json();
            } catch (Throwable $exception) {
                $this->error($key.': falha na fonte - '.$exception->getMessage());
                $failures++;

                continue;
            }

            try {
                $stats = $ingestor->ingest($key, is_array($payload) ? $payload : [], now()->toDateTimeImmutable());
            } catch (MarketDataException $exception) {
                $this->error($key.': '.$exception->getMessage());
                $failures++;

                continue;
            }

            $this->info(sprintf(
                '%s: stored=%d stale=%d rejected=%d',
                $key,
                $stats['stored'],
                $stats['stale'],
                $stats['rejected'],
            ));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
