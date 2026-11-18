<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\MarketData\Adapters\AdapterFactory;
use App\Domain\MarketData\MarketDataException;
use App\Domain\MarketData\MarketDataIngestor;
use App\Domain\MarketData\SourceCatalog;
use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\MarketQuote;
use DateTimeImmutable;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;
use ZipArchive;

/** DATA: ingestao de feeds oficiais, proveniencia, limites e bloqueios fail-closed. */
final class MarketDataIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-17T12:00:00+00:00';

    public function testBcbFixtureKeepsBrazilianDateAndNeverBecomesInstrumentPrice(): void
    {
        $now = $this->now();
        $stats = $this->ingestFixture('bcb_selic', 'bcb_sgs_selic.json', $now);

        self::assertSame(['stored' => 1, 'stale' => 0, 'rejected' => 0], $stats);
        $quote = MarketQuote::query()->firstOrFail();
        self::assertSame('13.750000', $quote->price);
        self::assertSame('indicator', $quote->quality_status);
        // timestampTz guarda o instante canonico em UTC; a data/offset de Brasilia
        // (-03:00), equivalente a 03:00 UTC, fica preservada na proveniencia.
        self::assertSame('2026-11-17T03:00:00+00:00', $quote->as_of->format(DATE_ATOM));
        self::assertSame('2026-11-17T00:00:00-03:00', $quote->metadata['as_of_local']);
        self::assertSame('indicator', $quote->metadata['data_kind']);
        self::assertSame('percent', $quote->metadata['unit']);
        self::assertSame('America/Sao_Paulo', $quote->metadata['timezone']);
        self::assertSame('bcb', $quote->metadata['provider']);
        self::assertSame('sgs-432', $quote->metadata['dataset']);
        self::assertSame('public', $quote->metadata['license']);
        self::assertFalse($quote->metadata['redistribution_allowed']);
        self::assertSame('indicator', Instrument::query()->firstOrFail()->instrument_type);
        // Indicador nao pode alimentar a estrategia: apenas cotacoes approved sao elegiveis.
        self::assertNotSame('approved', $quote->quality_status);
    }

    public function testTesouroAndCvmFixturesBecomeApprovedPricesWithProvenance(): void
    {
        // Cada fonte tem sua propria janela de frescura: usamos um "now" logo apos
        // a ultima data real de cada fixture (Tesouro 17/11, CVM 04/11).
        $tesouro = $this->ingestFixture('tesouro_prices', 'tesouro_precos_taxas.csv', $this->now());
        $cvm = $this->ingestPayload('cvm_funds', $this->zipFixture('cvm_inf_diario.csv'), new DateTimeImmutable('2026-11-04T23:00:00+00:00'));

        self::assertSame(['stored' => 2, 'stale' => 0, 'rejected' => 0], $tesouro);
        self::assertSame(['stored' => 2, 'stale' => 0, 'rejected' => 0], $cvm);

        $tesouroInstrument = Instrument::query()->where('symbol', 'Tesouro Selic 01/03/2031')->firstOrFail();
        $tesouroQuote = MarketQuote::query()->where('instrument_id', $tesouroInstrument->id)->firstOrFail();
        self::assertSame('approved', $tesouroQuote->quality_status);
        self::assertSame('price', $tesouroQuote->metadata['data_kind']);
        self::assertSame('brl', $tesouroQuote->metadata['unit']);
        self::assertSame('20212.350000', $tesouroQuote->price);
        self::assertSame('treasury', $tesouroInstrument->instrument_type);
        self::assertSame('tesouro_csv', $tesouroQuote->metadata['adapter']);

        $cvmInstrument = Instrument::query()->where('symbol', '00.017.024/0001-53')->firstOrFail();
        $cvmQuote = MarketQuote::query()->where('instrument_id', $cvmInstrument->id)->firstOrFail();
        self::assertSame('approved', $cvmQuote->quality_status);
        self::assertSame('fund', $cvmInstrument->instrument_type);
        self::assertSame('45.360755', $cvmQuote->price);
        self::assertSame('inf-diario-fi', $cvmQuote->metadata['dataset']);
        self::assertFalse($cvmQuote->metadata['redistribution_allowed']);
    }

    public function testFutureDatedQuoteIsRejectedWithoutInventingPrice(): void
    {
        $stats = $this->ingestFixture('tesouro_prices', 'tesouro_future.csv', $this->now());

        self::assertSame(['stored' => 0, 'stale' => 0, 'rejected' => 1], $stats);
        self::assertSame(0, MarketQuote::query()->count());
        $rejection = AuditEvent::query()
            ->where('action', 'market.ingestion_rejected')
            ->firstOrFail();
        self::assertSame(1, $rejection->metadata['future']);
    }

    public function testStaleQuoteIsStoredButNotMarkedApproved(): void
    {
        $now = new DateTimeImmutable('2026-11-19T12:00:00+00:00');
        $stats = $this->ingestFixture('tesouro_prices', 'tesouro_precos_taxas.csv', $now);

        self::assertSame(['stored' => 0, 'stale' => 2, 'rejected' => 0], $stats);
        $quote = MarketQuote::query()->firstOrFail();
        self::assertSame('stale', $quote->quality_status);
        self::assertNotSame('approved', $quote->quality_status);
    }

    public function testSchemaWithoutDataKindUnitTimezoneOrLicenseEvidenceIsRefused(): void
    {
        foreach (['data_kind', 'unit', 'timezone', 'license_evidence', 'date_format'] as $field) {
            $original = config('market_data.sources.tesouro_prices.'.$field);
            config()->set('market_data.sources.tesouro_prices.'.$field, null);

            try {
                $this->ingestFixture('tesouro_prices', 'tesouro_precos_taxas.csv', $this->now());
                self::fail('Fonte sem '.$field.' nao pode ser ingerida.');
            } catch (MarketDataException) {
                config()->set('market_data.sources.tesouro_prices.'.$field, $original);
            }
        }

        self::assertSame(0, MarketQuote::query()->count());
    }

    public function testHttpFailureOversizeAndInvalidShapeDoNotInventData(): void
    {
        config()->set('market_data.limits.max_bytes', 200);

        Http::fake([
            'example.test/failure' => Http::response('erro', 503),
            'example.test/oversize' => Http::response(str_repeat('x', 500), 200),
            'example.test/shape' => Http::response('{"symbol":"NAO-LISTA"}', 200),
        ]);

        foreach ([
            'failure' => 'http_status',
            'oversize' => 'response_too_large',
            'shape' => 'invalid_format',
        ] as $endpoint => $expectedReason) {
            config()->set('market_data.sources.tesouro_prices.endpoint', 'https://example.test/'.$endpoint);
            $this->artisan('market:sync', ['source' => 'tesouro_prices'])
                ->assertExitCode(SymfonyCommand::FAILURE);
        }

        // occurred_at tem precisao de segundo: comparar o conjunto completo, nao "o ultimo".
        self::assertEqualsCanonicalizing(
            ['http_status', 'response_too_large', 'invalid_format'],
            $this->failureReasons(),
        );
        self::assertSame(0, MarketQuote::query()->count());
    }

    public function testSuccessfulHttpSyncStoresApprovedPriceFromRealCsv(): void
    {
        // O comando usa now(); fixamos o relogio para a data da fixture ser vigente.
        $this->travelTo($this->now());

        Http::fake([
            'example.test/prices' => Http::response($this->fixture('tesouro_precos_taxas.csv'), 200, ['Content-Type' => 'text/csv']),
        ]);
        config()->set('market_data.sources.tesouro_prices.endpoint', 'https://example.test/prices');

        $this->artisan('market:sync', ['source' => 'tesouro_prices'])
            ->assertExitCode(SymfonyCommand::SUCCESS);

        $quote = MarketQuote::query()->firstOrFail();
        self::assertSame('approved', $quote->quality_status);
        self::assertSame('public', $quote->metadata['license']);
        self::assertSame('20212.350000', $quote->price);
    }

    public function testCommercialProviderIsOptInAndSkippedByDefault(): void
    {
        $catalog = app(SourceCatalog::class);

        self::assertFalse((bool) config('market_data.sources.commercial_quotes.usage_allowed'));
        self::assertNull(config('market_data.sources.commercial_quotes.adapter'));
        self::assertNotContains('commercial_quotes', $catalog->enabledKeys());
        foreach (['bcb_selic', 'bcb_focus', 'tesouro_prices', 'cvm_funds'] as $key) {
            self::assertContains($key, $catalog->enabledKeys());
            self::assertFalse((bool) config('market_data.sources.'.$key.'.redistribution_allowed'));
        }
        $this->expectException(MarketDataException::class);
        $catalog->get('commercial_quotes');
    }

    public function testUnapprovedAdapterCannotBeConfigured(): void
    {
        config()->set('market_data.sources.tesouro_prices.adapter', 'generic');
        $factory = new AdapterFactory();

        $this->expectException(MarketDataException::class);
        $factory->make(config('market_data.sources.tesouro_prices'));
    }

    public function testSchedulerIsDisabledByDefaultAndOptInWhenEnabled(): void
    {
        self::assertFalse((bool) config('market_data.scheduler.enabled'));

        // withSchedule registra via Artisan::starting; disparar o bootstrap do console.
        $this->bootConsoleSchedule();
        self::assertSame([], $this->scheduledCommands());

        config()->set('market_data.scheduler.enabled', true);
        $this->bootConsoleSchedule();
        self::assertStringContainsString('market:sync', implode(' ', $this->scheduledCommands()));
    }

    /**
     * Uma nova aplicacao de console dispara os callbacks Artisan::starting,
     * incluindo o withSchedule de bootstrap/app.php.
     */
    private function bootConsoleSchedule(): void
    {
        $this->app->forgetInstance(Schedule::class);

        new ConsoleApplication(
            $this->app,
            $this->app->make(Dispatcher::class),
            $this->app->version(),
        );
    }

    /** @return list<string> */
    private function scheduledCommands(): array
    {
        $schedule = $this->app->make(Schedule::class);

        return array_values(array_filter(array_map(
            static fn ($event): string => (string) $event->command,
            $schedule->events(),
        )));
    }

    /** @return list<string> */
    private function failureReasons(): array
    {
        return AuditEvent::query()
            ->where('action', 'market.ingestion_failed')
            ->get()
            ->map(static fn (AuditEvent $event): string => (string) $event->metadata['reason'])
            ->all();
    }

    /** @return array{stored: int, stale: int, rejected: int} */
    private function ingestFixture(string $source, string $fixture, DateTimeImmutable $now): array
    {
        return $this->ingestPayload($source, $this->fixture($fixture), $now);
    }

    /** @return array{stored: int, stale: int, rejected: int} */
    private function ingestPayload(string $source, string $payload, DateTimeImmutable $now): array
    {
        $records = app(AdapterFactory::class)->make(config('market_data.sources.'.$source))
            ->decode($payload);

        return app(MarketDataIngestor::class)->ingest($source, $records, $now);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function fixture(string $name): string
    {
        $path = __DIR__.'/../Fixtures/market_data/'.$name;
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function zipFixture(string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'data-ingestion-');
        self::assertIsString($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('inf_diario_fi_202611.csv', $this->fixture($name));
        $zip->close();
        $payload = (string) file_get_contents($path);
        @unlink($path);

        return $payload;
    }
}
