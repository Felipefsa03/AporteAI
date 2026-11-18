<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\MarketData\Adapters\AdapterFactory;
use App\Domain\MarketData\Adapters\PayloadLimits;
use App\Domain\MarketData\MarketDataException;
use App\Domain\MarketData\MarketDataIngestor;
use App\Domain\MarketData\SourceCatalog;
use App\Models\AuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class SyncMarketDataCommand extends Command
{
    protected $signature = 'market:sync {source? : Chave da fonte em config/market_data.php}';

    protected $description = 'Ingere feeds oficiais com licenca, proveniencia, limites e validade';

    public function handle(SourceCatalog $catalog, MarketDataIngestor $ingestor, AdapterFactory $adapters): int
    {
        $requested = $this->argument('source');
        $keys = is_string($requested) && $requested !== ''
            ? [$requested]
            : $catalog->enabledKeys();
        $failures = 0;

        if ($keys === []) {
            $this->error('Nenhuma fonte elegivel configurada; nenhum dado foi inventado.');

            return self::FAILURE;
        }

        foreach ($keys as $key) {
            if (! $this->sync($key, $ingestor, $adapters)) {
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sync(string $key, MarketDataIngestor $ingestor, AdapterFactory $adapters): bool
    {
        $source = config('market_data.sources.'.$key);
        if (! is_array($source)) {
            $this->error($key.': fonte desconhecida.');
            $this->auditFailure($key, 'source_unknown');

            return false;
        }

        $endpoint = $source['endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            $this->error($key.': endpoint oficial nao configurado; nenhum dado foi inventado.');
            $this->auditFailure($key, 'endpoint_missing');

            return false;
        }

        $limits = PayloadLimits::fromConfig();
        $timeout = max(1, (int) config('market_data.http.timeout_seconds', 30));
        $retries = max(0, (int) config('market_data.http.retries', 2));

        try {
            $response = Http::timeout($timeout)
                // throw=false: status HTTP nao e excecao de transporte; classificamos abaixo.
                ->retry($retries, 100, null, false)
                ->withOptions(['stream' => true])
                ->acceptJson()
                ->get($endpoint);
        } catch (Throwable) {
            $this->error($key.': falha de transporte na fonte oficial.');
            $this->auditFailure($key, 'transport_failed');

            return false;
        }

        if (! $response->successful()) {
            $this->error($key.': fonte oficial respondeu com status '.$response->status().'.');
            $this->auditFailure($key, 'http_status');

            return false;
        }

        $declared = $response->header('Content-Length');
        if (is_string($declared) && ctype_digit($declared) && (int) $declared > $limits->maxBytes) {
            $this->error($key.': Content-Length excede o limite antes do download.');
            $this->auditFailure($key, 'response_too_large');

            return false;
        }

        try {
            $body = $this->readBounded($response->toPsrResponse()->getBody(), $limits->maxBytes);
        } catch (MarketDataException) {
            $this->error($key.': download excedeu o limite de tamanho.');
            $this->auditFailure($key, 'response_too_large');

            return false;
        }

        try {
            $records = $adapters->make($source)->decode($body);
        } catch (Throwable) {
            // Nao registra mensagem de excecao: pode conter URL ou dados brutos.
            $this->error($key.': formato do feed oficial nao homologado.');
            $this->auditFailure($key, 'invalid_format');

            return false;
        }

        try {
            $stats = $ingestor->ingest($key, $records, now()->toDateTimeImmutable());
        } catch (MarketDataException $exception) {
            $this->error($key.': '.$exception->getMessage());
            $this->auditFailure($key, 'ingestion_refused');

            return false;
        }

        $this->info(sprintf(
            '%s: stored=%d stale=%d rejected=%d',
            $key,
            $stats['stored'],
            $stats['stale'],
            $stats['rejected'],
        ));

        return true;
    }

    /**
     * @param \Psr\Http\Message\StreamInterface $stream
     *
     * @throws MarketDataException
     */
    private function readBounded($stream, int $maxBytes): string
    {
        $body = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                throw new MarketDataException('Download excedeu o limite de tamanho.');
            }
            $body .= $chunk;
        }

        return $body;
    }

    /** Nao registra mensagem bruta: excecoes podem conter URLs com credenciais. */
    private function auditFailure(string $key, string $reason): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'market-sync-command',
            'action' => 'market.ingestion_failed',
            'subject_type' => 'data_source',
            'subject_id' => $key,
            'trace_id' => (string) Str::uuid(),
            'metadata' => ['reason' => $reason],
            'occurred_at' => now(),
        ]);
    }
}
