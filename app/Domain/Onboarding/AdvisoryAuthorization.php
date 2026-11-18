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
    public function __construct(private readonly SuitabilityPolicy $policy)
    {
    }

    public function canRecommend(SuitabilityAssessment $assessment, DateTimeImmutable $now): bool
    {
        return $this->blockReason($assessment, $now) === null;
    }

    public function assertCanRecommend(SuitabilityAssessment $assessment, DateTimeImmutable $now): void
    {
        $reason = $this->blockReason($assessment, $now);

        if ($reason === null) {
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
            'metadata' => ['reason' => $reason],
            'occurred_at' => $now,
        ]);

        throw new ComplianceException('Recomendação individualizada bloqueada por compliance.');
    }

    private function blockReason(SuitabilityAssessment $assessment, DateTimeImmutable $now): ?string
    {
        if (config('compliance.advisory_authorized', false) !== true) {
            return 'advisory_not_authorized';
        }

        if ($assessment->status !== 'valid') {
            return 'profile_not_valid';
        }

        if ($assessment->assessed_at === null || $assessment->assessed_at > $now) {
            return 'profile_not_assessed';
        }

        // Validez explicita e obrigatoria: ausencia de prazo nunca e tratada como vigor.
        if ($assessment->expires_at === null) {
            return 'profile_without_expiry';
        }

        if ($assessment->expires_at <= $now) {
            return 'profile_expired';
        }

        $answers = $assessment->answers;
        if (! is_array($answers)) {
            return 'profile_answers_malformed';
        }

        $classification = $this->policy->classify($answers);
        if ($classification === 'incomplete') {
            return 'profile_schema_invalid';
        }

        if (! in_array($assessment->classification, SuitabilityPolicy::PROFILES, true)) {
            return 'profile_unknown';
        }

        if ($assessment->classification !== $classification) {
            return 'profile_classification_mismatch';
        }

        if (! $this->policy->hasNoRestrictions($answers)) {
            return 'profile_restrictions_invalid';
        }

        return null;
    }
}
