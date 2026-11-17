<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

use RuntimeException;

/**
 * TOTP RFC 6238 com HMAC-SHA1, 6 digitos, passo de 30s e tolerancia de uma janela.
 * Sem dependencias externas para manter o minimo de superficie na conta unica do proprietario.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const STEP_SECONDS = 30;

    private const DIGITS = 6;

    private const WINDOW = 1;

    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16) {
            throw new RuntimeException('O segredo TOTP deve ter pelo menos 16 bytes.');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, self::STEP_SECONDS);
        $key = self::base32Decode($secret);

        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = (ord($hash[$offset]) & 0x7F) << 24
            | (ord($hash[$offset + 1]) & 0xFF) << 16
            | (ord($hash[$offset + 2]) & 0xFF) << 8
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1_000_000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $timestamp ??= time();

        for ($shift = -self::WINDOW; $shift <= self::WINDOW; $shift++) {
            $expected = self::code($secret, $timestamp + ($shift * self::STEP_SECONDS));
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private static function base32Decode(string $encoded): string
    {
        $normalized = strtoupper(rtrim($encoded, '='));
        $bits = '';

        foreach (str_split($normalized) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new RuntimeException('Segredo TOTP invalido.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $decoded .= chr(bindec($chunk));
        }

        return $decoded;
    }
}
