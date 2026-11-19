<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Report\BitmapFont;
use RuntimeException;

/** Decodificador mínimo dos PNGs gerados em PHP puro (filtro 0, RGB de 8 bits). */
final class PngInspector
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** @var array<int, array<int, array{int, int, int}>> */
    private array $pixels;

    private int $width;

    private int $height;

    private function __construct(string $png)
    {
        if (substr($png, 0, 8) !== self::SIGNATURE) {
            throw new RuntimeException('Assinatura PNG inválida.');
        }

        $offset = 8;
        $idat = '';
        while ($offset + 8 <= strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);
            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight', substr($data, 0, 8));
                $this->width = $header['width'];
                $this->height = $header['height'];
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
            $offset += 12 + $length;
        }

        $raw = gzuncompress($idat);
        if ($raw === false) {
            throw new RuntimeException('IDAT inválido.');
        }

        $this->pixels = [];
        $position = 0;
        for ($y = 0; $y < $this->height; ++$y) {
            if ($raw[$position] !== "\x00") {
                throw new RuntimeException('Filtro de linha não suportado.');
            }
            ++$position;
            for ($x = 0; $x < $this->width; ++$x) {
                $this->pixels[$y][$x] = [
                    ord($raw[$position]),
                    ord($raw[$position + 1]),
                    ord($raw[$position + 2]),
                ];
                $position += 3;
            }
        }
    }

    public static function decode(string $png): self
    {
        return new self($png);
    }

    /** @return array{int, int} */
    public function dimensions(): array
    {
        return [$this->width, $this->height];
    }

    public function inkCount(int $x1, int $y1, int $x2, int $y2): int
    {
        $count = 0;
        for ($y = max(0, $y1); $y <= min($this->height - 1, $y2); ++$y) {
            for ($x = max(0, $x1); $x <= min($this->width - 1, $x2); ++$x) {
                [$red, $green, $blue] = $this->pixels[$y][$x];
                if ($red + $green + $blue < 730) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    public function hasText(string $text, int $x, int $y, int $scale, bool $light = false): bool
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
                    $px = $x + $offset + ($column * $scale);
                    $py = $y + ($row * $scale);
                    if ($px >= $this->width || $py >= $this->height) {
                        return false;
                    }
                    [$red, $green, $blue] = $this->pixels[$py][$px];
                    $sum = $red + $green + $blue;
                    if ($light ? $sum < 600 : $sum >= 730) {
                        return false;
                    }
                }
            }
            $offset += 6 * $scale;
        }

        return true;
    }
}
