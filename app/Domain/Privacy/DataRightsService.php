<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Review\ReviewService;
use App\Models\AdvisoryContract;
use App\Models\AuditEvent;
use App\Models\DataRightsRequest;
use App\Models\Investor;
use App\Models\PortfolioEvent;
use App\Models\SuitabilityAssessment;
use App\Models\Subscription;
use App\Models\WhatsAppOptin;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A9/A14/RB-014: direitos do titulares funcionam mesmo durante bloqueio financeiro. */
final class DataRightsService
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /** @return array<string, mixed> */
    public function export(Investor $investor, DateTimeImmutable $now): array
    {
        $data = [];
        foreach ((array) config('retention.exportable', []) as $field) {
            $data[$field] = $investor->{$field};
        }

        $payload = [
            'generated_at' => $now->format(DATE_ATOM),
            'investor_data' => $data,
            'consents' => WhatsAppOptin::query()->where('investor_id', $investor->id)->get([
                'id', 'channel', 'accepted_at', 'revoked_at',
            ])->toArray(),
            'contracts' => AdvisoryContract::query()->where('investor_id', $investor->id)->get([
                'id', 'version', 'status', 'signed_at', 'artifact_uri', 'privacy_notice_uri',
            ])->toArray(),
            'suitability' => SuitabilityAssessment::query()->where('investor_id', $investor->id)->get([
                'id', 'version', 'classification', 'status', 'assessed_at', 'expires_at',
            ])->toArray(),
            'portfolio_events' => PortfolioEvent::query()->where('investor_id', $investor->id)->get([
                'id', 'event_type', 'amount_cents', 'occurred_at',
            ])->toArray(),
        ];

        $this->audit($investor, 'data_rights.exported', $now, []);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function review(Investor $investor, DateTimeImmutable $now): array
    {
        $this->audit($investor, 'data_rights.reviewed', $now, []);

        return [
            'categories' => [
                'identity' => 'nome, telefone e e-mail para identificação e contato',
                'contract' => 'contrato de consultoria e aviso de privacidade versionados',
                'suitability' => 'perfil e respostas usadas nas regras de alocação',
                'portfolio' => 'eventos financeiros confirmados pelo cliente',
                'billing' => 'assinatura e cobrança, separadas do patrimônio',
            ],
            'rights' => ['access', 'correction', 'export', 'review', 'deletion'],
            'retention_days' => (int) config('retention.deletion_grace_days', 90),
            'policy_version' => (string) config('retention.policy.version', 'unknown'),
            'policy_approved' => (bool) config('retention.policy.approved', false),
            'legal_hold_active' => (bool) config('retention.legal_hold.active', false),
            'paid_services_unlocked' => false,
        ];
    }

    public function correct(Investor $investor, string $field, mixed $value, DateTimeImmutable $now): Investor
    {
        if (! in_array($field, (array) config('retention.correctable', []), true)) {
            throw new DataRightsException('Campo não corretível pelo titular.');
        }

        $investor->forceFill([$field => $value])->save();
        // Metadados de auditoria registram apenas o nome do campo, nunca o valor (PII).
        $this->audit($investor, 'data_rights.corrected', $now, ['field' => $field]);

        return $investor;
    }

    public function requestDeletion(Investor $investor, string $reason, DateTimeImmutable $now): DataRightsRequest
    {
        $grace = (int) config('retention.deletion_grace_days', 90);
        $request = DataRightsRequest::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'type' => 'deletion',
            'status' => 'pending',
            'reason' => $reason,
            'scheduled_for' => $now->modify('+'.$grace.' days'),
            'created_at' => $now,
        ]);

        $investor->forceFill(['lifecycle_status' => 'deletion_requested'])->save();
        $this->audit($investor, 'data_rights.deletion_requested', $now, [
            'scheduled_for' => $request->scheduled_for->format(DATE_ATOM),
        ]);

        // Nunca copia o motivo livre do titular para a revisão: pode conter PII.
        $this->reviews->openCritical(
            $investor,
            'data_rights_request',
            $request->id,
            'deletion_requires_human_review',
            [
                'request_id' => $request->id,
                'policy_version' => (string) config('retention.policy.version', 'unknown'),
                'scheduled_for' => $request->scheduled_for->format(DATE_ATOM),
                'categories' => array_keys((array) config('retention.policy.categories', [])),
            ],
            ['data_rights_request'],
            ['A9', 'A14', 'RB-014'],
            $now,
        );

        return $request;
    }

    /** @return array<string, mixed> */
    public function billingSnapshot(Investor $investor): array
    {
        $subscription = Subscription::query()->where('investor_id', $investor->id)->latest('started_at')->first();

        return [
            'access_status' => $subscription?->access_status ?? 'none',
            'unchanged_by_data_rights' => true,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Investor $investor, string $action, DateTimeImmutable $now, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'data_subject',
            'actor_id' => (string) $investor->id,
            'action' => $action,
            'subject_type' => 'investor',
            'subject_id' => (string) $investor->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => $now,
        ]);
    }
}
