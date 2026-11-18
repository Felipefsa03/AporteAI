<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AporteSession;
use App\Models\Investor;
use App\Models\MarketQuote;
use App\Models\ReviewCase;
use Database\Seeders\PilotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PilotSeederTest extends TestCase
{
    use RefreshDatabase;

    public function testPilotSeedsFictitiousJourneyAndKeepsFailClosedDefaults(): void
    {
        self::assertFalse(config('pilot.public_recommendations'));
        self::assertFalse(config('pilot.advisory_authorized'));
        self::assertTrue(config('pilot.owner_dashboard_only'));

        $this->seed(PilotSeeder::class);

        self::assertSame(3, Investor::query()->count());
        self::assertSame(3, Investor::query()->where('phone_e164', 'like', '+5575992699%')->count());
        self::assertSame(2, MarketQuote::query()->count());
        self::assertSame(['CDB-LIQUIDEZ', 'TESOURO-IPCA'], \App\Models\Instrument::query()->orderBy('symbol')->pluck('symbol')->all());
        self::assertSame(1, AporteSession::query()->where('phase', 'COMPLETED')->count());
        self::assertSame(1, ReviewCase::query()->where('status', 'pending')->where('critical', true)->count());
        self::assertSame(0, Investor::query()->where('lifecycle_status', 'deletion_requested')->count());
    }

    public function testRollbackAndRunbookDocumentsExist(): void
    {
        foreach (['docs/pilot-runbook.md', 'docs/rollback.md', 'docs/readiness-report.md'] as $doc) {
            self::assertFileExists(base_path($doc));
        }
        self::assertStringContainsString('PILOT_ENABLED=false', file_get_contents(base_path('docs/rollback.md')));
    }
}
