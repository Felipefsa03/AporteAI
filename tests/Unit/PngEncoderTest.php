<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Report\BitmapFont;
use App\Domain\Report\PngEncoder;
use Tests\TestCase;
use Tests\Support\PngInspector;

final class PngEncoderTest extends TestCase
{
    public function testVisibleTextIsRenderedBeyondTextMetadata(): void
    {
        $view = [
            'title' => 'REALIZADO',
            'subtitle' => 'APORTEAI RELATORIO',
            'lines' => [
                'DATA 2026-11-17T15:30:00+00:00',
                'FONTE LED/DATA BASE 2026-11-17',
                'PATRIMONIO R$ 812,50',
                'APORTE LIQUIDO R$ 800,00',
                'RENDIMENTOS R$ 12,50',
                'COBERTURA COMPLETA',
            ],
            'legend_lines' => ['TESOURO QTD 0.800000 R$ 800,00'],
            'evolution' => [10000, 11000, 12000],
            'warnings' => ['Dados incompletos.'],
        ];

        $png = app(PngEncoder::class)->render(
            640,
            640,
            [['label' => 'TESOURO', 'value' => 80000, 'color' => [37, 99, 235]]],
            ['Title' => 'AporteAI REALIZADO', 'Payload' => '{}'],
            $view,
        );

        self::assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        self::assertStringContainsString('AporteAI REALIZADO', $png);

        $image = PngInspector::decode($png);
        self::assertSame([640, 640], $image->dimensions());
        self::assertTrue($image->hasText('REALIZADO', 18, 10, 3, true));
        self::assertTrue($image->hasText('APORTEAI RELATORIO', 18, 40, 2));
        self::assertTrue($image->hasText('DATA 2026-11-17T15:30:00+00:00', 18, 72, 2));
        self::assertTrue($image->hasText('FONTE LED/DATA BASE 2026-11-17', 18, 92, 2));
        self::assertTrue($image->hasText('PATRIMONIO R$ 812,50', 18, 112, 2));
        self::assertTrue($image->hasText('APORTE LIQUIDO R$ 800,00', 18, 132, 2));
        self::assertTrue($image->hasText('RENDIMENTOS R$ 12,50', 18, 152, 2));
        self::assertTrue($image->hasText('COBERTURA COMPLETA', 18, 172, 2));
        self::assertTrue($image->hasText('LEGENDA', 18, 206, 2));
        self::assertTrue($image->hasText('EVOLUCAO', 18, 450, 2));
        self::assertGreaterThan(50, $image->inkCount(18, 470, 600, 540));
        self::assertGreaterThan(20, $image->inkCount(460, 230, 590, 360));
    }

    public function testInsufficientHistoryDrawsVisibleWarning(): void
    {
        $png = app(PngEncoder::class)->render(640, 640, [], [], [
            'title' => 'REALIZADO',
            'lines' => [],
            'legend_lines' => [],
            'evolution' => [],
            'warnings' => ['Sem histórico suficiente para evolução.'],
        ]);

        $image = PngInspector::decode($png);
        self::assertTrue($image->hasText('EVOLUCAO', 18, 450, 2));
        self::assertTrue($image->hasText('SEM HISTORICO SUFICIENTE', 18, 474, 2));
        self::assertTrue($image->hasText('SEM HISTORICO SUFICIENTE', 18, 544, 2));
    }

    public function testFontNormalizationKeepsOnlySupportedGlyphs(): void
    {
        self::assertSame('PATRIMONIO R$ 1,50', BitmapFont::normalize('Patrimônio R$ 1,50'));
        self::assertSame('SEM DADOS ?', BitmapFont::normalize('sem dados ✓'));
    }
}
