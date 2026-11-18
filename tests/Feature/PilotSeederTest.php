<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivationRequest;
use App\Models\AdvisoryContract;
use App\Models\AdminUser;
use App\Models\AporteSession;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\MarketQuote;
use App\Models\OnboardingSession;
use App\Models\PaymentRecord;
use App\Models\Portfolio;
use App\Models\PortfolioEvent;
use App\Models\ReviewCase;
use App\Models\Subscription;
use App\Models\SuitabilityAssessment;
use App\Models\WhatsAppOptin;
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

    public function testPilotSeederIsIdempotentWhenRunTwice(): void
    {
        $counts = static fn (): array => [
            'admin_users' => AdminUser::query()->count(),
            'investors' => Investor::query()->count(),
            'activation_requests' => ActivationRequest::query()->count(),
            'advisory_contracts' => AdvisoryContract::query()->count(),
            'onboarding_sessions' => OnboardingSession::query()->count(),
            'suitability_assessments' => SuitabilityAssessment::query()->count(),
            'whatsapp_optins' => WhatsAppOptin::query()->count(),
            'subscriptions' => Subscription::query()->count(),
            'payment_records' => PaymentRecord::query()->count(),
            'instruments' => Instrument::query()->count(),
            'market_quotes' => MarketQuote::query()->count(),
            'portfolios' => Portfolio::query()->count(),
            'portfolio_events' => PortfolioEvent::query()->count(),
            'aporte_sessions' => AporteSession::query()->count(),
            'review_cases' => ReviewCase::query()->count(),
        ];

        $expected = [
            'admin_users' => 1,
            'investors' => 3,
            'activation_requests' => 3,
            'advisory_contracts' => 3,
            'onboarding_sessions' => 3,
            'suitability_assessments' => 3,
            'whatsapp_optins' => 3,
            'subscriptions' => 3,
            'payment_records' => 4,
            'instruments' => 2,
            'market_quotes' => 2,
            'portfolios' => 1,
            'portfolio_events' => 1,
            'aporte_sessions' => 1,
            'review_cases' => 1,
        ];

        $this->seed(PilotSeeder::class);
        self::assertSame($expected, $counts());

        $this->seed(PilotSeeder::class);
        self::assertSame($expected, $counts());
    }

    public function testRollbackAndRunbookDocumentsExist(): void
    {
        foreach (['docs/pilot-runbook.md', 'docs/rollback.md', 'docs/readiness-report.md'] as $doc) {
            self::assertFileExists(base_path($doc));
        }
        self::assertStringContainsString('PILOT_ENABLED=false', file_get_contents(base_path('docs/rollback.md')));
    }
}
