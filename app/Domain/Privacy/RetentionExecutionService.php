<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\DataRightsRequest;
use App\Models\Investor;
use App\Models\ReviewCase;
use App\Models\SuitabilityAssessment;
use App\Models\Subscription;
use App\Models\WhatsAppOptin;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A9/A14/RB-014: executa retenção aprovada, com hold, revisão humana e replay idempotente. */
final class RetentionExecutionService
{
    /** @return array<string, mixed> */
    public function execute(DataRightsRequest $request, bool $dryRun, DateTimeImmutable $now): array
    {
        if ($request->type !== 'deletion') {
            throw new DataRightsException('Somente solicitações de exclusão podem ser executadas.');
        }

        // Replay precisa enxergar o titular após a anonimização (soft delete).
        $investor = Investor::withTrashed()->findOrFail($request->investor_id);

        if ($request->status === 'executed' && $request->resolved_at !== null) {
            $this->audit($investor, $request, 'data_rights.retention_replay_ignored', $now, [
                'status' => 'already_executed',
                'dry_run' => $dryRun,
            ]);

            return $this->replayReport($investor, $request);
        }

        $approved = (bool) config('retention.policy.approved', false);
        if (! $dryRun && ! $approved) {
            throw new DataRightsException('Política de retenção não aprovada; apenas simulação permitida.');
        }

        $review = $this->approvedReview($request);
        if (! $dryRun && (bool) config('retention.require_human_review', true) && $review === null) {
            throw new DataRightsException('Execução exige revisão humana aprovada com papel autorizado.');
        }

        $holdActive = (bool) config('retention.legal_hold.active', false);
        $heldCategories = (array) config('retention.legal_hold.categories', []);
        $plan = $this->plan($holdActive, $heldCategories);
        $report = $this->report($investor, $request, $dryRun, $dryRun ? 'simulated' : 'executed', $plan, $holdActive, $review);

        if ($dryRun) {
            $this->audit($investor, $request, 'data_rights.retention_simulated', $now, $this->auditMetadata($report));

            return $report;
        }

        DB::transaction(function () use ($investor, $request, $plan, $report, $now): void {
            $this->anonymize($investor, $plan, $now);

            $request->forceFill([
                'status' => 'executed',
                'reason' => 'executed_under_approved_policy',
                'resolved_at' => $now,
            ])->save();

            $this->audit($investor, $request, 'data_rights.retention_executed', $now, $this->auditMetadata($report));
        });

        return $report;
    }

    /** @return array<string, array<string, mixed>> */
    private function plan(bool $holdActive, array $heldCategories): array
    {
        $plan = [];
        foreach ((array) config('retention.policy.categories', []) as $category => $definition) {
            $action = (string) ($definition['action'] ?? 'retain');
            $held = $holdActive && in_array($category, $heldCategories, true);

            $plan[$category] = [
                'action' => $held ? 'retained_legal_hold' : ($action === 'retain' ? 'retained_obligation' : 'anonymized'),
                'basis' => (string) ($definition['basis'] ?? ''),
                'legal_hold' => $held,
            ];
        }

        return $plan;
    }

    /** @param array<string, array<string, mixed>> $plan */
    private function anonymize(Investor $investor, array $plan, DateTimeImmutable $now): void
    {
        if (($plan['identity']['action'] ?? null) === 'anonymized') {
            $investor->forceFill([
                'full_name' => 'TITULAR-ANONIMIZADO',
                'phone_e164' => 'anonymized:'.(string) Str::uuid(),
                'email' => 'anonymized-'.(string) Str::uuid().'@invalid.test',
                'profession' => null,
                'income_monthly_brl' => null,
                'source_note' => null,
                'lifecycle_status' => 'anonymized',
                'deleted_at' => $now,
                'updated_at' => $now,
            ])->save();
        }

        if (($plan['suitability']['action'] ?? null) === 'anonymized') {
            SuitabilityAssessment::query()
                ->where('investor_id', $investor->id)
                ->update([
                    'answers' => [],
                    'status' => 'anonymized',
                    'updated_at' => $now,
                ]);
        }

        if (($plan['consent']['action'] ?? null) === 'anonymized') {
            WhatsAppOptin::query()
                ->where('investor_id', $investor->id)
                ->update([
                    'phone_e164' => 'anonymized:'.(string) Str::uuid(),
                    'evidence_uri' => 'redacted:consent',
                    'updated_at' => $now,
                ]);
        }
    }

