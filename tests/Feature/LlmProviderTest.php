<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Allocation\Allocation;
use App\Domain\Conversation\ConversationOrchestrator;
use App\Domain\Conversation\LlmGateway;
use App\Domain\Explanation\ProposalExplainer;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Explanation;
use App\Models\Investor;
use App\Models\PaymentRecord;
use App\Models\Subscription;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LlmProviderTest extends TestCase
{
    use RefreshDatabase;

    public function testProviderIsDisabledByDefaultAndDoesNotCallHttp(): void
    {
        config()->set('llm.enabled', false);
        Http::fake();
        Http::preventStrayRequests();

        self::assertNull(app(LlmGateway::class));

        $result = app(ProposalExplainer::class)->explain(
            $this->investor(),
            $this->validAllocation(new DateTimeImmutable('2026-11-17T12:00:00+00:00')),
            new DateTimeImmutable('2026-11-17T12:00:00+00:00'),
        );

        self::assertSame('review_required', $result['status']);
        self::assertSame('llm_unconfigured', $result['reason']);
        self::assertSame(0, Explanation::query()->count());
        Http::assertNothingSent();
    }

    public function testValidGuardedResponseIsExplainedWithSanitizedAudit(): void
    {
        $this->enableProvider();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Proposta validada: 6000 bps em TESOURO-IPCA e 4000 bps em CASH, com fonte bcb/selic.'],
                ]],
                'usage' => ['total_tokens' => 120, 'prompt_tokens' => 80, 'completion_tokens' => 40],
            ]),
        ]);

        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $result = app(ProposalExplainer::class)->explain($this->investor(), $this->validAllocation($now), $now);

        self::assertSame('generated', $result['status']);
        self::assertSame('test-model', $result['model']);
        self::assertSame(1, Explanation::query()->count());
        Http::assertSentCount(1);

        $audit = AuditEvent::query()->where('action', 'llm.completed')->firstOrFail();
        self::assertSame(120, $audit->metadata['tokens']);
        self::assertSame(1, $audit->metadata['cost_cents']);
        self::assertArrayNotHasKey('prompt', $audit->metadata);
        self::assertArrayNotHasKey('body', $audit->metadata);
        self::assertArrayNotHasKey('phone', $audit->metadata);
    }

    public function testInventedNumbersAreRejectedAndRoutedToHumanReview(): void
    {
        $this->enableProvider();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Invista 1200 bps em PETROBRAS e obtenha 15 por cento de retorno garantido.'],
                ]],
                'usage' => ['total_tokens' => 100, 'prompt_tokens' => 60, 'completion_tokens' => 40],
            ]),
        ]);

        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $result = app(ProposalExplainer::class)->explain($this->investor(), $this->validAllocation($now), $now);

        self::assertSame('review_required', $result['status']);
        self::assertSame('llm_output_invented_number', $result['reason']);
        self::assertSame(0, Explanation::query()->count());
        self::assertTrue(AuditEvent::query()->where('action', 'llm.output_rejected')->exists());
        Http::assertSentCount(1);
    }

    public function testPiiAndUrlsInOutputAreRejected(): void
    {
        $this->enableProvider();
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Acesse https://externo.example.test/aporte ou maria@example.test para ver 6000 bps em TESOURO-IPCA.'],
                ]],
                'usage' => ['total_tokens' => 100, 'prompt_tokens' => 60, 'completion_tokens' => 40],
            ]),
        ]);

        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $result = app(ProposalExplainer::class)->explain($this->investor(), $this->validAllocation($now), $now);

        self::assertSame('review_required', $result['status']);
        self::assertSame('llm_output_unsafe', $result['reason']);
        self::assertSame(0, Explanation::query()->count());
        Http::assertSentCount(1);
    }

    public function testProviderFailureOpensCircuitBreakerAndSkipsLaterCalls(): void
    {
        $this->enableProvider();
        config()->set('llm.circuit_breaker.failure_threshold', 1);
        Http::fake(['*' => Http::response([], 500)]);

        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $investor = $this->investor();
        $allocation = $this->validAllocation($now);
        $explainer = app(ProposalExplainer::class);

        $first = $explainer->explain($investor, $allocation, $now);
        $second = $explainer->explain($investor, $allocation, $now);

        self::assertSame('review_required', $first['status']);
        self::assertSame('llm_provider_unavailable', $first['reason']);
        self::assertSame('review_required', $second['status']);
        self::assertSame('llm_circuit_open', $second['reason']);
        self::assertSame(0, Explanation::query()->count());
        Http::assertSentCount(1);
    }

    public function testCallLimitBlocksProviderBeforeRequest(): void
    {
        $this->enableProvider();
        config()->set('llm.limits.max_calls_per_hour', 1);
        Cache::put('llm:calls:'.now()->format('YmdH'), 1, 3600);
        Http::fake();
        Http::preventStrayRequests();

        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $result = app(ProposalExplainer::class)->explain($this->investor(), $this->validAllocation($now), $now);

        self::assertSame('review_required', $result['status']);
        self::assertSame('llm_call_limit', $result['reason']);
        Http::assertNothingSent();
    }

    public function testTokenLimitBlocksProviderBeforeRequest(): void
    {
        $this->enableProvider();
        config()->set('llm.limits.max_tokens_per_hour', 1000);
        Cache::put('llm:tokens:'.now()->format('YmdH'), 1000, 3600);
        Http::fake();
        Http::preventStrayRequests();

        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $result = app(ProposalExplainer::class)->explain($this->investor(), $this->validAllocation($now), $now);

        self::assertSame('review_required', $result['status']);
        self::assertSame('llm_token_limit', $result['reason']);
        Http::assertNothingSent();
    }

    public function testCostLimitBlocksProviderBeforeRequest(): void
    {
        $this->enableProvider();
        config()->set('llm.limits.max_cost_cents_per_day', 1);
        Cache::put('llm:cost:'.now()->format('Ymd'), 1, 90000);
        Http::fake();
        Http::preventStrayRequests();

        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $result = app(ProposalExplainer::class)->explain($this->investor(), $this->validAllocation($now), $now);

        self::assertSame('review_required', $result['status']);
        self::assertSame('llm_cost_limit', $result['reason']);
        Http::assertNothingSent();
    }

    public function testBlockedProposalNeverReachesProvider(): void
    {
        $this->enableProvider();
        Http::fake();
        Http::preventStrayRequests();

        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $blocked = Allocation::make('blocked', 'compliance_blocked', 'rule-v1', [[
            'symbol' => 'CASH',
            'bps' => 10000,
            'amount_cents' => 80000,
            'risk_bps' => 0,
            'source' => 'internal/rule',
            'as_of' => null,
            'rule_version' => 'rule-v1',
        ]]);

        $result = app(ProposalExplainer::class)->explain($this->investor(), $blocked, $now);

        self::assertSame('blocked', $result['status']);
        self::assertSame(0, Explanation::query()->count());
        Http::assertNothingSent();
    }

    public function testOverdueMessageNeverReachesProvider(): void
    {
        $this->enableProvider();
        Http::fake();
        Http::preventStrayRequests();

        $investor = $this->investor();
        $subscription = $this->subscription($investor, 'past_due', 'suspended', 0);
        $this->payment($subscription);

        $result = app(ConversationOrchestrator::class)->handle(
            $investor,
            'Fazer aporte R$ 800',
            new DateTimeImmutable('2026-11-17T12:00:00+00:00'),
        );

        self::assertSame('blocked', $result['action']);
        self::assertSame(0, $result['llm_calls']);
        Http::assertNothingSent();
    }

    public function testFreeTextNeverReachesProviderEvenWhenPaid(): void
    {
        $this->enableProvider();
        Http::fake();
        Http::preventStrayRequests();

        $investor = $this->investor();
        $subscription = $this->subscription(
            $investor,
            'active',
            'active',
            3,
            new DateTimeImmutable('2026-11-20T12:00:00+00:00'),
        );
        $this->payment($subscription);

        $result = app(ConversationOrchestrator::class)->handle(
            $investor,
            'Minha carteira',
            new DateTimeImmutable('2026-11-17T12:00:00+00:00'),
        );

        self::assertSame('eligible', $result['action']);
        self::assertSame(0, $result['llm_calls']);
        self::assertSame(config('llm.free_text_reply'), $result['reply']);
        Http::assertNothingSent();
    }

    private function enableProvider(): void
    {
        config()->set('llm.enabled', true);
        config()->set('llm.driver', 'http');
        config()->set('llm.api_key', 'test-key');
        config()->set('llm.base_url', 'https://llm.example.test/v1');
        config()->set('llm.model', 'test-model');
    }

    private function validAllocation(DateTimeImmutable $asOf): Allocation
    {
        return Allocation::make('invest', 'deterministic_allocation', 'allocation-2026.11-v1', [
            [
                'symbol' => 'TESOURO-IPCA',
                'bps' => 6000,
                'amount_cents' => 48000,
                'risk_bps' => 1000,
                'source' => 'bcb/selic',
                'as_of' => $asOf->format(DATE_ATOM),
                'max_age_seconds' => 86400,
                'rule_version' => 'allocation-2026.11-v1',
            ],
            [
                'symbol' => 'CASH',
                'bps' => 4000,
                'amount_cents' => 32000,
                'risk_bps' => 0,
                'source' => 'internal/rule',
                'as_of' => $asOf->format(DATE_ATOM),
                'rule_version' => 'allocation-2026.11-v1',
            ],
        ]);
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
            'full_name' => 'Joana Prado',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'joana-'.Str::random(6).'@example.test',
        ]);
    }

    private function subscription(
        Investor $investor,
        string $status,
        string $access,
        int $grace,
        ?DateTimeImmutable $dueAt = null,
    ): Subscription {
        return Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => $status,
            'access_status' => $access,
            'grace_days' => $grace,
            'started_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
            'due_at' => $dueAt ?? new DateTimeImmutable('2026-11-16T12:00:00+00:00'),
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
