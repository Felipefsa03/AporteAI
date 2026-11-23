<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

use App\Models\AdminMfaChallenge;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Support\SmsGateway;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** A2F da conta única do proprietário: código de 6 dígitos enviado por SMS ao número cadastrado. */
final class OwnerMfaService
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly OwnerSessionIssuer $sessions,
    ) {
    }

    /** @return array{challengeToken: string, expiresAt: DateTimeImmutable, maskedPhone: string} */
    public function start(string $email, string $password, string $traceId): array
    {
        $user = AdminUser::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($user === null || ! Hash::check($password, $user->password_hash)) {
            $this->audit('admin.login.failed', $user?->id ?? (string) Str::uuid(), $traceId);
            throw new InvalidCredentials('Credenciais inválidas.');
        }

        if (! $user->active) {
            $this->audit('admin.login.blocked', $user->id, $traceId);
            throw new InvalidCredentials('Conta do proprietário desativada.');
        }

        $phone = $this->phone($user);
        $code = (string) random_int(100000, 999999);
        $token = Str::random(64);
        $ttl = max(60, (int) config('pilot.owner_mfa.code_ttl_seconds', 300));
        $expiresAt = now()->addSeconds($ttl)->toDateTimeImmutable();

        AdminMfaChallenge::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'code_hash' => $this->hashCode($code),
            'attempts' => 0,
            'expires_at' => $expiresAt,
        ]);

        $this->sms->send($phone, 'AporteAI: seu codigo de acesso e '.$code.'. Valido por '.intdiv($ttl, 60).' minutos. Nao compartilhe.');
        $this->audit('admin.mfa.sms_sent', $user->id, $traceId, ['phone' => $this->mask($phone)]);

        return [
            'challengeToken' => $token,
            'expiresAt' => $expiresAt,
            'maskedPhone' => $this->mask($phone),
        ];
    }

    /** @return array{token: string, expiresAt: DateTimeImmutable} */
    public function verify(string $challengeToken, string $code, string $traceId): array
    {
        $hash = hash('sha256', $challengeToken);

        /*
         * A validação e o consume acontecem em uma transação. O update condicional por
         * consumed_at é a cerca contra dois requests concorrentes com o mesmo código:
         * apenas aquele que alterar exatamente uma linha emite sessão.
         */
        $outcome = DB::transaction(function () use ($hash, $code, $traceId): array {
            $challenge = AdminMfaChallenge::query()->where('token_hash', $hash)->first();

            if ($challenge === null || $challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
                $this->audit(
                    'admin.mfa.failed',
                    $challenge?->admin_user_id ?? (string) Str::uuid(),
                    $traceId,
                    ['reason' => 'expired'],
                );

                return ['error' => new MfaChallengeExpired('Validação expirada. Solicite um novo código.')];
            }

            $user = AdminUser::query()->findOrFail($challenge->admin_user_id);
            if (! $user->active) {
                $this->audit('admin.login.blocked', $user->id, $traceId);

                return ['error' => new InvalidCredentials('Conta do proprietário desativada.')];
            }

            $maxAttempts = max(1, (int) config('pilot.owner_mfa.max_attempts', 5));
            if ($challenge->attempts >= $maxAttempts) {
                $this->audit('admin.mfa.failed', $user->id, $traceId, ['reason' => 'too_many_attempts']);

                return ['error' => new InvalidMfaCode('Código inválido.')];
            }

            if (! hash_equals($challenge->code_hash, $this->hashCode($code))) {
                $challenge->increment('attempts');
                $this->audit('admin.mfa.failed', $user->id, $traceId);

                return ['error' => new InvalidMfaCode('Código inválido.')];
            }

            $claimed = AdminMfaChallenge::query()
                ->whereKey($challenge->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            if ($claimed !== 1) {
                $this->audit('admin.mfa.failed', $user->id, $traceId, ['reason' => 'already_consumed']);

                return ['error' => new MfaChallengeExpired('Validação expirada. Solicite um novo código.')];
            }

            $this->audit('admin.mfa.sms_verified', $user->id, $traceId);

            return ['result' => $this->sessions->issue($user, $traceId, 'password+sms')];
        });

        $error = $outcome['error'] ?? null;
        if ($error instanceof InvalidCredentials) {
            throw $error;
        }

        /** @var array{token: string, expiresAt: DateTimeImmutable} $result */
        $result = $outcome['result'];

        return $result;
    }

    private function phone(AdminUser $user): string
    {
        $phone = is_string($user->mfa_phone_e164) && $user->mfa_phone_e164 !== ''
            ? $user->mfa_phone_e164
            : (string) config('pilot.owner_mfa.phone', '');

        if (! preg_match('/^\+[1-9]\d{8,14}$/', $phone)) {
            throw new InvalidCredentials('Número de A2F do proprietário não configurado.');
        }

        return $phone;
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function mask(string $phone): string
    {
        return substr($phone, 0, 3).str_repeat('*', max(0, strlen($phone) - 6)).substr($phone, -3);
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
