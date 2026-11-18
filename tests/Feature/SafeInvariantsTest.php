<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\AdminAuth\Totp;
use App\Domain\Allocation\AllocationEngine;
use App\Domain\Aporte\AporteException;
use App\Domain\Aporte\AporteFlowService;
use App\Domain\Conversation\LlmGateway;
use App\Domain\MarketData\SourceCatalog;
use App\Domain\Onboarding\AdvisoryAuthorization;
use App\Domain\Onboarding\ComplianceException;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\AporteSession;
use App\Models\AuditEvent;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\MarketQuote;
use App\Models\PortfolioEvent;
use App\Models\PortfolioRecommendation;
use App\Models\SuitabilityAssessment;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** SAFE: invariantes fail-closed de autorização, suitability, orçamento e acesso. */
final class SafeInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-17T12:00:00+00:00';

    private const APORTE_ANSWERS = [
        'objective' => 'renda',
        'horizon' => 'longo',
        'tolerance' => 'moderada',
        'financial_changes' => 'nao',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('compliance.advisory_authorized', true);
        config()->set('cache.default', 'array');
    }

    public function testSuitabilityExigeValidadeEsquemaValidoESemRestricoes(): void
    {
        $now = $this->now();
        $investor = $this->investor($this->admin()[0], 'SAFE-1');
        $valid = $this->assessment($investor);
        $withoutExpiry = $this->assessment($investor, ['expires_at' => null]);
        $expired = $this->assessment($investor, ['expires_at' => $now->modify('-1 second')]);
        $unknown = $this->assessment($investor, ['classification' => 'unknown']);
        $answers = $this->answers();
        $answers['restrictions'] = 'sem-renda-fixa';
        $malformed = $this->assessment($investor, ['answers' => $answers]);
        $restricted = $this->assessment($investor, [
            'answers' => ['restrictions' => ['no_credit']] + $this->answers(),
        ]);

        $authorization = app(AdvisoryAuthorization::class);

        self::assertTrue($authorization->canRecommend($valid, $now));
        self::assertFalse($authorization->canRecommend($withoutExpiry, $now));
        self::assertFalse($authorization->canRecommend($expired, $now));
        self::assertFalse($authorization->canRecommend($unknown, $now));
        self::assertFalse($authorization->canRecommend($malformed, $now));
        self::assertFalse($authorization->canRecommend($restricted, $now));

        try {
            $authorization->assertCanRecommend($withoutExpiry, $now);
            self::fail('Perfil sem prazo de validade deve ser bloqueado.');
        } catch (ComplianceException) {
            $event = AuditEvent::query()
                ->where('action', 'compliance.recommendation_blocked')
                ->where('subject_id', $withoutExpiry->id)
                ->firstOrFail();
            self::assertSame('profile_without_expiry', $event->metadata['reason']);
        }
    }

    public function testReservaEDividasLimitamOValorInvestidoComCentavosExatos(): void
    {
        $now = $this->now();
        $assessment = $this->assessment($this->investor($this->admin()[0], 'SAFE-2'));
        $candidates = [
            $this->candidate('TESOURO-IPCA', $now),
            $this->candidate('CDB-LIQUIDEZ', $now),
        ];
        $engine = app(AllocationEngine::class);

        $full = $engine->allocate($assessment, 80000, $candidates, [
            'reserve_cents' => 20000,
            'debts_cents' => 10000,
        ], $now);

        self::assertSame('invest', $full->status);
        self::assertSame(10000, $full->totalBps);
        self::assertSame(80000, $full->totalCents);
        self::assertSame(30000, $this->investedCents($full->targets));
        self::assertSame(50000, $this->cashCents($full->targets));
        self::assertLessThanOrEqual(50000, $this->investedCents($full->targets));

        $partial = $engine->allocate($assessment, 80000, $candidates, [
            'reserve_cents' => 20000,
        ], $now);

        self::assertSame(36000, $this->investedCents($partial->targets));
        self::assertSame(44000, $this->cashCents($partial->targets));
        self::assertSame(80000, $partial->totalCents);

        $audit = AuditEvent::query()->where('action', 'allocation.generated')->latest('occurred_at')->firstOrFail();
        self::assertSame(50000, $audit->metadata['available_cents']);
        self::assertSame(30000, $audit->metadata['invested_cents']);
    }

    public function testDividasOuMudancaFinanceiraBloqueiamInvestimento(): void
    {
        $now = $this->now();
        $assessment = $this->assessment($this->investor($this->admin()[0], 'SAFE-3'));
        $candidates = [$this->candidate('TESOURO-IPCA', $now)];
        $engine = app(AllocationEngine::class);

        $debts = $engine->allocate($assessment, 80000, $candidates, ['debts_cents' => 80000], $now);
        self::assertSame('blocked', $debts->status);
        self::assertSame('liquidity_or_reserve_first', $debts->reason);

        $changed = $engine->allocate($assessment, 80000, $candidates, ['financial_changes' => true], $now);
        self::assertSame('blocked', $changed->status);
        self::assertSame('liquidity_or_reserve_first', $changed->reason);
    }

    public function testPerfilDesconhecidoEFonteAusenteNaoGeramInstrumento(): void
    {
        $now = $this->now();
        $investor = $this->investor($this->admin()[0], 'SAFE-4');
        $unknown = $this->assessment($investor, ['classification' => 'unknown']);
        $candidates = [$this->candidate('TESOURO-IPCA', $now)];
        $engine = app(AllocationEngine::class);

        $blockedProfile = $engine->allocate($unknown, 80000, $candidates, [], $now);
        self::assertSame('blocked', $blockedProfile->status);
        self::assertSame('compliance_blocked', $blockedProfile->reason);
        self::assertSame([], array_values(array_filter(
            $blockedProfile->targets,
            static fn (array $target): bool => $target['symbol'] !== 'CASH',
        )));
        self::assertSame('profile_unknown', AuditEvent::query()
            ->where('action', 'compliance.recommendation_blocked')
            ->where('subject_id', $unknown->id)
            ->firstOrFail()
            ->metadata['reason']);

        config()->set('allocation.universe.TESOURO-IPCA.source', 'fonte-inexistente');
        $missingSource = $engine->allocate($this->assessment($investor), 80000, $candidates, [], $now);
        self::assertSame('blocked', $missingSource->status);
        self::assertSame('no_reliable_universe', $missingSource->reason);
        self::assertTrue(AuditEvent::query()->where('action', 'allocation.source_invalid')->exists());
    }

    public function testSuitabilityDeOutroInvestidorNaoPodeOrientarSessao(): void
    {
        $now = $this->now();
        $admin = $this->admin()[0];
        $investorA = $this->investor($admin, 'SAFE-5A');
        $investorB = $this->investor($admin, 'SAFE-5B');
        $assessmentB = $this->assessment($investorB);
        $service = app(AporteFlowService::class);
        $sessionA = $this->readySession($service, $investorA, 80000, $now);

        try {
            $service->propose($sessionA, [$this->candidate('TESOURO-IPCA', $now)], ['assessment' => $assessmentB], $now);
            self::fail('Suitability cruzado deve ser recusado.');
        } catch (AporteException $exception) {
            self::assertStringContainsString('outro investidor', $exception->getMessage());
        }

        self::assertSame(0, PortfolioRecommendation::query()->count());
    }

    public function testPropostaBloqueadaNaoChamaLlmENaoGeraOrdemOuRecomendacao(): void
    {
        config()->set('compliance.advisory_authorized', false);
        $now = $this->now();
        $llm = $this->fakeLlm();
        $investor = $this->investor($this->admin()[0], 'SAFE-6');
        $assessment = $this->assessment($investor);
        $candidate = $this->candidate('TESOURO-IPCA', $now);
        $service = app(AporteFlowService::class);
        $session = $this->readySession($service, $investor, 80000, $now);

        try {
            $service->propose($session, [$candidate], ['assessment' => $assessment], $now);
            self::fail('Proposta bloqueada nao pode ser publicada.');
        } catch (AporteException) {
            self::assertSame(0, $llm->calls);
            self::assertSame(0, PortfolioRecommendation::query()->count());
        }

        try {
            $service->confirm($session, 'ordem-bloqueada', $candidate['instrument'], 80000, $now);
            self::fail('Proposta bloqueada nao pode gerar ordem.');
        } catch (AporteException) {
            self::assertSame(0, $llm->calls);
            self::assertSame(0, PortfolioEvent::query()->count());
        }
    }

    public function testAdminDesativadoPerdeAcessoImediatamente(): void
    {
        [$admin, $token] = $this->admin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/investors')
            ->assertOk();

        $admin->forceFill(['active' => false])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/investors')
            ->assertStatus(401);
    }

    public function testLogoutRevogaSessaoDoProprietario(): void
    {
        [$admin, $token] = $this->admin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Trace-Id', (string) Str::uuid())
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        $session = AdminSession::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        self::assertNotNull($session->revoked_at);
        self::assertSame($admin->id, $session->admin_user_id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/investors')
            ->assertStatus(401);
    }

    public function testMfaInvalidoEGressaoDeLoginSaoBloqueados(): void
    {
        $secret = Totp::generateSecret();
        $mfaEmail = 'mfa-'.strtolower(Str::random(6)).'@example.test';
        AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => $mfaEmail,
            'password_hash' => Hash::make('senha-de-teste'),
            'mfa_secret_encrypted' => encrypt($secret),
            'mfa_enabled' => true,
            'active' => true,
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $mfaEmail,
            'password' => 'senha-de-teste',
            'mfa_code' => '000000',
            'trace_id' => (string) Str::uuid(),
        ])->assertStatus(401);

        self::assertTrue(AuditEvent::query()->where('action', 'admin.mfa.failed')->exists());

        $email = 'inexistente-'.Str::random(6).'@example.test';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => $email,
                'password' => 'errada',
                'trace_id' => (string) Str::uuid(),
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $email,
            'password' => 'errada',
            'trace_id' => (string) Str::uuid(),
        ])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function testEndpointHttpNaoUsaSuitabilityDeOutroInvestidor(): void
    {
        $now = $this->now();
        $admin = $this->admin();
        $investorA = $this->investor($admin[0], 'SAFE-7A');
        $investorB = $this->investor($admin[0], 'SAFE-7B');
        $this->assessment($investorB);
        $this->candidate('TESOURO-IPCA', $now);

        $this->withHeader('Authorization', 'Bearer '.$admin[1])
            ->postJson('/api/v1/admin/clients/'.$investorA->id.'/allocation', [
                'budget_brl' => '800.00',
            ])
            ->assertStatus(404);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    /** @return array{0: AdminUser, 1: string} */
    private function admin(bool $active = true): array
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('senha-de-teste'),
            'mfa_secret_encrypted' => encrypt(Totp::generateSecret()),
            'mfa_enabled' => false,
            'active' => $active,
        ]);
        $token = Str::random(64);
        AdminSession::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'mfa_completed_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        return [$admin, $token];
    }

    private function investor(AdminUser $admin, string $suffix): Investor
    {
        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Investidor '.$suffix,
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => strtolower($suffix).'-'.Str::random(6).'@example.test',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function assessment(Investor $investor, array $overrides = []): SuitabilityAssessment
    {
        $version = $overrides['version'] ?? 1 + (int) SuitabilityAssessment::query()
            ->where('investor_id', $investor->id)
            ->max('version');

        return SuitabilityAssessment::query()->create(array_replace([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'version' => $version,
            'answers' => $this->answers(),
            'classification' => 'moderate',
            'status' => 'valid',
            'assessed_at' => $this->now()->modify('-2 hours'),
            'expires_at' => $this->now()->modify('+180 days'),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        return [
            'objective' => 'growth',
            'horizon' => 'long',
            'knowledge' => 'intermediate',
            'liquidity' => 'monthly',
            'restrictions' => [],
        ];
    }

    /** @return array{instrument: Instrument, quote: MarketQuote} */
    private function candidate(string $symbol, DateTimeImmutable $asOf): array
    {
        $source = app(SourceCatalog::class)->get((string) config('allocation.universe.'.$symbol.'.source'));
        $instrument = Instrument::query()->firstOrCreate(
            ['symbol' => $symbol],
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

        return ['instrument' => $instrument, 'quote' => $quote];
    }

    private function readySession(AporteFlowService $service, Investor $investor, int $amountCents, DateTimeImmutable $now): AporteSession
    {
        $session = $service->start($investor, $amountCents, $now);
        foreach (self::APORTE_ANSWERS as $question => $value) {
            $session = $service->answer($session, $question, $value, 'key-'.$question, $now)['session'];
        }

        return $session;
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

    /** @param list<array<string, mixed>> $targets */
    private function investedCents(array $targets): int
    {
        return array_sum(array_map(
            static fn (array $target): int => $target['symbol'] === 'CASH' ? 0 : (int) $target['amount_cents'],
            $targets,
        ));
    }

    /** @param list<array<string, mixed>> $targets */
    private function cashCents(array $targets): int
    {
        foreach ($targets as $target) {
            if ($target['symbol'] === 'CASH') {
                return (int) $target['amount_cents'];
            }
        }

        self::fail('Destino CASH não encontrado.');
    }
}
