<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\AdminAuth\Totp;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\PaymentRecord;
use App\Models\Subscription;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** UI: painel exclusivo do proprietário, sem credenciais de cliente e sobre a API existente. */
final class OwnerPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function testLoginSemCodigoMfaNaoCriaSessao(): void
    {
        $secret = Totp::generateSecret();
        $admin = $this->admin('sem-mfa@example.test', $secret, true);

        $this->from(route('panel.login'))
            ->post(route('panel.login.submit'), [
                'email' => $admin->email,
                'password' => 'senha-do-proprietario',
            ])
            ->assertRedirect(route('panel.login'));

        $this->assertSame(0, AdminSession::query()->count());
        $this->assertNull(session('panel_token'));
    }

    public function testLoginExigeMfaENaoCriaCredencialDeCliente(): void
    {
        $secret = Totp::generateSecret();
        $admin = $this->admin('com-mfa@example.test', $secret, true);

        $this->get('/painel')->assertRedirect(route('panel.login'));

        $response = $this->post(route('panel.login.submit'), [
            'email' => $admin->email,
            'password' => 'senha-do-proprietario',
            'mfa_code' => Totp::code($secret),
        ]);
        $response->assertRedirect(route('panel.dashboard'));

        $this->assertSame(1, AdminSession::query()->count());
        $this->assertNotNull(session('panel_token'));
        $this->assertSame(0, \App\Models\Investor::query()->count());
        $this->get('/painel')->assertOk()->assertSee('Visão geral do piloto');
    }

    public function testTodasAsPaginasDoPainelRenderizam(): void
    {
        $admin = $this->admin();
        $investor = $this->investor($admin);
        $this->subscription($investor);
        $client = $this->withSession(['panel_token' => $this->sessionFor($admin)]);

        foreach ([
            route('panel.dashboard') => 'Visão geral do piloto',
            route('panel.clients') => 'Clientes fictícios',
            route('panel.client', $investor->id) => 'Investidor Piloto',
            route('panel.reviews') => 'Fila de revisão humana',
            route('panel.billing') => 'Planos e cobrança',
            route('panel.metrics') => 'Métricas do proprietário',
            route('panel.client.data', $investor->id) => 'Dados do titular',
        ] as $url => $heading) {
            $client->get($url)->assertOk()->assertSee($heading);
        }
    }

    public function testContaDesativadaNaoAcessaPainel(): void
    {
        $admin = $this->admin();
        $token = $this->sessionFor($admin);

        $admin->forceFill(['active' => false])->save();

        $this->withSession(['panel_token' => $token])
            ->get('/painel')
            ->assertRedirect(route('panel.login'));

        $this->assertNull(session('panel_token'));
    }

    public function testSessaoRevogadaNaoAcessaPainel(): void
    {
        $admin = $this->admin();
        $token = $this->sessionFor($admin);
        AdminSession::query()->where('token_hash', hash('sha256', $token))
            ->update(['revoked_at' => now()]);

        $this->withSession(['panel_token' => $token])
            ->get('/painel')
            ->assertRedirect(route('panel.login'));
    }

    public function testCadastroDeClienteFicticioPeloPainel(): void
    {
        $admin = $this->admin();
        $this->withSession(['panel_token' => $this->sessionFor($admin)])
            ->post(route('panel.clients.store'), [
                'full_name' => 'Cliente Fictício',
                'phone_e164' => '+5575992605555',
                'email' => 'ficticio@example.test',
                'profession' => 'Professor',
                'income_monthly_brl' => '3200.00',
                'source_note' => 'gerado para piloto',
            ])
            ->assertRedirect();

        $investor = Investor::query()->firstOrFail();
        $this->assertSame($admin->id, $investor->created_by_admin_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'investor.created',
            'subject_id' => $investor->id,
        ]);
    }

    public function testAtivacaoSemOptinFicaBloqueadaNoPainel(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $investor = $this->investor($admin);
        $this->subscription($investor);

        $this->withSession(['panel_token' => $this->sessionFor($admin)])
            ->post(route('panel.client.activate', $investor->id))
            ->assertRedirect();

        $this->assertSame(0, \App\Models\ActivationRequest::query()->count());
        $this->assertStringContainsString(
            'Opt-in ausente',
            session('panel_error') ?? '',
        );
    }

    public function testBaixaManualAuditadaPeloPainel(): void
    {
        $admin = $this->admin();
        $investor = $this->investor($admin);
        $subscription = $this->subscription($investor, 'past_due', 'suspended');
        $payment = $this->payment($subscription);

        $this->withSession(['panel_token' => $this->sessionFor($admin)])
            ->post(route('panel.payment.settle', $payment->id), [
                'evidence_uri' => 'piloto://comprovante/123',
            ])
            ->assertRedirect();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->reconciled_at);
        $this->assertSame('active', $subscription->fresh()->access_status);
        $audit = AuditEvent::query()->where('action', 'billing.manual_settlement')->firstOrFail();
        $this->assertSame('piloto://comprovante/123', $audit->metadata['evidence_uri']);
    }

    public function testIsolamentoDoProprietarioNosDadosDoCliente(): void
    {
        $owner = $this->admin();
        $other = $this->admin('outro@example.test', null, false, false);
        $mine = $this->investor($owner);
        $theirs = $this->investor($other);
        $token = $this->sessionFor($owner);

        $this->withSession(['panel_token' => $token])
            ->get(route('panel.client.data', $mine->id))
            ->assertOk();

        $this->withSession(['panel_token' => $token])
            ->get(route('panel.client.data', $theirs->id))
            ->assertForbidden();
    }

    public function testMetricasSaaSESeparadasDoPatrimonioNaTela(): void
    {
        $admin = $this->admin();
        $investor = $this->investor($admin);
        $subscription = $this->subscription($investor);
        PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => 'piloto-1',
            'installment_number' => 1,
            'amount_cents' => 2990,
            'due_at' => new DateTimeImmutable('2026-12-01T12:00:00+00:00'),
            'status' => 'paid',
            'paid_at' => now(),
            'reconciled_at' => now(),
        ]);
        \App\Models\PortfolioEvent::query()->create([
            'id' => (string) Str::uuid(),
            'portfolio_id' => \App\Models\Portfolio::query()->firstOrCreate(
                ['investor_id' => $investor->id],
                ['id' => (string) Str::uuid()],
            )->id,
            'investor_id' => $investor->id,
            'event_type' => 'DEPOSIT',
            'external_id' => 'piloto-deposito-1',
            'amount_cents' => 100000,
            'affects_positions' => true,
            'occurred_at' => now(),
        ]);

        $this->withSession(['panel_token' => $this->sessionFor($admin)])
            ->get(route('panel.metrics'))
            ->assertOk()
            ->assertSee('Receita SaaS')
            ->assertSee('Patrimônio acompanhado')
            ->assertSee('29,90')
            ->assertSee('1.000,00');
    }

    private function admin(
        ?string $email = null,
        ?string $secret = null,
        bool $mfa = false,
        bool $active = true,
    ): AdminUser {
        return AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => $email ?? 'owner-'.Str::lower(Str::random(6)).'@example.test',
            'password_hash' => Hash::make('senha-do-proprietario'),
            'mfa_secret_encrypted' => encrypt($secret ?? Totp::generateSecret()),
            'mfa_enabled' => $mfa,
            'active' => $active,
        ]);
    }

    private function sessionFor(AdminUser $admin): string
    {
        $token = Str::random(64);
        AdminSession::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'mfa_completed_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        return $token;
    }

    private function investor(AdminUser $admin, ?string $ownerId = null): Investor
    {
        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $ownerId ?? $admin->id,
            'full_name' => 'Investidor Piloto',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'i'.Str::lower(Str::random(6)).'@example.test',
        ]);
    }

    private function subscription(Investor $investor, string $status = 'active', string $access = 'active'): Subscription
    {
        return Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => $status,
            'access_status' => $access,
            'due_at' => new DateTimeImmutable('2026-12-01T12:00:00+00:00'),
        ]);
    }

    private function payment(Subscription $subscription): PaymentRecord
    {
        return PaymentRecord::query()->create([
            'id' => (string) Str::uuid(),
            'subscription_id' => $subscription->id,
            'provider_ref' => 'piloto-'.Str::random(6),
            'installment_number' => 1,
            'amount_cents' => 2990,
            'due_at' => new DateTimeImmutable('2026-12-01T12:00:00+00:00'),
            'status' => 'processing',
        ]);
    }
}
