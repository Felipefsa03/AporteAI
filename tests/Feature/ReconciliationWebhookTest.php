<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\SubscriptionService;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\PaymentRecord;
use App\Models\Subscription;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReconciliationWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.gateway.webhook_secret', 'gateway-secret');
    }

    public function testSignedPaidWebhookReactivatesOnceAndIgnoresDuplicate(): void
    {
        [$subscription, $payment] = $this->overduePayment();
        $raw = json_encode([
            'id' => 'evt-paid-1',
            'status' => 'paid',
            'provider_ref' => $payment->provider_ref,
        ], JSON_THROW_ON_ERROR);
        $headers = fn (string $signature): array => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => $signature,
        ];

        $this->call('POST', '/api/v1/webhooks/payment-gateway', [], [], [], $headers('sha256=bad'), $raw)
            ->assertStatus(401);

        $signature = 'sha256='.hash_hmac('sha256', $raw, 'gateway-secret');
        $this->call('POST', '/api/v1/webhooks/payment-gateway', [], [], [], $headers($signature), $raw)
            ->assertOk()
            ->assertJsonPath('applied', true);
        self::assertSame('active', $subscription->fresh()->access_status);

        $this->call('POST', '/api/v1/webhooks/payment-gateway', [], [], [], $headers($signature), $raw)
            ->assertOk()
            ->assertJsonPath('applied', false);
        self::assertSame(1, AuditEvent::query()->where('action', 'billing.reconciled')->count());
    }

    public function testProcessingWebhookNeverGrantsAccess(): void
    {
        [$subscription, $payment] = $this->overduePayment();
        $raw = json_encode([
            'id' => 'evt-processing-1',
            'status' => 'processing',
            'provider_ref' => $payment->provider_ref,
        ], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'gateway-secret');

        $this->call('POST', '/api/v1/webhooks/payment-gateway', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => $signature,
        ], $raw)
            ->assertOk()
            ->assertJsonPath('applied', false);

        self::assertSame('suspended', $subscription->fresh()->access_status);
    }

    public function testManualSettlementRequiresAuditableEvidence(): void
    {
        [$subscription, $payment] = $this->overduePayment();
        $service = app(SubscriptionService::class);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        try {
            $service->recordManualSettlement($subscription, $payment, '   ', $now);
            self::fail('Evidência obrigatória não foi aplicada.');
        } catch (BillingException) {
            self::assertSame('suspended', $subscription->fresh()->access_status);
        }

        self::assertTrue($service->recordManualSettlement(
            $subscription,
            $payment,
            'file:///evidence/comprovante-auditado',
            $now,
        ));
        self::assertSame('active', $subscription->fresh()->access_status);
        self::assertTrue(AuditEvent::query()->where('action', 'billing.manual_settlement')->exists());
    }

    /** @return array{Subscription, PaymentRecord} */
    private function overduePayment(): array
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);
        $investor = Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Ana Souza',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'ana-'.Str::random(6).'@example.test',
        ]);
        $due = new DateTimeImmutable('2026-11-16T12:00:00+00:00');
        $subscription = Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => 'past_due',
            'access_status' => 'suspended',
            'started_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
            'due_at' => $due,
        ]);
        $payment = PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => 'provider-'.Str::random(8),
            'installment_number' => 1,
            'amount_cents' => 2990,
            'due_at' => $due,
            'status' => 'open',
        ]);

        return [$subscription, $payment];
    }
}