    private function approvedReview(DataRightsRequest $request): ?ReviewCase
    {
        $review = ReviewCase::query()
            ->where('investor_id', $request->investor_id)
            ->where('subject_type', 'data_rights_request')
            ->where('subject_id', $request->id)
            ->where('status', 'resolved')
            ->whereIn('resolution_reason', (array) config('retention.approved_outcomes', []))
            ->latest('resolved_at')
            ->first();

        if ($review === null || $review->resolved_by_admin_id === null) {
            return null;
        }

        $admin = AdminUser::query()->find($review->resolved_by_admin_id);
        if ($admin === null || ! in_array((string) $admin->role, (array) config('review.authorized_roles', []), true)) {
            return null;
        }

        return $review;
    }

    /** @param array<string, array<string, mixed>> $plan */
    private function report(
        Investor $investor,
        DataRightsRequest $request,
        bool $dryRun,
        string $status,
        array $plan,
        bool $holdActive,
        ?ReviewCase $review,
    ): array {

        $retained = [];
        $anonymized = [];
        foreach ($plan as $category => $detail) {
            if (str_starts_with((string) $detail['action'], 'retained')) {
                $retained[] = $category;
            } else {
                $anonymized[] = $category;
            }
        }

        $subscription = Subscription::query()
            ->where('investor_id', $investor->id)
            ->latest('started_at')
            ->first();

        return [
            'request_id' => $request->id,
            'investor_id' => $investor->id,
            'status' => $status,
            'dry_run' => $dryRun,
            'policy_approved' => (bool) config('retention.policy.approved', false),
            'policy_version' => (string) config('retention.policy.version', 'unknown'),
            'legal_hold_active' => $holdActive,
            'categories' => $plan,
            'anonymized_categories' => $anonymized,
            'retained_categories' => $retained,
            'review_outcome' => $review?->resolution_reason,
            'billing' => [
                'access_status' => $subscription?->access_status ?? 'none',
                'unchanged_by_retention' => true,
            ],
            'audit_events_preserved' => AuditEvent::query()->count(),
        ];
    }

    /** @param array<string, mixed> $report */
    private function replayReport(Investor $investor, DataRightsRequest $request): array
    {
        $audit = AuditEvent::query()
            ->where('action', 'data_rights.retention_executed')
            ->where('subject_type', 'data_rights_request')
            ->where('subject_id', $request->id)
            ->latest('occurred_at')
            ->first();

        $metadata = is_array($audit?->metadata) ? $audit->metadata : [];

        return array_replace([
            'request_id' => $request->id,
            'investor_id' => $investor->id,
            'status' => 'already_executed',
            'replayed' => true,
            'dry_run' => false,
            'policy_approved' => true,
            'policy_version' => (string) config('retention.policy.version', 'unknown'),
            'categories' => [],
            'anonymized_categories' => [],
            'retained_categories' => [],
            'billing' => [
                'access_status' => Subscription::query()->where('investor_id', $investor->id)->latest('started_at')->first()?->access_status ?? 'none',
                'unchanged_by_retention' => true,
            ],
        ], $metadata, [
            'status' => 'already_executed',
            'replayed' => true,
            'request_id' => $request->id,
            'investor_id' => $investor->id,
        ]);
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function auditMetadata(array $report): array
    {
        return [
            'request_id' => $report['request_id'],
            'policy_version' => $report['policy_version'],
            'status' => $report['status'],
            'dry_run' => $report['dry_run'],
            'legal_hold_active' => $report['legal_hold_active'],
            'anonymized_categories' => $report['anonymized_categories'],
            'retained_categories' => $report['retained_categories'],
            'audit_events_preserved' => $report['audit_events_preserved'],
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Investor $investor, DataRightsRequest $request, string $action, DateTimeImmutable $now, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'retention-service',
            'action' => $action,
            'subject_type' => 'data_rights_request',
            'subject_id' => $request->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => $now,
        ]);
    }
}
