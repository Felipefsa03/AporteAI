<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

use App\Models\AdminUser;
use App\Models\AuditEvent;
use Illuminate\Support\Str;

/** Login Google somente para a conta única do proprietário; nunca cria ou autentica investidores. */
final class OwnerGoogleLogin
{
    public function __construct(
        private readonly GoogleVerifier $verifier,
        private readonly OwnerSessionIssuer $sessions,
    ) {
    }

    /** @return array{token: string, expiresAt: \DateTimeImmutable} */
    public function login(string $idToken, string $traceId): array
    {
        $identity = $this->verifier->verify($idToken);
        $user = AdminUser::query()->where('email', $identity->email)->first();

        if ($user === null || ! $user->active) {
            $this->audit('admin.login.failed', $user?->id ?? (string) Str::uuid(), $traceId, ['method' => 'google']);
            throw new InvalidCredentials('Conta Google não autorizada para o painel.');
        }

        if (is_string($user->google_sub) && $user->google_sub !== '' && ! hash_equals($user->google_sub, $identity->subject)) {
            $this->audit('admin.login.failed', $user->id, $traceId, ['method' => 'google', 'reason' => 'subject_mismatch']);
            throw new InvalidCredentials('Conta Google não autorizada para o painel.');
        }

        if (! is_string($user->google_sub) || $user->google_sub === '') {
            $user->forceFill(['google_sub' => $identity->subject])->save();
        }

        return $this->sessions->issue($user, $traceId, 'google');
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $action, string $actorId, string $traceId, array $metadata = []): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'admin_user',
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => 'admin_user',
            'subject_id' => $actorId,
            'trace_id' => $traceId,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
