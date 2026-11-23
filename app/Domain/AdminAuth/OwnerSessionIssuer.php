<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** Emite a única sessão administrativa permitida, sempre com trilha de auditoria. */
final class OwnerSessionIssuer
{
    private const SESSION_TTL_HOURS = 8;

    /** @return array{token: string, expiresAt: DateTimeImmutable} */
    public function issue(AdminUser $user, string $traceId, string $method): array
    {
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

        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'admin_user',
            'actor_id' => $user->id,
            'action' => 'admin.login.succeeded',
            'subject_type' => 'admin_user',
            'subject_id' => $user->id,
            'trace_id' => $traceId,
            'metadata' => ['method' => $method],
            'occurred_at' => now(),
        ]);

        return ['token' => $token, 'expiresAt' => $expiresAt];
    }
}
