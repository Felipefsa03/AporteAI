<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\AdminAuth\GoogleIdentity;
use App\Domain\AdminAuth\GoogleVerifier;
use App\Models\AdminMfaChallenge;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Support\LogSmsGateway;
use App\Support\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/** AUTH: conta única do proprietário, Google configurável e A2F por SMS antes da sessão. */
final class OwnerAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Número fictício do piloto; o número do bot nunca é padrão de A2F do proprietário. */
    private const OWNER_PHONE = '+55759926990001';

    private object $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new class implements SmsGateway
        {
            /** @var array<int, array{to:string,message:string}> */
            public array $messages = [];

            public function send(string $toE164, string $message): void
            {
                $this->messages[] = ['to' => $toE164, 'message' => $message];
            }
        };
        $this->app->instance(SmsGateway::class, $this->sms);
    }

    public function testExisteApenasUmaContaAtivaDoProprietario(): void
    {
        $this->admin();
        $second = $this->admin('segundo@example.test', false);

        self::assertFalse($second->active);
        self::assertSame(1, AdminUser::query()->where('active', true)->count());
        self::assertSame(0, \App\Models\Investor::query()->count());
    }

    public function testSenhaESmsEmiteSessaoComAuditoriaECodigoDeUsoUnico(): void
    {
        $admin = $this->admin();

        $this->from(route('panel.login'))
            ->post(route('panel.login.submit'), [
                'email' => $admin->email,
                'password' => 'senha-do-proprietario',
            ])
            ->assertRedirect(route('panel.sms'));

        self::assertCount(1, $this->sms->messages);
        self::assertSame(self::OWNER_PHONE, $this->sms->messages[0]['to']);
        $code = $this->codeFrom($this->sms->messages[0]['message']);
        self::assertNotNull(session('panel_challenge_token'));
        self::assertNull(session('panel_token'));
        self::assertSame(0, AdminSession::query()->count());

        $wrongCode = $code === '000000' ? '111111' : '000000';
        $this->from(route('panel.sms'))
            ->post(route('panel.sms.verify'), ['code' => $wrongCode])
            ->assertRedirect(route('panel.sms'))
            ->assertSessionHas('panel_error', 'Código inválido.');
        self::assertNotNull(session('panel_challenge_token'));
        self::assertNull(session('panel_token'));
        self::assertSame(0, AdminSession::query()->count());
        self::assertTrue(AuditEvent::query()->where('action', 'admin.mfa.failed')->exists());

        $this->post(route('panel.sms.verify'), ['code' => $code])
            ->assertRedirect(route('panel.dashboard'));

        self::assertNotNull(session('panel_token'));
        self::assertNull(session('panel_challenge_token'));
        self::assertSame(1, AdminSession::query()->whereNull('revoked_at')->count());
        self::assertTrue(AuditEvent::query()->where('action', 'admin.mfa.sms_sent')->exists());
        self::assertTrue(AuditEvent::query()->where('action', 'admin.mfa.sms_verified')->exists());
        self::assertTrue(AuditEvent::query()
            ->where('action', 'admin.login.succeeded')
            ->where('metadata->method', 'password+sms')
            ->exists());

        $challenge = AdminMfaChallenge::query()->firstOrFail();
        self::assertNotNull($challenge->consumed_at);
    }

    public function testCodigoExpiradoNaoCriaSessao(): void
    {
        $admin = $this->admin();

        $this->post(route('panel.login.submit'), [
            'email' => $admin->email,
            'password' => 'senha-do-proprietario',
        ])->assertRedirect(route('panel.sms'));

        AdminMfaChallenge::query()->update(['expires_at' => now()->subMinute()]);
        $code = $this->codeFrom($this->sms->messages[0]['message']);
        self::assertNotSame('', $code, $this->sms->messages[0]['message']);
        self::assertNotNull(session('panel_challenge_token'));

        $this->from(route('panel.sms'))
            ->post(route('panel.sms.verify'), ['code' => $code])
            ->assertRedirect(route('panel.login'))
            ->assertSessionHas('panel_error', 'Validação expirada. Solicite um novo código.');

        self::assertNull(session('panel_challenge_token'));
        self::assertNull(session('panel_token'));
        self::assertSame(0, AdminSession::query()->count());
    }

    public function testApiDistingueCodigoInvalidoDeDesafioExpirado(): void
    {
        $admin = $this->admin();

        $this->post(route('panel.login.submit'), [
            'email' => $admin->email,
            'password' => 'senha-do-proprietario',
        ])->assertRedirect(route('panel.sms'));

        $challengeToken = (string) session('panel_challenge_token');
        $code = $this->codeFrom($this->sms->messages[0]['message']);
        $wrongCode = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/admin/auth/password/verify', [
            'challenge_token' => $challengeToken,
            'code' => $wrongCode,
            'trace_id' => (string) Str::uuid(),
        ])->assertStatus(401)->assertJsonPath('message', 'Código inválido.');

        AdminMfaChallenge::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/admin/auth/password/verify', [
            'challenge_token' => $challengeToken,
            'code' => $code,
            'trace_id' => (string) Str::uuid(),
        ])->assertStatus(410)->assertJsonPath('message', 'Validação expirada. Solicite um novo código.');

        self::assertSame(0, AdminSession::query()->count());
    }

    public function testCodigoConsumidoNaoPodeSerReutilizado(): void
    {
        $admin = $this->admin();

        $this->post(route('panel.login.submit'), [
            'email' => $admin->email,
            'password' => 'senha-do-proprietario',
        ])->assertRedirect(route('panel.sms'));

        $challengeToken = (string) session('panel_challenge_token');
        $code = $this->codeFrom($this->sms->messages[0]['message']);

        $this->post(route('panel.sms.verify'), ['code' => $code])
            ->assertRedirect(route('panel.dashboard'));

        $this->postJson('/api/v1/admin/auth/password/verify', [
            'challenge_token' => $challengeToken,
            'code' => $code,
            'trace_id' => (string) Str::uuid(),
        ])->assertStatus(410);

        self::assertSame(1, AdminSession::query()->whereNull('revoked_at')->count());
    }

    public function testGoogleNaoConfiguradoNaoEmiteSessao(): void
    {
        config()->set('services.google.client_id', '');

        $this->postJson('/api/v1/admin/auth/google', [
            'credential' => 'token-ficticio',
            'trace_id' => (string) Str::uuid(),
        ])->assertStatus(503);

        self::assertSame(0, AdminSession::query()->count());
    }

    public function testGoogleAutorizaSomenteAContaDoProprietario(): void
    {
        $admin = $this->admin();
        $this->app->instance(GoogleVerifier::class, new class($admin->email) implements GoogleVerifier
        {
            public function __construct(private readonly string $ownerEmail)
            {
            }

            public function verify(string $idToken): GoogleIdentity
            {
                if ($idToken === 'owner') {
                    return new GoogleIdentity('google-sub-123', $this->ownerEmail, true);
                }

                return new GoogleIdentity('google-sub-999', 'outro@example.test', true);
            }
        });

        $this->postJson('/api/v1/admin/auth/google', [
            'credential' => 'owner',
            'trace_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonStructure(['token', 'expires_at']);

        self::assertSame(1, AdminSession::query()->count());
        self::assertSame('google-sub-123', $admin->fresh()->google_sub);
        self::assertTrue(AuditEvent::query()
            ->where('action', 'admin.login.succeeded')
            ->where('metadata->method', 'google')
            ->exists());

        $this->postJson('/api/v1/admin/auth/google', [
            'credential' => ' stranger ',
            'trace_id' => (string) Str::uuid(),
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/auth/google', [
            'credential' => 'stranger',
            'trace_id' => (string) Str::uuid(),
        ])->assertStatus(401);

        self::assertSame(1, AdminSession::query()->count());
    }

    public function testNumeroDoBotNaoEhPadraoDeA2f(): void
    {
        self::assertNotSame('+5575992605555', config('pilot.owner_mfa.phone'));
        self::assertStringNotContainsString(
            'OWNER_MFA_PHONE=+5575992605555',
            (string) file_get_contents(base_path('.env.example')),
        );
    }

    public function testTransporteDeLogNaoRegistraOCodigo(): void
    {
        $path = storage_path('logs/sms-auth-test.log');
        @unlink($path);
        config()->set('logging.channels.sms.path', $path);

        try {
            (new LogSmsGateway())->send(self::OWNER_PHONE, 'AporteAI: seu codigo de acesso e 123456.');
            $contents = (string) file_get_contents($path);

            self::assertStringNotContainsString('123456', $contents);
            self::assertStringContainsString('owner_mfa_code', $contents);
            self::assertStringContainsString('[redacted]', $contents);
        } finally {
            @unlink($path);
        }
    }

    public function testLoginMostraOpcoesELogoDaIdentidade(): void
    {
        config()->set('services.google.client_id', '');

        $this->get(route('panel.login'))
            ->assertOk()
            ->assertSee('Entrar com Google')
            ->assertSee('aguardando configuração')
            ->assertSee('enviado por SMS', false)
            ->assertSee('/images/aporteai-logo.png');

        self::assertFileExists(public_path('images/aporteai-logo.png'));
    }

    private function codeFrom(string $message): string
    {
        preg_match('/codigo de acesso e (\d{6})/u', $message, $matches);

        return $matches[1] ?? '';
    }

    private function admin(string $email = 'limalipe355@example.test', bool $active = true): AdminUser
    {
        return AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => $email,
            'password_hash' => Hash::make('senha-do-proprietario'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'mfa_phone_e164' => self::OWNER_PHONE,
            'active' => $active,
        ]);
    }
}
