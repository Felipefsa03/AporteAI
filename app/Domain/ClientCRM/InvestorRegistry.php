<?php

declare(strict_types=1);

namespace App\Domain\ClientCRM;

use App\Models\AuditEvent;
use App\Models\Investor;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Trilha de cadastro feita exclusivamente pelo proprietario.
 * Nenhuma credencial web e criada para o investidor (A10).
 */
final class InvestorRegistry
{
    private const STATUSES = ['draft', 'qualified', 'proposal', 'closed', 'archived'];

    /** @param array<string, mixed> $input */
    public function create(array $input, string $adminId, string $traceId): Investor
    {
        $data = $this->normalize($input);

        $investor = Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $adminId,
        ] + $data);

        $this->audit('investor.created', $adminId, $investor->id, $traceId, []);

        return $investor;
    }

    /** @param array<string, mixed> $input */
    public function update(Investor $investor, array $input, string $adminId, string $traceId): Investor
    {
        $data = $this->normalize($input);
        $previousStatus = $investor->lifecycle_status;

        $investor->fill($data)->save();

        $this->audit('investor.updated', $adminId, $investor->id, $traceId, [
            'lifecycle_status_from' => $previousStatus,
            'lifecycle_status_to' => $investor->lifecycle_status,
        ]);

        return $investor;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
            throw new InvalidArgumentException('full_name deve ter entre 3 e 120 caracteres.');
        }

        $phone = trim((string) ($input['phone_e164'] ?? ''));
        if (preg_match('/^\+[1-9]\d{7,14}$/', $phone) !== 1) {
            throw new InvalidArgumentException('phone_e164 deve estar no formato E.164.');
        }

        $email = trim((string) ($input['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('email invalido.');
        }

        $status = (string) ($input['lifecycle_status'] ?? 'draft');
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('lifecycle_status invalido.');
        }

        $income = $input['income_monthly_brl'] ?? null;
        if ($income !== null && (! is_numeric($income) || (float) $income < 0)) {
            throw new InvalidArgumentException('income_monthly_brl deve ser um numero nao negativo.');
        }

        $profession = $this->nullableString($input['profession'] ?? null, 120, 'profession');
        $sourceNote = $this->nullableString($input['source_note'] ?? null, 1000, 'source_note');

        return [
            'full_name' => $fullName,
            'phone_e164' => $phone,
            'email' => $email,
            'profession' => $profession,
            'income_monthly_brl' => $income === null ? null : (string) (float) $income,
            'lifecycle_status' => $status,
            'source_note' => $sourceNote,
        ];
    }

    private function nullableString(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) $value);
        if (mb_strlen($text) > $max) {
            throw new InvalidArgumentException($field." deve ter no maximo {$max} caracteres.");
        }

        return $text;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $action, string $adminId, string $investorId, string $traceId, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'admin_user',
            'actor_id' => $adminId,
            'action' => $action,
            'subject_type' => 'investor',
            'subject_id' => $investorId,
            'trace_id' => $traceId,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
