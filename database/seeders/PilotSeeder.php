<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\MarketData\SourceCatalog;
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
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** PIL: somente dados fictícios; nunca usar em produção. */
final class PilotSeeder extends Seeder
{
    public function run(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $admin = $this->owner($now);
        $prefix = (string) config('pilot.fictitious_phone_prefix', '+5575992699');

        $investors = [];
        foreach (['Ana', 'Bruno', 'Carla'] as $index => $name) {
            $investors[$name] = $this->investor($admin, $name, $prefix.sprintf('%04d', $index + 1), $now);
            $this->salesRecord($investors[$name], $now);
            $this->consentAndOnboarding($investors[$name], $now);
        }

        $this->marketData($now);

        $this->subscription($investors['Ana'], 'active', 'active', 'paid', 2990, $now);
        $suspended = $this->subscription($investors['Bruno'], 'past_due', 'suspended', 'open', 2990, $now);
        $this->payment($suspended, 2990, 'open', 2, $now);
        $this->subscription($investors['Carla'], 'active', 'grace', 'paid', 9900, $now);

        $portfolio = Portfolio::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investors['Ana']->id,
            'name' => 'Carteira principal',
            'status' => 'active',
        ]);
        $instrument = Instrument::query()->where('symbol', 'TESOURO-IPCA')->firstOrFail();
        PortfolioEvent::query()->create([
            'id' => (string) Str::uuid(),
            'portfolio_id' => $portfolio->id,
            'investor_id' => $investors['Ana']->id,
            'event_type' => 'ORDER_CONFIRMED',
            'external_id' => 'pilot-order-ana-1',
            'instrument_id' => $instrument->id,
            'amount_cents' => 80000,
            'affects_positions' => true,
            'metadata' => ['source' => 'pilot'],
            'occurred_at' => $now,
        ]);

        AporteSession::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investors['Ana']->id,
            'amount_cents' => 80000,
            'phase' => 'COMPLETED',
            'answers' => ['objective' => 'renda', 'horizon' => 'longo', 'tolerance' => 'moderada', 'financial_changes' => 'nao'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        ReviewCase::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investors['Bruno']->id,
            'subject_type' => 'billing_gate',
            'subject_id' => (string) Str::uuid(),
            'reason' => 'pilot_suspended_nonpayment',
            'status' => 'pending',
            'critical' => true,
            'snapshot' => ['access_status' => 'suspended'],
            'sources' => [],
            'rules' => ['rule_version' => 'pilot-2026.11-v1'],
            'created_at' => $now,
        ]);
    }

    private function owner(DateTimeImmutable $now): AdminUser
    {
        $configuredHash = env('PILOT_OWNER_PASSWORD_HASH');

        return AdminUser::query()->firstOrCreate(
            ['email' => 'pilot-owner@example.test'],
            [
                'id' => (string) Str::uuid(),
                'role' => 'owner',
                'password_hash' => $configuredHash ?: Hash::make(Str::random(40)),
                'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
                'mfa_enabled' => false,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function investor(AdminUser $admin, string $name, string $phone, DateTimeImmutable $now): Investor
    {
        return Investor::query()->firstOrCreate(
            ['phone_e164' => $phone],
            [
                'id' => (string) Str::uuid(),
                'created_by_admin_id' => $admin->id,
                'full_name' => 'Piloto '.$name,
                'email' => strtolower($name).'.piloto@example.test',
                'profession' => 'Dados fictícios',
                'lifecycle_status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function salesRecord(Investor $investor, DateTimeImmutable $now): void
    {
        if (DB::table('sales_records')->where('investor_id', $investor->id)->exists()) {
            return;
        }
        DB::table('sales_records')->insert([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'closed_at' => $now,
            'salesperson_note' => 'Dado fictício do piloto técnico.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function consentAndOnboarding(Investor $investor, DateTimeImmutable $now): void
    {
        WhatsAppOptin::query()->firstOrCreate(
            ['idempotency_key' => 'pilot-optin-'.$investor->id],
            [
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'phone_e164' => $investor->phone_e164,
                'channel' => 'whatsapp',
                'evidence_uri' => 'file:///pilot/fictitious-consent',
                'accepted_at' => $now,
            ],
        );
        ActivationRequest::query()->firstOrCreate(
            ['idempotency_key' => 'pilot-activation-'.$investor->id],
            [
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'requested_by_admin_id' => $investor->created_by_admin_id,
                'status' => 'delivered',
                'requested_at' => $now,
                'delivered_at' => $now,
            ],
        );
        OnboardingSession::query()->firstOrCreate(
            ['phone_e164' => $investor->phone_e164, 'status' => 'completed'],
            [
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'completed_at' => $now,
            ],
        );
        AdvisoryContract::query()->firstOrCreate(
            ['investor_id' => $investor->id, 'version' => 1],
            [
                'id' => (string) Str::uuid(),
                'status' => 'signed',
                'signed_at' => $now,
                'artifact_uri' => 'file:///pilot/contract-v1',
                'privacy_notice_uri' => 'file:///pilot/privacy-v1',
            ],
        );
        SuitabilityAssessment::query()->firstOrCreate(
            ['investor_id' => $investor->id, 'version' => 1],
            [
                'id' => (string) Str::uuid(),
                'answers' => ['objective' => 'renda'],
                'classification' => 'moderate',
                'status' => 'valid',
                'assessed_at' => $now,
                'expires_at' => $now->modify('+180 days'),
            ],
        );
    }

    private function marketData(DateTimeImmutable $now): void
    {
        $catalog = app(SourceCatalog::class);
        foreach (['TESOURO-IPCA' => 'tesouro_prices', 'CDB-LIQUIDEZ' => 'bcb_selic'] as $symbol => $sourceKey) {
            $source = $catalog->get($sourceKey);
            $instrument = Instrument::query()->firstOrCreate(
                ['symbol' => $symbol],
                [
                    'id' => (string) Str::uuid(),
                    'instrument_type' => 'renda_fixa',
                    'issuer' => 'Emissor fictício',
                    'status' => 'active',
                ],
            );
            MarketQuote::query()->firstOrCreate(
                ['instrument_id' => $instrument->id, 'source_id' => $source['id'], 'as_of' => $now],
                [
                    'id' => (string) Str::uuid(),
                    'price' => '100.0000',
                    'quality_status' => 'approved',
                    'captured_at' => $now,
                    'metadata' => ['pilot' => true],
                ],
            );
        }
    }

    private function subscription(
        Investor $investor,
        string $status,
        string $access,
        string $paymentStatus,
        int $amount,
        DateTimeImmutable $now,
    ): Subscription {
        $subscription = Subscription::query()->firstOrCreate(
            ['investor_id' => $investor->id, 'plan_code' => 'pilot-monthly'],
            [
                'id' => (string) Str::uuid(),
                'status' => $status,
                'access_status' => $access,
                'started_at' => $now->modify('-30 days'),
                'due_at' => $now->modify('-1 day'),
            ],
        );
        $this->payment($subscription, $amount, $paymentStatus, 1, $now);

        return $subscription;
    }

    private function payment(
        Subscription $subscription,
        int $amount,
        string $status,
        int $installment,
        DateTimeImmutable $now,
    ): PaymentRecord {
        return PaymentRecord::query()->firstOrCreate(
            ['subscription_id' => $subscription->id, 'installment_number' => $installment],
            [
                'id' => (string) Str::uuid(),
                'provider_ref' => 'pilot-payment-'.$subscription->id.'-'.$installment,
                'amount_cents' => $amount,
                'due_at' => $now->modify('-1 day'),
                'status' => $status,
                'paid_at' => $status === 'paid' ? $now->modify('-1 day') : null,
            ],
        );
    }
}
