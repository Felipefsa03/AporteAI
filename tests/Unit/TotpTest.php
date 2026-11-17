<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AdminAuth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** Vetores RFC 6238 para HMAC-SHA1, truncados a 6 digitos. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @dataProvider rfc6238Vectors
     */
    public function testMatchesRfc6238Vectors(int $timestamp, string $expected): void
    {
        self::assertSame($expected, Totp::code(self::SECRET, $timestamp));
    }

    /** @return array<int, array{int, string}> */
    public static function rfc6238Vectors(): array
    {
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
            [20000000000, '353130'],
        ];
    }

    public function testAcceptsOneStepOfClockSkew(): void
    {
        $code = Totp::code(self::SECRET, 1_000);

        self::assertTrue(Totp::verify(self::SECRET, $code, 1_030));
        self::assertFalse(Totp::verify(self::SECRET, $code, 1_061));
    }

    public function testRejectsMalformedCodes(): void
    {
        self::assertFalse(Totp::verify(self::SECRET, '12345', 1_000));
        self::assertFalse(Totp::verify(self::SECRET, 'abcdef', 1_000));
    }

    public function testGeneratedSecretIsBase32(): void
    {
        $secret = Totp::generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertSame(6, strlen(Totp::code($secret)));
    }
}
