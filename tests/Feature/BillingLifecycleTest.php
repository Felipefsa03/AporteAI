<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\AccessPolicy;
use App\Domain\Billing\BillingException;
use App\Domain\Billing\SubscriptionService;
use App\Models\AdminUser;
use App\Models\Investor;
use App\Models\PaymentRecord;
use App\Models\Subscription;
use App\Models\SubscriptionAccessEvent;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function testOverdueSuspendsAccessWithoutCancelingTheAnnualContract(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $subscription = $this->subscription($this->investor(), 'annual', new DateTimeImmutable('2026-11-16T12:00:00+00:00'));
        $this->service()->activate($subscription, new DateTimeImmutable('2026-11-01T12:00:00+00:00'));

        self::assertSame('suspended', $this->service()->markOverdue($subscription, $now));

        $subscription->refresh();
        self::assertSame('past_due', $subscription->status);
        self::assertSame('suspended', $subscription->access_status);
        self::assertNull($subscription->canceled_at);
        self::assertFalse($this->access()->allowsPaidServices($subscription));
    }

    public function testContractualGracePeriodDelaysSuspension(): void
    {
        $subscription = $this->subscription($this->investor(), 'monthly', new DateTimeImmutable('2026-11-16T12:00:00+00:00'), 3);
        $this->service()->activate($subscription, new DateTimeImmutable('2026-11-01T12:00:00+00:00'));

        self::assertSame(
            'unchanged',
            $this->service()->markOverdue($subscription, new DateTimeImmutable('2026-11-17T12:00:00+00:00')),
        );
        self::assertTrue($this->access()->allowsPaidServices($subscription->refresh()));
    }

    public function testPaidWebhookReactivatesOnlyOnce(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $subscription = $this->subscription($this->investor(), 'monthly', new DateTimeImmutable('2026-11-16T12:00:00+00:00'));
        $this->service()->activate($subscription, new DateTimeImmutable('2026-11-01T12:00:00+00:00'));
        $this->service()->markOverdue($subscription, $now);
        $payment = $this->payment($subscription, 'provider-once');

        self::assertTrue($this->service()->reconcile($subscription, $payment, 'webhook-once', 'paid', $now));
        self::assertTrue($this->access()->allowsPaidServices($subscription->refresh()));

        $events = SubscriptionAccessEvent::query()->where('subscription_id', $subscription->id)->count();
        self::assertFalse($this->service()->reconcile($subscription, $payment, 'webhook-once', 'paid', $now));
        self::assertSame($events, SubscriptionAccessEvent::query()->where('subscription_id', $subscription->id)->count());
    }

    public function testProcessingWebhookNeverGrantsAccess(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $subscription = $this->subscription($this->investor(), 'monthly', new DateTimeImmutable('2026-11-16T12:00:00+00:00'));
        $this->service()->activate($subscription, new DateTimeImmutable('2026-11-01T12:00:00+00:00'));
        $this->service()->markOverdue($subscription, $now);
        $payment = $this->payment($subscription, 'provider-processing');

        self::assertFalse($this->service()->reconcile($subscription, $payment, 'webhook-processing', 'processing', $now));
        self::assertFalse($this->access()->allowsPaidServices($subscription->refresh()));
    }

    public function testManualReactivationRequiresReconciledPayment(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $subscription = $this->subscription($this->investor(), 'monthly', new DateTimeImmutable('2026-11-16T12:00:00+00:00'));
        $this->service()->activate($subscription, new DateTimeImmutable('2026-11-01T12:00:00+00:00'));
        $this->service()->markOverdue($subscription, $now);

        $this->expectException(BillingException::class);
        $this->service()->reactivate($subscription, 'cliente disse que pagou', $now);
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    private function access(): AccessPolicy
    {
        return app(AccessPolicy::class);
    }

    private function investor(): Investor
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('senha-de-teste'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);

        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Investidor Teste',
            'phone_e164' => '+55119888'.Str::random(4),
            'email' => 'investidor-'.Str::random(6).'@example.test',
        ]);
    }

    private function subscription(Investor $investor, string $plan, DateTimeImmutable $dueAt, int $graceDays = 0): Subscription
    {
        return Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => $plan,
            'status' => 'pending_activation',
            'due_at' => $dueAt,
            'grace_days' => $graceDays,
        ]);
    }

    private function payment(Subscription $subscription, string $providerRef): PaymentRecord
    {
        return PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => $providerRef,
            'installment_number' => 1,
            'amount_cents' => 2990,
            'due_at' => $subscription->due_at,
            'status' => 'pending',
        ]);
    }
}
