<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\MarketData\MarketDataException;
use App\Domain\MarketData\MarketDataIngestor;
use App\Models\MarketQuote;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketDataIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function testIngestsWithProvenanceAndQuality(): void
    {
        $this->configureBcb();
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        $stats = $this->ingestor()->ingest('bcb_selic', [
            ['data' => '2026-11-17', 'valor' => '15,25'],
        ], $now);

        self::assertSame(['stored' => 1, 'stale' => 0, 'rejected' => 0], $stats);
        $quote = MarketQuote::query()->firstOrFail();
        self::assertSame('15.250000', $quote->price);
        self::assertSame('valid', $quote->quality_status);
        self::assertSame('public', $quote->metadata['license']);
        $this->assertDatabaseHas('audit_events', ['action' => 'market.ingested']);
    }

    public function testStaleQuoteIsStoredButNotMarkedValid(): void
    {
        $this->configureBcb();

        $stats = $this->ingestor()->ingest('bcb_selic', [
            ['data' => '2026-11-01', 'valor' => '15,25'],
        ], new DateTimeImmutable('2026-11-17T12:00:00+00:00'));

        self::assertSame(['stored' => 0, 'stale' => 1, 'rejected' => 0], $stats);
        $this->assertDatabaseHas('market_quotes', ['quality_status' => 'stale']);
    }

    public function testInvalidRecordIsRejectedWithoutInventingPrice(): void
    {
        $this->configureBcb();

        $stats = $this->ingestor()->ingest('bcb_selic', [
            ['data' => '2026-11-17', 'valor' => 'nao-e-numero'],
        ], new DateTimeImmutable('2026-11-17T12:00:00+00:00'));

        self::assertSame(['stored' => 0, 'stale' => 0, 'rejected' => 1], $stats);
        self::assertSame(0, MarketQuote::query()->count());
    }

    public function testIngestionIsIdempotent(): void
    {
        $this->configureBcb();
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $payload = [['data' => '2026-11-17', 'valor' => '15,25']];

        $this->ingestor()->ingest('bcb_selic', $payload, $now);
        $this->ingestor()->ingest('bcb_selic', $payload, $now);

        self::assertSame(1, MarketQuote::query()->count());
    }

    public function testLicensedSourceWithoutPermissionIsRefused(): void
    {
        config()->set('market_data.sources.commercial_quotes', [
            'provider' => 'commercial',
            'dataset' => 'quotes',
            'adapter' => 'generic',
            'symbol_field' => 'symbol',
            'date_field' => 'as_of',
            'price_field' => 'price',
            'date_format' => DATE_ATOM,
            'license' => 'licensed',
            'usage_allowed' => false,
            'redistribution_allowed' => false,
            'max_age_seconds' => 300,
            'endpoint' => '',
        ]);

        $this->expectException(MarketDataException::class);

        $this->ingestor()->ingest('commercial_quotes', [], new DateTimeImmutable('2026-11-17T12:00:00+00:00'));
    }

    private function ingestor(): MarketDataIngestor
    {
        return app(MarketDataIngestor::class);
    }

    private function configureBcb(): void
    {
        config()->set('market_data.sources.bcb_selic', [
            'provider' => 'bcb',
            'dataset' => 'selic',
            'adapter' => 'bcb',
            'symbol' => 'SELIC',
            'date_field' => 'data',
            'price_field' => 'valor',
            'date_format' => 'Y-m-d',
            'license' => 'public',
            'usage_allowed' => true,
            'redistribution_allowed' => true,
            'max_age_seconds' => 86_400,
            'endpoint' => 'https://example.test/selic',
        ]);
    }
}
