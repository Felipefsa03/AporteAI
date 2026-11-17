<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Billing\BillingCatalog;
use App\Domain\Billing\Cents;
use Tests\TestCase;

final class BillingCatalogTest extends TestCase
{
    public function testMonthlyAndAnnualAmountsInCents(): void
    {
        $catalog = new BillingCatalog();
        $monthly = $catalog->plan('monthly');
        $annual = $catalog->plan('annual');

        self::assertSame(2990, $monthly['amount_cents']);
        self::assertSame(1990, $annual['amount_cents']);
        self::assertSame(12, $annual['installments']);
        self::assertSame(23880, $annual['total_cents']);
        self::assertSame(1990 * 12, $annual['total_cents']);
    }

    public function testPlansAreOneProductWithTheSameFeatures(): void
    {
        $catalog = new BillingCatalog();
        $catalog->assertConsistent();

        self::assertCount(2, $catalog->plans());
        self::assertSame(['whatsapp_bot', 'suitability', 'portfolio_report', 'human_review'], $catalog->features());
    }

    public function testCentsAreExact(): void
    {
        self::assertSame(2990, Cents::fromBrl('R$ 29,90'));
        self::assertSame(1990, Cents::fromBrl('19,90'));
        self::assertSame('238,80', Cents::format(23880));
    }
}
