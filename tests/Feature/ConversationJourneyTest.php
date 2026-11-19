<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Conversation\LlmGateway;
use App\Domain\MarketData\SourceCatalog;
use App\Domain\WhatsApp\WebhookDeduplicator;
use App\Domain\WhatsApp\WebhookEventParser;
use App\Domain\WhatsApp\WebhookProcessor;
use App\Models\AdminUser;
use App\Models\AporteSession;
use App\Models\AuditEvent;
use App\Models\DataRightsRequest;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\InvestorJourney;
use App\Models\MarketQuote;
use App\Models\PaymentRecord;
use App\Models\PortfolioEvent;
use App\Models\Report;
use App\Models\ReviewCase;
use App\Models\Subscription;
use App\Models\SubscriptionAccessEvent;
use App\Models\WhatsAppOutboxMessage;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConversationJourneyTest extends TestCase
{
    use RefreshDatabase;

    private object $llm;

    private int $clock;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('compliance.advisory_authorized', true);
        config()->set('whatsapp.cloud_api.mock', true);
        config()->set('billing.gateway.available', true);

        $this->adminId = (string) AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(8).'@example.test',
            'password_hash' => Hash::make('secret'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ])->id;

        $this->llm = new class implements LlmGateway
        {
            public int $calls = 0;

            public function complete(string $prompt): string
            {
                ++$this->calls;

                return 'Proposta validada pelo motor determinístico, com fontes e regras auditadas.';
            }
        };
        $this->app->instance(LlmGateway::class, $this->llm);
        $this->clock = (new DateTimeImmutable('2026-11-19T12:00:00+00:00'))->getTimestamp();
    }

    public function testJourneyResumesAndRequiresFourAnswersBeforeAnyProposal(): void
    {
        $investor = $this->paidInvestor();
        $now = $this->tick();
        $this->candidates($now);

        $this->assertState($investor, 'Ola', 'awaiting_identity');
        $this->assertState($investor, 'CONFIRMO TELEFONE', 'awaiting_terms');
        $this->assertState($investor, 'ACEITO TERMOS', 'awaiting_suitability');
        $this->assertState($investor, 'PERFIL GROWTH LONG INTERMEDIATE MONTHLY NENHUMA', 'awaiting_amount');
        $this->assertState($investor, '800,00', 'awaiting_aporte_1');

        foreach (['objetivo', 'horizonte', 'tolerancia'] as $index => $answer) {
            $this->assertState($investor, $answer, 'awaiting_aporte_'.($index + 2));
        }

        $early = $this->send($investor, 'PROPOR');
        self::assertSame('awaiting_aporte_4', $early['journey_state']);
        self::assertSame('awaiting_expected_answer', $early['journey_action']);
        self::assertSame(0, Report::query()->where('label', 'PROPOSTA')->count());

        $this->assertState($investor, 'nao', 'awaiting_decision');
        $session = AporteSession::query()->where('investor_id', $investor->id)->firstOrFail();
        self::assertEqualsCanonicalizing([
            'objective' => 'objetivo',
            'horizon' => 'horizonte',
            'tolerance' => 'tolerancia',
            'financial_changes' => 'nao',
        ], array_intersect_key($session->answers, array_flip(['objective', 'horizon', 'tolerance', 'financial_changes'])));

        $proposal = $this->send($investor, 'PROPOR');
        self::assertSame('awaiting_confirmation', $proposal['journey_state']);
        self::assertSame('proposal_generated', $proposal['journey_action']);
        self::assertSame(1, Report::query()->where('label', 'PROPOSTA')->count());
        self::assertTrue(WhatsAppOutboxMessage::query()
            ->where('type', 'image')
            ->where('idempotency_key', 'like', 'png:report:%')
            ->exists());

        $resume = $this->send($investor, 'CONTINUAR');
        self::assertSame('awaiting_confirmation', $resume['journey_state']);
        self::assertSame('resumed', $resume['journey_action']);
        self::assertStringContainsString('CONFIRMO APORTE', (string) $resume['reply']);
    }

    public function testDuplicateAndOutOfOrderEventsDoNotAdvanceAndJourneysAreIsolatedByPhone(): void
    {
        $first = $this->paidInvestor();
        $second = $this->paidInvestor();
        $now = $this->tick();

        $initial = $this->send($first, 'Ola', 'chat-first-1', $now);
        self::assertSame('awaiting_identity', $initial['journey_state']);

        $duplicate = $this->send($first, 'Ola', 'chat-first-1', $now);
        self::assertSame('duplicate', $duplicate['status']);
        self::assertSame(1, InvestorJourney::query()->where('investor_id', $first->id)->value('version'));

        $later = $now->modify('+10 seconds');
        $this->send($first, 'CONFIRMO TELEFONE', 'chat-first-2', $later);
        self::assertSame('awaiting_terms', $this->state($first));

        $stale = $this->send($first, 'CONFIRMO TELEFONE', 'chat-first-stale', $now);
        self::assertSame('out_of_order', $stale['journey_action']);
        self::assertSame('awaiting_terms', $stale['journey_state']);
        self::assertSame('awaiting_terms', $this->state($first));

        $secondInitial = $this->send($second, 'Ola', 'chat-second-1', $later->modify('+10 seconds'));
        self::assertSame('awaiting_identity', $secondInitial['journey_state']);
        self::assertSame('awaiting_identity', $this->state($second));
        self::assertSame(2, InvestorJourney::query()->count());
        self::assertSame(1, AuditEvent::query()->where('action', 'journey.ignored_out_of_order')->count());
    }

    public function testDelinquentCommandsAreAuditedAndNeverUnlockPaidServicesOrLlm(): void
    {
        $investor = $this->unpaidInvestor();
        $now = $this->tick();

        $blocked = $this->send($investor, 'Fazer aporte R$ 800', 'chat-blocked', $now);
        self::assertSame('blocked', $blocked['action']);
        self::assertSame(0, $blocked['llm_calls']);
        self::assertSame(0, $this->llm->calls);
        self::assertSame('new', $this->state($investor));

        $this->send($investor, 'Já paguei', 'chat-paid-1', $now->modify('+10 seconds'));
        $this->send($investor, 'Já paguei', 'chat-paid-2', $now->modify('+20 seconds'));

        $payment = PaymentRecord::query()->firstOrFail();
        self::assertSame('processing', $payment->status);
        self::assertSame('suspended', Subscription::query()->firstOrFail()->access_status);
        self::assertSame(1, AuditEvent::query()->where('action', 'billing.verification_requested')->count());
        self::assertSame(2, AuditEvent::query()
            ->where('action', 'journey.command')
            ->where('metadata->action', 'reconcile_requested')
            ->count());

        $data = $this->send($investor, 'Meus dados', 'chat-data', $now->modify('+30 seconds'));
        self::assertSame('data_reviewed', $data['journey_action']);
        self::assertStringNotContainsString($investor->email, (string) $data['reply']);

        $deletion = $this->send($investor, 'SOLICITAR EXCLUSÃO', 'chat-delete', $now->modify('+40 seconds'));
        self::assertSame('deletion_requested', $deletion['journey_action']);
        self::assertSame(1, DataRightsRequest::query()->where('type', 'deletion')->count());

        $cancellation = $this->send($investor, 'Cancelar assinatura', 'chat-cancel', $now->modify('+50 seconds'));
        self::assertSame('cancellation_requested', $cancellation['journey_action']);
        self::assertSame(1, SubscriptionAccessEvent::query()
            ->where('reason', 'bot_cancellation_requested')
            ->count());
        self::assertSame('suspended', Subscription::query()->firstOrFail()->access_status);

        $handoff = $this->send($investor, 'HUMANO', 'chat-human', $now->modify('+60 seconds'));
        self::assertSame('human_handoff', $handoff['status']);
        self::assertSame(1, AuditEvent::query()
            ->where('action', 'journey.command')
            ->where('metadata->command', 'handoff')
            ->count());

        self::assertSame(0, $this->llm->calls);
    }

    public function testExplicitConfirmationAppliesOnceAndReportsCanBeResent(): void
    {
        $investor = $this->paidInvestor();
        $now = $this->tick();
        $this->candidates($now);
        $this->completeToProposal($investor);

        $confirmed = $this->send($investor, 'CONFIRMO APORTE');
        self::assertSame('completed', $confirmed['journey_state']);
        self::assertSame('aporte_confirmed', $confirmed['journey_action']);
        self::assertSame(1, Report::query()->where('label', 'REALIZADO')->count());
        self::assertSame(1, PortfolioEvent::query()->where('investor_id', $investor->id)->count());

        $second = $this->send($investor, 'CONFIRMO APORTE');
        self::assertSame('duplicate_confirmation_blocked', $second['journey_action']);
        self::assertSame(1, PortfolioEvent::query()->where('investor_id', $investor->id)->count());

        $report = $this->send($investor, 'MEU RELATORIO');
        self::assertSame('report_sent', $report['journey_action']);
        self::assertSame(2, Report::query()->where('label', 'REALIZADO')->count());
        self::assertSame(1, PortfolioEvent::query()->where('investor_id', $investor->id)->count());

        $images = WhatsAppOutboxMessage::query()
            ->where('type', 'image')
            ->where('idempotency_key', 'like', 'png:report:%')
            ->get();
        self::assertCount(3, $images);
        self::assertSame($images->pluck('idempotency_key')->unique()->count(), $images->count());
    }

    public function testRefusalAndReviewNeverCreatePositionsOrOrders(): void
    {
        $investor = $this->paidInvestor();
        $now = $this->tick();
        $this->candidates($now);
        $this->completeToPhaseOne($investor);

        $refused = $this->send($investor, 'RECUSAR');
        self::assertSame('refused', $refused['journey_state']);
        self::assertSame('aporte_refused', $refused['journey_action']);
        self::assertSame(0, Report::query()->count());
        self::assertSame(0, PortfolioEvent::query()->count());

        $again = $this->paidInvestor();
        $this->completeToPhaseOne($again);
        $review = $this->send($again, 'REVISAR');
        self::assertSame('review', $review['journey_state']);
        self::assertSame(1, ReviewCase::query()->where('investor_id', $again->id)->where('critical', true)->count());

        $blocked = $this->send($again, 'PROPOR');
        self::assertSame('review', $blocked['journey_state']);
        self::assertSame('proposal_blocked_by_review', $blocked['journey_action']);
        self::assertSame(0, Report::query()->where('investor_id', $again->id)->count());
        self::assertSame(0, PortfolioEvent::query()->where('investor_id', $again->id)->count());
    }

    private function assertState(Investor $investor, string $body, string $expected): void
    {
        $result = $this->send($investor, $body);

        self::assertSame($expected, $result['journey_state'], $body);
        self::assertSame($expected, $this->state($investor), $body);
    }

    /** @return array<string, mixed> */
    private function send(
        Investor $investor,
        string $body,
        ?string $externalId = null,
        ?DateTimeImmutable $receivedAt = null,
    ): array {
        $receivedAt = $receivedAt ?? $this->tick();
        $id = $externalId ?? 'chat-'.Str::uuid();
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => (string) config('whatsapp.bot.phone_number_id')],
                        'messages' => [[
                            'id' => $id,
                            'from' => ltrim($investor->phone_e164, '+'),
                            'timestamp' => (string) $receivedAt->getTimestamp(),
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];

        $event = app(WebhookEventParser::class)->parse($payload, $receivedAt)[0];
        $inboxEventId = app(WebhookDeduplicator::class)->claim($event, $receivedAt);
        if ($inboxEventId === null) {
            return ['status' => 'duplicate'];
        }
        $event['inbox_event_id'] = $inboxEventId;

        $result = app(WebhookProcessor::class)->ingest($event, $receivedAt, true);
        $reply = $result['reply'] ?? null;

        return is_array($reply) ? array_replace($result, $reply) : $result;
    }

    private function tick(): DateTimeImmutable
    {
        $this->clock += 10;

        return (new DateTimeImmutable('@'.$this->clock))->setTimezone(new DateTimeZone('UTC'));
    }

    private function state(Investor $investor): string
    {
        return (string) (InvestorJourney::query()->where('investor_id', $investor->id)->value('state') ?? 'new');
    }

    private function paidInvestor(): Investor
    {
        $investor = $this->investor();
        $due = new DateTimeImmutable('2026-12-01T12:00:00+00:00');

        $subscription = Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => 'active',
            'access_status' => 'active',
            'grace_days' => 0,
            'started_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
            'due_at' => $due,
        ]);

        PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => 'provider-'.Str::random(6),
            'installment_number' => 1,
            'amount_cents' => 2990,
            'due_at' => $due,
            'status' => 'paid',
            'paid_at' => new DateTimeImmutable('2026-11-15T12:00:00+00:00'),
            'reconciled_at' => new DateTimeImmutable('2026-11-15T12:05:00+00:00'),
            'payment_url' => 'https://pay.example.test/fatura',
        ]);

        return $investor;
    }

    private function unpaidInvestor(): Investor
    {
        $investor = $this->investor();
        $due = new DateTimeImmutable('2026-11-16T12:00:00+00:00');

        $subscription = Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => 'past_due',
            'access_status' => 'suspended',
            'grace_days' => 0,
            'started_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
            'due_at' => $due,
        ]);

        PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => 'provider-'.Str::random(6),
            'installment_number' => 1,
            'amount_cents' => 2990,
            'due_at' => $due,
            'status' => 'open',
            'payment_url' => 'https://pay.example.test/fatura',
        ]);

        return $investor;
    }

    private function investor(): Investor
    {
        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $this->adminId,
            'full_name' => 'Investidor Jornada',
            'phone_e164' => '+55759927'.Str::random(5),
            'email' => 'jornada-'.Str::random(8).'@example.test',
        ]);
    }

    /** @return list<array{instrument: Instrument, quote: MarketQuote}> */
    private function candidates(DateTimeImmutable $asOf): array
    {
        $candidates = [];

        foreach (array_keys((array) config('allocation.universe', [])) as $symbol) {
            $sourceKey = (string) config('allocation.universe.'.$symbol.'.source', 'bcb_selic');
            $source = app(SourceCatalog::class)->get($sourceKey);
            $instrument = Instrument::query()->firstOrCreate(
                ['symbol' => (string) $symbol],
                ['id' => (string) Str::uuid(), 'instrument_type' => 'renda_fixa', 'status' => 'active'],
            );
            $quote = MarketQuote::query()->create([
                'id' => (string) Str::uuid(),
                'instrument_id' => $instrument->id,
                'source_id' => $source['id'],
                'as_of' => $asOf,
                'price' => '100.0000',
                'quality_status' => 'approved',
                'captured_at' => $asOf,
            ]);
            $candidates[] = ['instrument' => $instrument, 'quote' => $quote];
        }

        return $candidates;
    }

    private function completeToPhaseOne(Investor $investor): void
    {
        $this->assertState($investor, 'Ola', 'awaiting_identity');
        $this->assertState($investor, 'CONFIRMO TELEFONE', 'awaiting_terms');
        $this->assertState($investor, 'ACEITO TERMOS', 'awaiting_suitability');
        $this->assertState($investor, 'PERFIL GROWTH LONG INTERMEDIATE MONTHLY NENHUMA', 'awaiting_amount');
        $this->assertState($investor, '800,00', 'awaiting_aporte_1');

        foreach (['objetivo', 'horizonte', 'tolerancia', 'nao'] as $answer) {
            $this->send($investor, $answer);
        }

        self::assertSame('awaiting_decision', $this->state($investor));
    }

    private function completeToProposal(Investor $investor): void
    {
        $this->completeToPhaseOne($investor);
        $result = $this->send($investor, 'PROPOR');

        self::assertSame('awaiting_confirmation', $result['journey_state']);
    }
}
