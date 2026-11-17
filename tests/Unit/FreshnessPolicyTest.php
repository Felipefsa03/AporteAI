<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\MarketData\FreshnessPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FreshnessPolicyTest extends TestCase
{
    public function testAcceptsDataInsideTheWindow(): void
    {
        $policy = new FreshnessPolicy();
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        self::assertSame('valid', $policy->status(new DateTimeImmutable('2026-11-17T11:00:00+00:00'), 7200, $now));
        self::assertSame('valid', $policy->status(new DateTimeImmutable('2026-11-17T10:00:00+00:00'), 7200, $now));
    }

    public function testRejectsStaleAndFutureData(): void
    {
        $policy = new FreshnessPolicy();
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        self::assertSame('stale', $policy->status(new DateTimeImmutable('2026-11-17T09:59:59+00:00'), 7200, $now));
        self::assertSame('stale', $policy->status(new DateTimeImmutable('2026-11-17T13:00:00+00:00'), 7200, $now));
    }
}
