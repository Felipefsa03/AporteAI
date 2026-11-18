<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AdminAuthenticator
{
    private const SESSION_TTL_HOURS = 8;

    /**
     * Autentica a conta unica do proprietario e emite uma sessao somente apos o MFA.
     *
     * @return array{token: string, expiresAt: DateTimeImmutable}
     */
    public function login(string $email, string $password, ?string $mfaCode, string $traceId): array
    {
        $user = AdminUser::query()
            ->where('email', strtolower(trim($email)))
            ->first();

        if ($user === null || ! Hash::check($password, $user->password_hash)) {
            $this->audit('admin.login.failed', $user?->id ?? (string) Str::uuid(), $traceId);
            throw new InvalidCredentials('Credenciais invalidas.');
        }

        if (! $user->active) {
            $this->audit('admin.login.blocked', $user->id, $traceId);
            throw new InvalidCredentials('Conta do proprietario desativada.');
        }

        if ($user->mfa_enabled) {
            // encrypt() serializa o valor; decryptString() nao desfaz a serializacao.
            $secret = Crypt::decrypt($user->mfa_secret_encrypted);
            if ($mfaCode === null || ! Totp::verify($secret, $mfaCode)) {
                $this->audit('admin.mfa.failed', $user->id, $traceId);
                throw new InvalidCredentials('Codigo MFA invalido.');
            }
        }

        $token = Str::random(64);
        $expiresAt = now()->addHours(self::SESSION_TTL_HOURS)->toDateTimeImmutable();

        AdminSession::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'mfa_completed_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit('admin.login.succeeded', $user->id, $traceId);

        return ['token' => $token, 'expiresAt' => $expiresAt];
    }

    public function revoke(string $tokenHash, string $traceId): void
    {
        $session = AdminSession::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();

        if ($session === null) {
            return;
        }

        $session->forceFill(['revoked_at' => now()])->save();
        $this->audit('admin.logout.succeeded', $session->admin_user_id, $traceId);
    }

    private function audit(string $action, string $actorId, string $traceId): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'admin_user',
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => 'admin_user',
            'subject_id' => $actorId,
            'trace_id' => $traceId,
            'metadata' => null,
            'occurred_at' => now(),
        ]);
    }
}
