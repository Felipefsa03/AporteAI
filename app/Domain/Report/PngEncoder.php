<?php

declare(strict_types=1);

namespace App\Domain\Report;

/** Gerador PNG mínimo em PHP puro: sem GD, sem ImageMagick, sem serviços externos. */
final class PngEncoder
{
    private const BACKGROUND = [250, 250, 248];
    private const HEADER = [15, 23, 42];
    private const INK = [15, 23, 42];
    private const MUTED = [71, 85, 105];
    private const LINE = [203, 213, 225];
    private const WARNING = [180, 83, 9];
    private const SERIES = [37, 99, 235];
    private const WHITE = [255, 255, 255];

    /** @param list<array{label: string, value: string, color: array{int, int, int}}> $legend */
    public function render(int $width, int $height, array $legend, array $metadata, array $view = []): string
    {
        $pixels = array_fill(0, $height, array_fill(0, $width, self::BACKGROUND));

        $title = BitmapFont::normalize((string) ($view['title'] ?? ($metadata['Title'] ?? 'RELATORIO')));
        $subtitle = BitmapFont::normalize((string) ($view['subtitle'] ?? 'APORTEAI RELATORIO'));
        $lines = $this->strings($view['lines'] ?? []);
        $legendLines = $this->strings($view['legend_lines'] ?? []);
        $warnings = $this->strings($view['warnings'] ?? []);
        $evolution = $this->integers($view['evolution'] ?? []);

        $this->fillRect($pixels, 0, 0, $width, 58, self::HEADER);
        $this->drawText($pixels, $title, 18, 10, 3, self::WHITE);
        $this->drawText($pixels, $subtitle, 18, 40, 2, [191, 219, 254]);

        $y = 72;
        foreach ($lines as $line) {
            foreach (explode("\n", wordwrap($line, max(20, intdiv($width - 36, 12)), "\n", true)) as $wrapped) {
                if ($y + 14 >= $height) {
                    break 2;
                }
                $this->drawText($pixels, $wrapped, 18, $y, 2, self::INK);
                $y += 20;
            }
        }

        $this->fillRect($pixels, 18, $y + 2, $width - 36, 1, self::LINE);
        $y += 14;

        $this->drawPie($pixels, $legend, min($width - 24, $width - 100), min($y + 40, $height - 240), 62);
        $this->drawText($pixels, 'LEGENDA', 18, $y, 2, self::MUTED);
        $y += 22;
        foreach ($legendLines as $line) {
            if ($y + 14 >= $height - 150) {
                break;
            }
            $this->drawText($pixels, mb_substr($line, 0, 46), 18, $y, 2, self::INK);
            $y += 18;
        }

        $evolutionY = max($y + 12, $height - 190);
        $this->drawText($pixels, 'EVOLUCAO', 18, $evolutionY, 2, self::MUTED);
        if (count($evolution) >= 2) {
            $this->drawEvolution($pixels, $evolution, 18, $evolutionY + 24, $width - 36, 62);
        } else {
            $this->drawText($pixels, 'SEM HISTORICO SUFICIENTE', 18, $evolutionY + 24, 2, self::WARNING);
        }

        $warningY = $height - 96;
        $this->fillRect($pixels, 18, $warningY - 10, $width - 36, 1, self::LINE);
        foreach ($warnings as $index => $warning) {
            foreach (explode("\n", wordwrap($warning, max(20, intdiv($width - 36, 12)), "\n", true)) as $wrapped) {
                $this->drawText($pixels, $wrapped, 18, $warningY + ($index * 18), 2, self::WARNING);
                $warningY += 18;
            }
        }

        $raw = '';
        foreach ($pixels as $row) {
            $raw .= "\x00";
            foreach ($row as $pixel) {
                $raw .= chr($pixel[0]).chr($pixel[1]).chr($pixel[2]);
            }
        }

        $png = $this->chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0));
        foreach ($metadata as $key => $value) {
            $png .= $this->chunk('tEXt', (string) $key."\0".(string) $value);
        }
        $png .= $this->chunk('IDAT', gzcompress($raw, 9));
        $png .= $this->chunk('IEND', '');

        return "\x89PNG\r\n\x1a\n".$png;
    }

    /** @param list<mixed> $values @return list<string> */
    private function strings(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): string => BitmapFont::normalize((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /** @param list<mixed> $values @return list<int> */
    private function integers(array $values): array
    {
        return array_values(array_filter(
            array_map('intval', $values),
            static fn (int $value): bool => $value !== 0,
        ));
    }

    /** @param array<int, array<int, array{int, int, int}>> $pixels */
    private function fillRect(array &$pixels, int $x, int $y, int $width, int $height, array $color): void
    {
        for ($row = max(0, $y); $row < min(count($pixels), $y + $height); ++$row) {
            for ($column = max(0, $x); $column < min(count($pixels[$row]), $x + $width); ++$column) {
                $pixels[$row][$column] = $color;
            }
        }
    }

    /** @param array<int, array<int, array{int, int, int}>> $pixels */
    private function drawText(array &$pixels, string $text, int $x, int $y, int $scale, array $color): void
    {
        $glyphs = BitmapFont::glyphs();
        $offset = 0;
        foreach (str_split(BitmapFont::normalize($text)) as $character) {
            $glyph = $glyphs[$character] ?? $glyphs['?'];
            foreach ($glyph as $row => $pattern) {
                for ($column = 0; $column < 5; ++$column) {
                    if ($pattern[$column] !== '1') {
                        continue;
                    }
                    $this->fillRect(
                        $pixels,
                        $x + $offset + ($column * $scale),
                        $y + ($row * $scale),
                        $scale,
                        $scale,
                        $color,
                    );
                }
            }
            $offset += 6 * $scale;
        }
    }

    /** @param array<int, array<int, array{int, int, int}>> $pixels */
    private function drawPie(array &$pixels, array $legend, int $cx, int $cy, int $radius): void
    {
        $total = 0;
        foreach ($legend as $item) {
            $total += max(0, (int) $item['value']);
        }

        $start = -M_PI / 2;
        foreach ($legend as $item) {
            $share = $total > 0 ? max(0, (int) $item['value']) / $total : 0;
            $end = $start + ($share * 2 * M_PI);
            $color = $item['color'];

            for ($y = $cy - $radius; $y <= $cy + $radius; ++$y) {
                for ($x = $cx - $radius; $x <= $cx + $radius; ++$x) {
                    if ($x < 0 || $y < 0 || $y >= count($pixels) || $x >= count($pixels[$y])) {
                        continue;
                    }
                    $dx = $x - $cx;
                    $dy = $y - $cy;
                    if ($dx * $dx + $dy * $dy > $radius * $radius) {
                        continue;
                    }
                    $angle = atan2($dy, $dx);
                    if ($angle < $start) {
                        $angle += 2 * M_PI;
                    }
                    if ($angle >= $start && $angle < $end) {
                        $pixels[$y][$x] = $color;
                    }
                }
            }
            $start = $end;
        }
    }

    /** @param array<int, array<int, array{int, int, int}>> $pixels @param list<int> $points */
    private function drawEvolution(array &$pixels, array $points, int $x, int $y, int $width, int $height): void
    {
        $points = array_slice($points, -12);
        $minimum = min($points);
        $maximum = max($points);
        $span = max(1, $maximum - $minimum);
        $count = count($points);
        $coordinates = [];

        foreach ($points as $index => $point) {
            $coordinates[] = [
                $x + ($count === 1 ? 0 : intdiv($index * $width, $count - 1)),
                $y + $height - intdiv(($point - $minimum) * $height, $span),
            ];
        }

        for ($index = 1; $index < count($coordinates); ++$index) {
            $this->drawLine($pixels, $coordinates[$index - 1], $coordinates[$index], self::SERIES);
        }
        foreach ($coordinates as $coordinate) {
            $this->fillRect($pixels, $coordinate[0] - 2, $coordinate[1] - 2, 5, 5, self::SERIES);
        }
    }

    /** @param array<int, array<int, array{int, int, int}>> $pixels @param array{int, int} $from @param array{int, int} $to */
    private function drawLine(array &$pixels, array $from, array $to, array $color): void
    {
        [$x0, $y0] = $from;
        [$x1, $y1] = $to;
        $dx = abs($x1 - $x0);
        $dy = abs($y1 - $y0);
        $stepX = $x0 < $x1 ? 1 : -1;
        $stepY = $y0 < $y1 ? 1 : -1;
        $error = $dx - $dy;

        while (true) {
            if ($x0 >= 0 && $y0 >= 0 && $y0 < count($pixels) && $x0 < count($pixels[$y0])) {
                $pixels[$y0][$x0] = $color;
            }
            if ($x0 === $x1 && $y0 === $y1) {
                break;
            }
            $double = 2 * $error;
            if ($double > -$dy) {
                $error -= $dy;
                $x0 += $stepX;
            }
            if ($double < $dx) {
                $error += $dx;
                $y0 += $stepY;
            }
        }
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
