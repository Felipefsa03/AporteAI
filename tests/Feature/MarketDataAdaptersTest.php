<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\MarketData\Adapters\AdapterFactory;
use App\Domain\MarketData\Adapters\BcbFocusODataAdapter;
use App\Domain\MarketData\Adapters\BcbSgsJsonAdapter;
use App\Domain\MarketData\Adapters\CvmInfDiarioZipAdapter;
use App\Domain\MarketData\Adapters\PayloadLimits;
use App\Domain\MarketData\Adapters\TesouroCsvAdapter;
use App\Domain\MarketData\MarketDataException;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** DATA: parsing dos formatos reais das fontes oficiais e limites de payload. */
final class MarketDataAdaptersTest extends TestCase
{
    private PayloadLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limits = new PayloadLimits(20_971_520, 33_554_432, 4, 50_000, 100);
    }

    public function testBcbSgsJsonParsesBrazilianDateAndDecimal(): void
    {
        $adapter = new BcbSgsJsonAdapter('SELIC', 'd/m/Y', 'America/Sao_Paulo', $this->limits);
        $records = $adapter->decode($this->fixture('bcb_sgs_selic.json'));

        self::assertCount(1, $records);
        self::assertSame('SELIC', $records[0]->symbol);
        self::assertSame('17/11/2026', $records[0]->asOf->format('d/m/Y'));
        self::assertSame('-03:00', $records[0]->asOf->format('P'));
        self::assertSame(
            '2026-11-17T03:00:00+00:00',
            $records[0]->asOf->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        );
        self::assertSame('13.75', $records[0]->price);
    }

    public function testBcbFocusODataUsesVerifiedSelicExpectationsFeed(): void
    {
        $adapter = new BcbFocusODataAdapter('America/Sao_Paulo', $this->limits);
        $records = $adapter->decode($this->fixture('bcb_focus_odata.json'));

        self::assertCount(2, $records);
        self::assertSame('FOCUS_SELIC_R72028', $records[0]->symbol);
        self::assertSame('FOCUS_SELIC_R62028', $records[1]->symbol);
        self::assertSame('2026-11-13', $records[0]->asOf->format('Y-m-d'));
        self::assertSame('11.5', $records[0]->price);
    }

    public function testTesouroCsvUsesOfficialColumnsAndUnitPriceNotRate(): void
    {
        $adapter = new TesouroCsvAdapter('America/Sao_Paulo', $this->limits);
        $records = $adapter->decode($this->fixture('tesouro_precos_taxas.csv'));

        self::assertCount(2, $records);
        self::assertSame('Tesouro Selic 01/03/2031', $records[0]->symbol);
        self::assertSame('17/11/2026', $records[0]->asOf->format('d/m/Y'));
        // PU Venda Manha = 20212,35; Taxa Venda Manha = 0,12 (nao e preco).
        self::assertSame('20212.35', $records[0]->price);
        self::assertSame('858.97', $records[1]->price);
    }

    public function testCvmZipParsesRealSchemaAndRejectsUnexpectedEntries(): void
    {
        $adapter = new CvmInfDiarioZipAdapter('America/Sao_Paulo', $this->limits);
        $csv = $this->fixture('cvm_inf_diario.csv');
        $records = $adapter->decode($this->zip([$csv]));

        self::assertCount(2, $records);
        self::assertSame('00.017.024/0001-53', $records[0]->symbol);
        self::assertSame('2026-11-03', $records[0]->asOf->format('Y-m-d'));
        self::assertSame('45.3607553', $records[0]->price);

        $this->expectException(MarketDataException::class);
        $adapter->decode($this->zip([$csv], 'extra.txt'));
    }

    public function testCvmZipRejectsOversizeUncompressedEntry(): void
    {
        $tiny = new PayloadLimits(20_971_520, 300, 4, 50_000, 100);
        $adapter = new CvmInfDiarioZipAdapter('America/Sao_Paulo', $tiny);

        $this->expectException(MarketDataException::class);
        $adapter->decode($this->zip([$this->fixture('cvm_inf_diario.csv')]));
    }

    public function testParserRejectsMalformedOfficialFormat(): void
    {
        $adapter = new TesouroCsvAdapter('America/Sao_Paulo', $this->limits);

        $this->expectException(MarketDataException::class);
        $adapter->decode("symbol,date,price\nFAKE,2026-11-17,1\n");
    }

    public function testFactoryRefusesUnapprovedAdapter(): void
    {
        $factory = new AdapterFactory();

        $this->expectException(MarketDataException::class);
        $factory->make(['adapter' => 'generic', 'timezone' => 'UTC']);
    }

    /** @param list<string> $files */
    private function zip(array $files, ?string $extraName = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'data-adapter-');
        self::assertIsString($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $index => $contents) {
            $zip->addFromString('inf_diario_fi_202611_'.($index + 1).'.csv', $contents);
        }
        if ($extraName !== null) {
            $zip->addFromString($extraName, 'not a csv');
        }
        $zip->close();
        $payload = (string) file_get_contents($path);
        @unlink($path);

        return $payload;
    }

    private function fixture(string $name): string
    {
        $path = __DIR__.'/../Fixtures/market_data/'.$name;
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
