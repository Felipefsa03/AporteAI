<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Conversation\ConversationOrchestrator;
use App\Domain\Conversation\LlmGateway;
use App\Domain\Dashboard\DashboardMetricsService;
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

/** TST/PRD §16: homologação dos fluxos P0 em ambiente de teste determinístico. */
final class P0HomologationTest extends TestCase
{
    use RefreshDatabase;

    public function testA10OnlyOwnerAccessesDashboardAndRegistriesDoNotCross(): void
    {
        $this->getJson('/api/v1/admin/investors')->assertStatus(401);
        $a = $this->investor('A');
        $b = $this->investor('B');
        $subscriptionA = $this->subscription($a, 'active', 'active');
        $this->subscription($b, 'past_due', 'suspended');
        $this->payment($subscriptionA, 2990);

        $metrics = app(DashboardMetricsService::class);
        self::assertSame('owner', $metrics->snapshot()['scope']);
        self::assertSame(2990, $metrics->client($a->id)['saas_revenue_cents']);
        self::assertSame(0, $metrics->client($b->id)['saas_revenue_cents']);
        self::assertSame($b->id, $metrics->client($b->id)['investor_id']);
        self::assertNotSame($a->id, $metrics->client($b->id)['investor_id']);
    }

    public function testA13AndA14OverdueOnlyShowsNoticeAndRightsRemainAvailable(): void
    {
        $llm = $this->fakeLlm();
        $investor = $this->investor('C');
        $subscription = $this->subscription($investor, 'past_due', 'suspended');
        $this->payment($subscription);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        $blocked = app(ConversationOrchestrator::class)->handle($investor, 'Fazer aporte R$ 800', $now);
        self::assertSame('blocked', $blocked['action']);
        self::assertFalse($blocked['allow_llm']);
        self::assertSame(0, $blocked['llm_calls']);
        self::assertStringContainsString('venceu em 16/11/2026', $blocked['reply']);
        self::assertStringNotContainsString('RESPOSTA_PAGA', $blocked['reply']);

        foreach (['Meu plano', 'Já paguei', 'Regularizar pagamento', 'Falar com atendimento', 'Cancelar assinatura', 'Meus dados'] as $command) {
            $result = app(ConversationOrchestrator::class)->handle($investor, $command, $now);
            self::assertSame('command', $result['action'], $command);
            self::assertSame(0, $result['llm_calls'], $command);
        }
        self::assertSame(0, $llm->calls);
        self::assertSame('suspended', $subscription->fresh()->access_status);
    }

    public function testA15SignedPaidWebhookReactivatesOnceAndProcessingIsIgnored(): void
    {
        config()->set('billing.gateway.webhook_secret', 'gateway-secret');
        $investor = $this->investor('D');
        $subscription = $this->subscription($investor, 'past_due', 'suspended');
        $payment = $this->payment($subscription, 2990, 'open');

        $processing = $this->signedWebhook([
            'id' => 'evt-processing',
            'status' => 'processing',
            'provider_ref' => $payment->provider_ref,
        ]);
        $processing->assertOk()->assertJsonPath('applied', false);
        self::assertSame('suspended', $subscription->fresh()->access_status);

        $payload = [
            'id' => 'evt-paid',
            'status' => 'paid',
            'provider_ref' => $payment->provider_ref,
        ];
        $this->signedWebhook($payload)->assertOk()->assertJsonPath('applied', true);
        $this->signedWebhook($payload)->assertOk()->assertJsonPath('applied', false);
        self::assertSame('active', $subscription->fresh()->access_status);
        self::assertSame(1, AuditEvent::query()->where('action', 'billing.reconciled')->count());
    }

    public function testA16OverdueInstallmentIsIdentifiedWithoutAutomaticCancellation(): void
    {
        $investor = $this->investor('E');
        $subscription = $this->subscription($investor, 'past_due', 'suspended');
        $payment = $this->payment($subscription, 2990, 'open', 2);
        $result = app(ConversationOrchestrator::class)->handle(
            $investor,
            'Fazer aporte R$ 800',
            new DateTimeImmutable('2026-11-17T12:00:00+00:00'),
        );

        self::assertSame('blocked', $result['action']);
        self::assertSame('payment_pending_after_grace', $result['reason']);
        self::assertSame(2, $payment->fresh()->installment_number);
        self::assertSame('past_due', $subscription->fresh()->status);
        self::assertNull($subscription->fresh()->canceled_at);
    }

    private function signedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'gateway-secret');

        return $this->call('POST', '/api/v1/webhooks/payment-gateway', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => $signature,
        ], $raw);
    }

    private function fakeLlm(): object
    {
        $llm = new class implements LlmGateway
        {
            public int $calls = 0;

            public function complete(string $prompt): string
            {
                ++$this->calls;

                return 'RESPOSTA_PAGA';
            }
        };
        $this->app->instance(LlmGateway::class, $llm);

        return $llm;
    }

    private function investor(string $suffix): Investor
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);

        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Piloto '.$suffix,
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'piloto'.$suffix.'-'.Str::random(6).'@example.test',
        ]);
    }

    private function subscription(Investor $investor, string $status, string $access): Subscription
    {
        return Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => $status,
            'access_status' => $access,
            'started_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
            'due_at' => new DateTimeImmutable('2026-11-16T12:00:00+00:00'),
        ]);
    }

    private function payment(Subscription $subscription, int $amount = 2990, string $status = 'paid', int $installment = 1): PaymentRecord
    {
        return PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => 'p0-'.Str::random(8),
            'installment_number' => $installment,
            'amount_cents' => $amount,
            'due_at' => new DateTimeImmutable('2026-11-16T12:00:00+00:00'),
            'status' => $status,
        ]);
    }
}
