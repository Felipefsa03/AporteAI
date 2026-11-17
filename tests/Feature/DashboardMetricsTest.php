<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Dashboard\DashboardMetricsService;
use App\Models\ActivationRequest;
use App\Models\AdminUser;
use App\Models\AporteSession;
use App\Models\Investor;
use App\Models\OnboardingSession;
use App\Models\PaymentRecord;
use App\Models\Portfolio;
use App\Models\PortfolioEvent;
use App\Models\Subscription;
use App\Models\WhatsAppOptin;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function testA10OwnerMetricsAreIsolatedPerInvestorAndRevenueIsNotPortfolioValue(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $a = $this->investor('Ana');
        $b = $this->investor('Bruno');
        $this->seedInvestorData($a, $now, 10000, 80000, true);
        $this->seedInvestorData($b, $now, 5000, 30000, false);

        $global = app(DashboardMetricsService::class)->snapshot();
        self::assertSame('owner', $global['scope']);
        self::assertSame(2, $global['investors']);
        self::assertSame(1, $global['funnel']['sales']);
        self::assertSame(1, $global['funnel']['opted_in']);
        self::assertSame(1, $global['funnel']['activated']);
        self::assertSame(1, $global['funnel']['onboarded']);
        self::assertSame(1, $global['funnel']['first_aporte']);
        self::assertSame(15000, $global['saas_revenue_cents']);
        self::assertSame(110000, $global['overseen_portfolio_cents']);
        self::assertNotSame($global['saas_revenue_cents'], $global['overseen_portfolio_cents']);

        $metrics = app(DashboardMetricsService::class);
        $clientA = $metrics->client($a->id);
        $clientB = $metrics->client($b->id);

        self::assertSame('investor', $clientA['scope']);
        self::assertSame(10000, $clientA['saas_revenue_cents']);
        self::assertSame(80000, $clientA['overseen_portfolio_cents']);
        self::assertSame(0, $clientB['funnel']['sales']);
        self::assertSame(5000, $clientB['saas_revenue_cents']);
        self::assertSame(30000, $clientB['overseen_portfolio_cents']);
    }

    private function seedInvestorData(Investor $investor, DateTimeImmutable $now, int $revenue, int $portfolioValue, bool $fullFunnel): void
    {
        if ($fullFunnel) {
            DB::table('sales_records')->insert([
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'closed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            WhatsAppOptin::query()->create([
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'idempotency_key' => 'optin-'.$investor->id,
                'phone_e164' => $investor->phone_e164,
                'evidence_uri' => 'https://example.test/evidence',
                'accepted_at' => $now,
            ]);
            ActivationRequest::query()->create([
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'requested_by_admin_id' => $investor->created_by_admin_id,
                'idempotency_key' => 'activation-'.$investor->id,
                'status' => 'delivered',
                'requested_at' => $now,
                'delivered_at' => $now,
            ]);
            OnboardingSession::query()->create([
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'phone_e164' => $investor->phone_e164,
                'status' => 'completed',
                'completed_at' => $now,
            ]);
            AporteSession::query()->create([
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'amount_cents' => $portfolioValue,
                'phase' => 'COMPLETED',
                'answers' => [],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $subscription = Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => 'active',
            'access_status' => 'active',
            'started_at' => $now,
        ]);
        PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => 'pay-'.$investor->id,
            'installment_number' => 1,
            'amount_cents' => $revenue,
            'due_at' => $now,
            'status' => 'paid',
            'paid_at' => $now,
        ]);

        $portfolio = Portfolio::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'name' => 'Carteira principal',
            'status' => 'active',
        ]);
        PortfolioEvent::query()->create([
            'id' => (string) Str::uuid(),
            'portfolio_id' => $portfolio->id,
            'investor_id' => $investor->id,
            'event_type' => 'ORDER_CONFIRMED',
            'external_id' => 'order-'.$investor->id,
            'amount_cents' => $portfolioValue,
            'affects_positions' => true,
            'metadata' => [],
            'occurred_at' => $now,
        ]);
    }

    private function investor(string $name): Investor
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);

        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => $name,
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => strtolower($name).'-'.Str::random(6).'@example.test',
        ]);
    }
}
