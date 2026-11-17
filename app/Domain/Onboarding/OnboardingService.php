<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\AdvisoryContract;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\OnboardingSession;
use App\Models\SuitabilityAssessment;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A1: fluxo versionado e resumivel no mesmo WhatsApp, sem perder respostas. */
final class OnboardingService
{
    private const NEXT = [
        'identity' => 'terms',
        'terms' => 'suitability',
        'suitability' => 'completed',
    ];

    public function __construct(private readonly SuitabilityPolicy $policy)
    {
    }

    public function start(Investor $investor, DateTimeImmutable $now): OnboardingSession
    {
        $active = OnboardingSession::query()
            ->where('investor_id', $investor->id)
            ->where('status', 'in_progress')
            ->latest('created_at')
            ->first();

        if ($active !== null) {
            return $active;
        }

        return OnboardingSession::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'phone_e164' => $investor->phone_e164,
            'step' => 'identity',
            'payload' => [],
            'status' => 'in_progress',
        ]);
    }

    /**
     * @param array<string, mixed> $answers
     * @return array{session: OnboardingSession, reply: string}
     */
    public function answer(
        OnboardingSession $session,
        string $idempotencyKey,
        array $answers,
        DateTimeImmutable $now,
    ): array {
        if ($session->status === 'completed') {
            return ['session' => $session, 'reply' => 'Onboarding já concluído.'];
        }

        $payload = is_array($session->payload) ? $session->payload : [];
        if (($payload['idempotency_key'] ?? null) === $idempotencyKey) {
            return ['session' => $session, 'reply' => (string) ($payload['last_reply'] ?? 'Resposta já registrada.')];
        }

        $step = (string) $session->step;
        $reply = $this->applyStep($session, $step, $answers, $now);

        $payload['idempotency_key'] = $idempotencyKey;
        $payload['last_reply'] = $reply;
        $session->forceFill([
            'step' => $step === 'suitability' ? 'completed' : self::NEXT[$step],
            'payload' => $payload,
            'status' => $step === 'suitability' ? 'completed' : 'in_progress',
            'last_answer_at' => $now,
            'completed_at' => $step === 'suitability' ? $now : null,
        ])->save();

        $this->audit($session, 'onboarding.'.$step.'_answered', $now);

        return ['session' => $session, 'reply' => $reply];
    }

    /** @param array<string, mixed> $answers */
    private function applyStep(
        OnboardingSession $session,
        string $step,
        array $answers,
        DateTimeImmutable $now,
    ): string {
        if ($step === 'identity') {
            if (($answers['phone_confirmed'] ?? false) !== true) {
                throw new ComplianceException('Numero de telefone nao confirmado.');
            }

            return 'Dados confirmados. Envie os termos e o aviso de privacidade aceitos.';
        }

        if ($step === 'terms') {
            $artifact = isset($answers['artifact_uri']) ? (string) $answers['artifact_uri'] : '';
            $privacy = isset($answers['privacy_notice_uri']) ? (string) $answers['privacy_notice_uri'] : '';

            if ($artifact === '' || $privacy === '') {
                throw new ComplianceException('Contrato e aviso de privacidade sao obrigatorios.');
            }

            $version = 1 + (int) AdvisoryContract::query()->where('investor_id', $session->investor_id)->max('version');
            AdvisoryContract::query()->create([
                'id' => (string) Str::uuid(),
                'investor_id' => $session->investor_id,
                'version' => $version,
                'status' => 'signed',
                'signed_at' => $now,
                'artifact_uri' => $artifact,
                'privacy_notice_uri' => $privacy,
            ]);

            return 'Contrato registrado (v'.$version.'). Agora responda o suitability.';
        }

        if ($step === 'suitability') {
            if (! $this->policy->isComplete($answers)) {
                throw new ComplianceException('Questionario de suitability incompleto.');
            }

            $version = 1 + (int) SuitabilityAssessment::query()->where('investor_id', $session->investor_id)->max('version');
            $classification = $this->policy->classify($answers);
            SuitabilityAssessment::query()->create([
                'id' => (string) Str::uuid(),
                'investor_id' => $session->investor_id,
                'version' => $version,
                'answers' => $answers,
                'classification' => $classification,
                'status' => 'valid',
                'assessed_at' => $now,
                'expires_at' => $this->policy->expiresAt($now),
            ]);

            return 'Perfil '.$classification.' registrado (v'.$version.'). Onboarding concluido.';
        }

        throw new ComplianceException('Etapa de onboarding desconhecida.');
    }

    private function audit(OnboardingSession $session, string $action, DateTimeImmutable $now): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'onboarding-service',
            'action' => $action,
            'subject_type' => 'investor',
            'subject_id' => $session->investor_id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => ['session_id' => $session->id],
            'occurred_at' => $now,
        ]);
    }
}
