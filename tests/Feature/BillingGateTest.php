<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\SubscriptionService;
use App\Domain\Conversation\ConversationOrchestrator;
use App\Domain\Conversation\LlmGateway;
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

final class BillingGateTest extends TestCase
{
    use RefreshDatabase;

    public function testA13OverdueMessageNeverCallsLlm(): void
    {
        $llm = $this->fakeLlm();
        $investor = $this->investor();
        $subscription = $this->subscription($investor, 'past_due', 'suspended', 0);
        $this->payment($subscription);

        $result = app(ConversationOrchestrator::class)->handle(
            $investor,
            'Fazer aporte R$ 800',
            new DateTimeImmutable('2026-11-17T12:00:00+00:00'),
        );

        self::assertSame('blocked', $result['action']);
        self::assertFalse($result['allow_llm']);
        self::assertSame(0, $result['llm_calls']);
        self::assertSame(0, $llm->calls);
        self::assertStringContainsString('venceu em 16/11/2026', $result['reply']);
        self::assertStringContainsString('temporariamente pausados', $result['reply']);
        self::assertStringContainsString('https://pay.example.test/fatura', $result['reply']);
        self::assertStringNotContainsString('RESPOSTA_PAGA', $result['reply']);
    }

    public function testA14AllowedCommandsWorkWithoutPaidServices(): void
    {
        $llm = $this->fakeLlm();
        $investor = $this->investor();
        $subscription = $this->subscription($investor, 'past_due', 'suspended', 0);
        $payment = $this->payment($subscription);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $orchestrator = app(ConversationOrchestrator::class);

        foreach (['Meu plano', 'Regularizar pagamento', 'Falar com atendimento', 'Meus dados', 'Exportar dados', 'Cancelar assinatura'] as $command) {
            $result = $orchestrator->handle($investor, $command, $now);
            self::assertSame('command', $result['action'], $command);
            self::assertSame(0, $result['llm_calls'], $command);
            self::assertNotSame('', $result['reply'], $command);
        }

        $result = $orchestrator->handle($investor, 'Já paguei', $now);
        self::assertSame('command', $result['action']);
        self::assertSame(0, $result['llm_calls']);
        self::assertSame('processing', $payment->fresh()->status);
        self::assertSame('suspended', $subscription->fresh()->access_status);
        self::assertSame(0, $llm->calls);
    }

    public function testGatewayFailureIsVerificationAndNotConfirmedDebt(): void
    {
        config()->set('billing.gateway.available', false);
        $llm = $this->fakeLlm();
        $investor = $this->investor();
        $subscription = $this->subscription($investor, 'past_due', 'suspended', 0);
        $this->payment($subscription);

        $result = app(ConversationOrchestrator::class)->handle(
            $investor,
            'Fazer aporte R$ 800',
            new DateTimeImmutable('2026-11-17T12:00:00+00:00'),
        );

        self::assertSame('verification', $result['action']);
        self::assertSame(0, $result['llm_calls']);
        self::assertStringNotContainsString('venceu em', $result['reply']);
        self::assertTrue(AuditEvent::query()->where('action', 'billing.gate_escalation')->exists());
        self::assertSame(0, $llm->calls);
    }

    public function testA15ReconciliationAllowsPaidAccessWithoutFreeTextLlm(): void
    {
        $llm = $this->fakeLlm();
        $investor = $this->investor();
        $subscription = $this->subscription($investor, 'past_due', 'suspended', 0);
        $payment = $this->payment($subscription);
        $service = app(SubscriptionService::class);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        self::assertTrue($service->reconcile($subscription, $payment, 'paid-once', 'paid', $now));
        self::assertFalse($service->reconcile($subscription, $payment->fresh(), 'paid-once', 'paid', $now));

        $result = app(ConversationOrchestrator::class)->handle($investor, 'Minha carteira', $now);
        self::assertSame('eligible', $result['action']);
        self::assertTrue($result['allow_llm']);
        self::assertSame(0, $result['llm_calls']);
        self::assertSame(config('llm.free_text_reply'), $result['reply']);
        self::assertStringNotContainsString('RESPOSTA_PAGA', $result['reply']);
        self::assertSame(0, $llm->calls);
    }

    public function testContractualGracePeriodKeepsPaidAccessAvailable(): void
    {
        $investor = $this->investor();
        $subscription = $this->subscription($investor, 'active', 'active', 3);
        $this->payment($subscription);

        $result = app(ConversationOrchestrator::class)->handle(
            $investor,
            'Fazer aporte R$ 800',
            new DateTimeImmutable('2026-11-17T12:00:00+00:00'),
        );

        self::assertSame('grace', $result['action']);
        self::assertTrue($result['allow_llm']);
    }

    public function testSignedWebhookRoutesThroughGateBeforeLlm(): void
    {
        config()->set('whatsapp.webhook.app_secret', 'webhook-secret');
        $llm = $this->fakeLlm();
        $investor = $this->investor();
        $subscription = $this->subscription($investor, 'past_due', 'suspended', 0);
        $this->payment($subscription);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '5575992605555'],
                        'messages' => [[
                            'id' => 'wamid-gated-1',
                            'from' => ltrim($investor->phone_e164, '+'),
                            'type' => 'text',
                            'text' => ['body' => 'Fazer aporte R$ 800'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'webhook-secret');

        $this->call('POST', '/api/v1/webhooks/whatsapp-bot', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw)
            ->assertOk()
            ->assertJsonPath('statuses.wamid-gated-1', 'stored')
            ->assertJsonPath('replies.wamid-gated-1.action', 'blocked')
            ->assertJsonPath('replies.wamid-gated-1.llm_calls', 0);

        self::assertSame(0, $llm->calls);
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

    private function investor(): Investor
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
            'full_name' => 'Maria Silva',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'maria-'.Str::random(6).'@example.test',
        ]);
    }

    private function subscription(Investor $investor, string $status, string $access, int $grace): Subscription
    {
        return Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => $status,
            'access_status' => $access,
            'grace_days' => $grace,
            'started_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
            'due_at' => new DateTimeImmutable('2026-11-16T12:00:00+00:00'),
        ]);
    }

    private function payment(Subscription $subscription): PaymentRecord
    {
        return PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => 'provider-'.Str::random(6),
            'installment_number' => 1,
            'amount_cents' => 2990,
            'due_at' => $subscription->due_at,
            'status' => 'open',
            'payment_url' => 'https://pay.example.test/fatura',
        ]);
    }
}
