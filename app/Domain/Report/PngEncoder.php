<?php

declare(strict_types=1);

namespace App\Domain\Report;

/** Gerador PNG mínimo em PHP puro: sem GD, sem ImageMagick, sem serviços externos. */
final class PngEncoder
{
    /** @param list<array{label: string, value: string, color: array{int, int, int}}> $legend */
    public function render(int $width, int $height, array $legend, array $metadata): string
    {
        $pixels = array_fill(0, $height, array_fill(0, $width, [250, 250, 248]));
        $total = 0;
        foreach ($legend as $item) {
            $total += max(0, (int) $item['value']);
        }

        $cx = 150;
        $cy = intdiv($height, 2);
        $radius = min(105, intdiv($height, 2) - 24);
        $start = -M_PI / 2;

        foreach ($legend as $item) {
            $share = $total > 0 ? max(0, (int) $item['value']) / $total : 0;
            $end = $start + ($share * 2 * M_PI);
            $color = $item['color'];

            for ($y = 0; $y < $height; ++$y) {
                for ($x = 0; $x < $width; ++$x) {
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

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
