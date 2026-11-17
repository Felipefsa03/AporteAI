<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\ReviewCase;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A8: casos críticos param; decisão humana exige papel, motivo e trilha, sem burlar bloqueios. */
final class ReviewService
{
    /**
     * @param array<string, mixed> $snapshot
     * @param list<array<string, mixed>> $sources
     * @param list<array<string, mixed>> $rules
     */
    public function openCritical(
        Investor $investor,
        string $subjectType,
        string $subjectId,
        string $reason,
        array $snapshot,
        array $sources,
        array $rules,
        DateTimeImmutable $now,
    ): ReviewCase {
        $case = ReviewCase::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'reason' => $reason,
            'status' => 'pending',
            'critical' => true,
            'snapshot' => $snapshot,
            'sources' => $sources,
            'rules' => $rules,
            'created_at' => $now,
        ]);

        $this->audit($case, 'review.case_opened', $now, ['reason' => $reason]);

        return $case;
    }

    public function resolve(
        ReviewCase $case,
        AdminUser $admin,
        string $outcome,
        string $justification,
        DateTimeImmutable $now,
    ): ReviewCase {
        if (! in_array((string) $admin->role, (array) config('review.authorized_roles', []), true)) {
            throw new ReviewException('Papel sem autorização para resolver revisão crítica.');
        }

        if (in_array($outcome, (array) config('review.blocked_outcomes', []), true)) {
            throw new ReviewException('Decisão humana não pode liberar bloqueio crítico nem executar ordem.');
        }

        if (trim($justification) === '') {
            throw new ReviewException('Resolução exige motivo registrado.');
        }

        if ($case->status !== 'pending') {
            throw new ReviewException('Caso de revisão já foi resolvido.');
        }

        $case->forceFill([
            'status' => 'resolved',
            'justification' => $justification,
            'resolved_by_admin_id' => $admin->id,
            'resolution_reason' => $outcome,
            'resolved_at' => $now,
        ])->save();

        $this->audit($case, 'review.case_resolved', $now, [
            'outcome' => $outcome,
            'admin_id' => $admin->id,
        ]);

        return $case;
    }

    public function assertNoPendingBlock(ReviewCase $case): void
    {
        if ($case->critical && $case->status === 'pending') {
            throw new ReviewException('Bloqueio crítico pendente: nenhuma ordem pode ser executada.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(ReviewCase $case, string $action, DateTimeImmutable $now, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'review-service',
            'action' => $action,
            'subject_type' => 'review_case',
            'subject_id' => $case->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => $now,
        ]);
    }
}
