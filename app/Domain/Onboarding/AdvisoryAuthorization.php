<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\AuditEvent;
use App\Models\SuitabilityAssessment;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A3/RB-003: sem estrutura autorizada e suitability vigente, nenhum instrumento é nomeado. */
final class AdvisoryAuthorization
{
    public function canRecommend(SuitabilityAssessment $assessment, DateTimeImmutable $now): bool
    {
        if (config('compliance.advisory_authorized', false) !== true) {
            return false;
        }

        if ($assessment->status !== 'valid' || $assessment->classification === 'incomplete') {
            return false;
        }

        if ($assessment->expires_at !== null && $assessment->expires_at <= $now) {
            return false;
        }

        $answers = is_array($assessment->answers) ? $assessment->answers : [];
        $restrictions = $answers['restrictions'] ?? [];

        return ! is_array($restrictions) || $restrictions === [];
    }

    public function assertCanRecommend(SuitabilityAssessment $assessment, DateTimeImmutable $now): void
    {
        if ($this->canRecommend($assessment, $now)) {
            return;
        }

        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'compliance-gate',
            'action' => 'compliance.recommendation_blocked',
            'subject_type' => 'suitability_assessment',
            'subject_id' => $assessment->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => ['reason' => 'unauthorized_or_stale_profile'],
            'occurred_at' => $now,
        ]);

        throw new ComplianceException('Recomendação individualizada bloqueada por compliance.');
    }
}
