<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Models\ActivationRequest;
use App\Models\AporteSession;
use App\Models\Investor;
use App\Models\OnboardingSession;
use App\Models\PaymentRecord;
use App\Models\PortfolioEvent;
use App\Models\Subscription;
use App\Models\WhatsAppOptin;
use Illuminate\Support\Facades\DB;

/** A10/RB-013: métricas do proprietário, com isolamento por investidor e receita SaaS separada do patrimônio. */
final class DashboardMetricsService
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $investors = Investor::query()->whereNull('deleted_at')->count();

        return [
            'scope' => 'owner',
            'investors' => $investors,
            'funnel' => [
                'sales' => DB::table('sales_records')->count(),
                'opted_in' => WhatsAppOptin::query()->whereNull('revoked_at')->count(),
                'activated' => ActivationRequest::query()->whereNotNull('delivered_at')->count(),
                'onboarded' => OnboardingSession::query()->where('status', 'completed')->count(),
                'first_aporte' => AporteSession::query()->where('phase', 'COMPLETED')->count(),
            ],
            'retention' => [
                'active_subscriptions' => Subscription::query()->where('access_status', 'active')->count(),
                'suspended_subscriptions' => Subscription::query()->where('access_status', 'suspended_nonpayment')->count(),
            ],
            'saas_revenue_cents' => $this->revenueCents(),
            'overseen_portfolio_cents' => $this->portfolioCents(),
            'revenue_vs_portfolio' => 'receita SaaS e patrimônio acompanhado são métricas distintas',
        ];
    }

    /** @return array<string, mixed> */
    public function client(string $investorId): array
    {
        $investor = Investor::query()->findOrFail($investorId);

        return [
            'scope' => 'investor',
            'investor_id' => $investor->id,
            'funnel' => [
                'sales' => DB::table('sales_records')->where('investor_id', $investor->id)->count(),
                'opted_in' => WhatsAppOptin::query()->where('investor_id', $investor->id)->whereNull('revoked_at')->count(),
                'activated' => ActivationRequest::query()->where('investor_id', $investor->id)->whereNotNull('delivered_at')->count(),
                'onboarded' => OnboardingSession::query()->where('investor_id', $investor->id)->where('status', 'completed')->count(),
                'first_aporte' => AporteSession::query()->where('investor_id', $investor->id)->where('phase', 'COMPLETED')->count(),
            ],
            'saas_revenue_cents' => $this->revenueCents($investor->id),
            'overseen_portfolio_cents' => $this->portfolioCents($investor->id),
        ];
    }

    private function revenueCents(?string $investorId = null): int
    {
        $subscriptionIds = Subscription::query()
            ->when($investorId !== null, fn ($query) => $query->where('investor_id', $investorId))
            ->pluck('id');

        return (int) PaymentRecord::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->where('status', 'paid')
            ->sum('amount_cents');
    }

    private function portfolioCents(?string $investorId = null): int
    {
        return (int) PortfolioEvent::query()
            ->when($investorId !== null, fn ($query) => $query->where('investor_id', $investorId))
            ->where('affects_positions', true)
            ->get()
            ->sum(static fn (PortfolioEvent $event): int =>
                $event->event_type === 'REVERSAL' ? -$event->amount_cents : $event->amount_cents);
    }
}
