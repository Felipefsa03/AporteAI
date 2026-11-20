<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use App\Domain\Aporte\AporteException;
use App\Domain\Aporte\AporteFlowService;
use App\Domain\Billing\AccessDecision;
use App\Domain\Billing\Cents;
use App\Domain\Billing\MessageClassifier;
use App\Domain\Billing\SubscriptionService;
use App\Domain\MarketData\SourceCatalog;
use App\Domain\Onboarding\ComplianceException;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Portfolio\PortfolioLedger;
use App\Domain\Privacy\DataRightsService;
use App\Domain\Report\ReportGenerator;
use App\Domain\WhatsApp\WhatsAppOutboundService;
use App\Models\AuditEvent;
use App\Models\AporteSession;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\InvestorJourney;
use App\Models\MarketQuote;
use App\Models\OnboardingSession;
use App\Models\Subscription;
use App\Models\SuitabilityAssessment;
use App\Models\WhatsAppConversation;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * CHAT: jornada persistente pelo bot. Nenhuma transição roda antes do
 * BillingGate; comandos administrativos funcionam mesmo sem serviço pago,
 * mas nunca liberam acesso, desbloqueiam pagamento ou executam ordens.
 */
final class InvestorJourneyService
{
    private const PROMPTS = [
        'awaiting_identity' => 'Para começar, confirme seu telefone respondendo CONFIRMO TELEFONE.',
        'awaiting_terms' => 'Envie ACEITO TERMOS para registrar o contrato e o aviso de privacidade desta versão.',
        'awaiting_suitability' => 'Responda PERFIL <objetivo> <horizonte> <conhecimento> <liquidez> <restricoes>. Exemplo: PERFIL GROWTH LONG INTERMEDIATE MONTHLY NENHUMA.',
        'awaiting_amount' => 'Informe o valor do aporte em reais, por exemplo 800,00.',
        'awaiting_aporte_1' => 'Pergunta 1/4: seu objetivo principal com este aporte?',
        'awaiting_aporte_2' => 'Pergunta 2/4: qual é o seu horizonte?',
        'awaiting_aporte_3' => 'Pergunta 3/4: como você avalia seu conhecimento financeiro?',
        'awaiting_aporte_4' => 'Pergunta 4/4: houve mudança relevante na sua situação financeira?',
        'awaiting_decision' => 'Escolha: PROPOR (gerar proposta validada), RECUSAR ou REVISAR.',
        'awaiting_confirmation' => 'Proposta validada. Para executar, responda explicitamente CONFIRMO APORTE. Nada será executado sem essa confirmação.',
        'completed' => 'Aporte concluído e relatório enviado. Envie NOVO APORTE para começar outro ciclo ou MEU RELATORIO para receber o relatório realizado.',
        'refused' => 'Proposta recusada. Nenhuma ordem foi gerada. Envie NOVO APORTE se desejar recomeçar.',
        'review' => 'Solicitação enviada para revisão humana. Nenhuma ordem pode avançar enquanto o caso estiver pendente.',
    ];

    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly AporteFlowService $aporte,
        private readonly SubscriptionService $subscriptions,
        private readonly DataRightsService $dataRights,
        private readonly ReportGenerator $reports,
        private readonly PortfolioLedger $ledger,
        private readonly WhatsAppOutboundService $outbound,
        private readonly MessageClassifier $classifier,
        private readonly SourceCatalog $sources,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function handle(
        Investor $investor,
        array $event,
        AccessDecision $decision,
        DateTimeImmutable $now,
    ): array {
        $base = $decision->toArray();

        if ($decision->action === 'blocked' || $decision->action === 'verification') {
            $this->auditOnce($investor, 'journey.gate_blocked', $event, [
                'reason' => $decision->reason,
            ], $now);

            return array_replace($base, [
                'reply' => $decision->reply,
                'llm_calls' => 0,
                'journey_state' => $this->currentState($investor),
                'journey_action' => 'gate_blocked',
            ]);
        }

        $command = $this->commandFromDecision($decision, $event);
        if ($decision->action === 'command' || $command !== null) {
            return array_replace($base, $this->command($investor, $event, $decision, $command, $now));
        }

        if (! $decision->allowLlm) {
            return array_replace($base, [
                'reply' => $decision->reply,
                'llm_calls' => 0,
                'journey_action' => 'gate_not_allowed',
            ]);
        }

        return array_replace($base, $this->advance($investor, $event, $now));
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function command(
        Investor $investor,
        array $event,
        AccessDecision $decision,
        ?string $command,
        DateTimeImmutable $now,
    ): array {
        $command = $command ?? $this->classifier->command($this->body($event));
        $normalized = $this->classifier->normalize($this->body($event));
        $reply = $decision->reply;
        $action = 'journey_command';
        $dataAction = null;

        if ($command === 'privacy') {
            if (str_contains($normalized, 'EXCLUSAO')) {
                $this->dataRights->requestDeletion($investor, 'bot_command', $now);
                $reply = 'Solicitação de exclusão registrada para revisão humana. Seus direitos serão atendidos conforme a política aprovada.';
                $action = 'deletion_requested';
            } elseif (str_contains($normalized, 'EXPORTAR')) {
                $this->dataRights->export($investor, $now);
                $reply = 'Exportação dos seus dados gerada e registrada. O conteúdo não é enviado por este canal.';
                $action = 'data_exported';
            } else {
                $dataAction = $this->dataRights->review($investor, $now);
                $reply = 'Seus direitos de titular estão disponíveis: acesso, correção, exportação, revisão e exclusão. Nenhum serviço pago foi liberado por este comando.';
                $action = 'data_reviewed';
            }
        } elseif ($command === 'cancellation') {
            $subscription = Subscription::query()
                ->where('investor_id', $investor->id)
                ->whereNotIn('status', ['canceled', 'expired'])
                ->latest('started_at')
                ->first();

            if ($subscription !== null) {
                $this->subscriptions->requestCancellation($subscription, $this->eventId($event), $now);
            }
            $action = 'cancellation_requested';
        } elseif ($command === 'reconcile') {
            $action = 'reconcile_requested';
        } elseif ($command === 'handoff') {
            $action = 'human_handoff';
        }

        $this->auditOnce($investor, 'journey.command', $event, [
            'command' => $command ?? 'unknown',
            'action' => $action,
            'policy_version' => $dataAction['policy_version'] ?? null,
        ], $now);

        return [
            'reply' => $reply,
            'llm_calls' => 0,
            'journey_state' => $this->currentState($investor),
            'journey_action' => $action,
        ];
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function advance(Investor $investor, array $event, DateTimeImmutable $now): array
    {
        return DB::transaction(function () use ($investor, $event, $now): array {
            $journey = InvestorJourney::query()->firstOrCreate(
                ['investor_id' => $investor->id],
                [
                    'id' => (string) Str::uuid(),
                    'state' => 'new',
                    'payload' => [],
                ],
            );
            $journey = InvestorJourney::query()->lockForUpdate()->findOrFail($journey->id);

            $inboxId = $this->eventId($event);
            $occurredAt = $this->occurredAt($event, $now);

            if ($inboxId !== '' && $journey->last_inbox_event_id === $inboxId) {
                $this->auditOnce($investor, 'journey.ignored_duplicate', $event, [
                    'from' => (string) $journey->state,
                ], $now);

                return $this->ignored($journey, 'duplicate_event');
            }

            if ($journey->last_occurred_at !== null && $occurredAt < $journey->last_occurred_at) {
                $this->auditOnce($investor, 'journey.ignored_out_of_order', $event, [
                    'from' => (string) $journey->state,
                    'occurred_at' => $occurredAt->format(DATE_ATOM),
                    'last_occurred_at' => $journey->last_occurred_at->format(DATE_ATOM),
                ], $now);

                return $this->ignored($journey, 'out_of_order');
            }

            $from = (string) $journey->state;
            try {
                [$state, $reply, $action] = $this->transition($investor, $journey, $event, $now);
            } catch (AporteException|ComplianceException $exception) {
                $this->auditOnce($investor, 'journey.transition_rejected', $event, [
                    'from' => $from,
                    'reason' => $exception->getMessage(),
                ], $now);

                return [
                    'reply' => 'Não foi possível avançar: '.$exception->getMessage().' '.$this->prompt($from),
                    'llm_calls' => 0,
                    'journey_state' => $from,
                    'journey_action' => 'transition_rejected',
                ];
            }

            $journey->forceFill([
                'state' => $state,
                'last_occurred_at' => $occurredAt,
                'last_inbox_event_id' => $inboxId !== '' ? $inboxId : null,
                'version' => (int) $journey->version + 1,
                'updated_at' => $now,
            ])->save();

            $this->auditOnce($investor, 'journey.advanced', $event, [
                'from' => $from,
                'to' => $state,
                'action' => $action,
            ], $now);

            return [
                'reply' => $reply,
                'llm_calls' => 0,
                'journey_state' => $state,
                'journey_action' => $action,
            ];
        });
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: string, 1: string, 2: string}
     */
    private function transition(
        Investor $investor,
        InvestorJourney $journey,
        array $event,
        DateTimeImmutable $now,
    ): array {
        $state = (string) $journey->state;
        $normalized = $this->classifier->normalize($this->body($event));

        if (in_array($normalized, ['CONTINUAR', 'RETOMAR', 'RESUMIR'], true)) {
            return [$state, $this->prompt($state), 'resumed'];
        }

        if (in_array($normalized, ['MEU RELATORIO', 'RELATORIO', 'MEU RELATÓRIO'], true)
            && ! in_array($state, ['awaiting_identity', 'awaiting_terms', 'awaiting_suitability'], true)) {
            return [$state, $this->sendReport($investor, $event, $now), 'report_sent'];
        }

        if ($state === 'new') {
            return ['awaiting_identity', $this->prompt('awaiting_identity'), 'journey_started'];
        }

        if ($state === 'awaiting_identity') {
            if (! in_array($normalized, ['CONFIRMO TELEFONE', 'CONFIRMO', 'CONFIRMO O TELEFONE'], true)) {
                return [$state, $this->prompt($state), 'awaiting_expected_answer'];
            }

            $session = $this->onboardingSession($investor, $now);
            $result = $this->onboarding->answer($session, $this->eventId($event), [
                'phone_confirmed' => true,
            ], $now);
            $journey->putPayload('onboarding_session_id', $result['session']->id);

            return ['awaiting_terms', $result['reply'].' '.$this->prompt('awaiting_terms'), 'identity_confirmed'];
        }

        if ($state === 'awaiting_terms') {
            if (! in_array($normalized, ['ACEITO TERMOS', 'ACEITO TERMOS E PRIVACIDADE', 'ACEITO'], true)) {
                return [$state, $this->prompt($state), 'awaiting_expected_answer'];
            }

            $session = $this->onboardingSession($investor, $now);
            $result = $this->onboarding->answer($session, $this->eventId($event), [
                'artifact_uri' => 'internal://contracts/aporteai-v1.pdf',
                'privacy_notice_uri' => 'internal://privacy/aporteai-v1.pdf',
            ], $now);

            return ['awaiting_suitability', $result['reply'].' '.$this->prompt('awaiting_suitability'), 'terms_accepted'];
        }

        if ($state === 'awaiting_suitability') {
            $answers = $this->suitabilityAnswers($normalized);
            if ($answers === null) {
                return [$state, $this->prompt($state), 'awaiting_expected_answer'];
            }

            $session = $this->onboardingSession($investor, $now);
            $result = $this->onboarding->answer($session, $this->eventId($event), $answers, $now);

            return ['awaiting_amount', $result['reply'].' '.$this->prompt('awaiting_amount'), 'suitability_completed'];
        }

        if ($state === 'awaiting_amount') {
            $amount = $this->amountCents($this->body($event));
            if ($amount === null) {
                return [$state, $this->prompt($state), 'awaiting_expected_answer'];
            }

            $session = $this->aporte->start($investor, $amount, $now);
            $journey->putPayload('aporte_session_id', $session->id);
            $journey->putPayload('amount_cents', $amount);
            $journey->putPayload('answers_index', 0);

            return ['awaiting_aporte_1', $this->prompt('awaiting_aporte_1'), 'aporte_started'];
        }

        if (str_starts_with($state, 'awaiting_aporte_')) {
            return $this->answerAporteQuestion($investor, $journey, $event, $now);
        }

        if ($state === 'awaiting_decision') {
            if (in_array($normalized, ['PROPOR', 'GERAR PROPOSTA', 'PROPOSTA'], true)) {
                return $this->generateProposal($investor, $journey, $event, $now);
            }

            if (in_array($normalized, ['RECUSAR', 'RECUSAR PROPOSTA'], true)) {
                $session = $this->aporteSession($journey);
                $this->aporte->refuse($session, 'bot_explicit_refusal', $now);

                return ['refused', $this->prompt('refused'), 'aporte_refused'];
            }

            if (in_array($normalized, ['REVISAR', 'SOLICITAR REVISAO', 'SOLICITAR REVISÃO'], true)) {
                $session = $this->aporteSession($journey);
                $this->aporte->requestReview($session, 'bot_requested_review', $now);

                return ['review', $this->prompt('review'), 'review_requested'];
            }

            return [$state, $this->prompt($state), 'awaiting_expected_answer'];
        }

        if ($state === 'awaiting_confirmation') {
            if (! in_array($normalized, ['CONFIRMO APORTE', 'CONFIRMAR APORTE'], true)) {
                return [$state, 'Confirmação não reconhecida. '.$this->prompt($state), 'confirmation_required'];
            }

            if ($journey->payloadValue('confirmation_event_id') !== null) {
                return ['completed', 'Este aporte já foi confirmado. Nenhuma nova ordem foi gerada.', 'duplicate_confirmation_blocked'];
            }

            return $this->confirmAporte($investor, $journey, $event, $now);
        }

        if ($state === 'review') {
            if (in_array($normalized, ['NOVO APORTE', 'RECOMECAR', 'RECOMEÇAR'], true)) {
                return ['review', 'Revisão humana pendente: novo aporte não pode ser iniciado.', 'new_aporte_blocked_by_review'];
            }

            if (in_array($normalized, ['PROPOR', 'GERAR PROPOSTA', 'PROPOSTA', 'CONFIRMO APORTE', 'CONFIRMAR APORTE'], true)) {
                return ['review', 'Revisão humana pendente: nenhuma proposta ou ordem pode avançar.', 'proposal_blocked_by_review'];
            }

            return [$state, $this->prompt($state), 'awaiting_human_review'];
        }

        if ($state === 'completed'
            && in_array($normalized, ['CONFIRMO APORTE', 'CONFIRMAR APORTE'], true)
            && $journey->payloadValue('confirmation_event_id') !== null) {
            return ['completed', 'Este aporte já foi confirmado. Nenhuma nova ordem foi gerada.', 'duplicate_confirmation_blocked'];
        }

        if (in_array($normalized, ['NOVO APORTE', 'RECOMECAR', 'RECOMEÇAR'], true)) {
            $journey->putPayload('aporte_session_id', null);
            $journey->putPayload('answers_index', 0);
            $journey->putPayload('proposal_report_id', null);
            $journey->putPayload('realized_report_id', null);
            $journey->putPayload('confirmation_event_id', null);

            return ['awaiting_amount', $this->prompt('awaiting_amount'), 'new_aporte_started'];
        }

        return [$state, $this->prompt($state), 'state_kept'];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: string, 1: string, 2: string}
     */
    private function answerAporteQuestion(
        Investor $investor,
        InvestorJourney $journey,
        array $event,
        DateTimeImmutable $now,
    ): array {
        $index = (int) $journey->payloadValue('answers_index', 0);
        $questions = AporteFlowService::QUESTIONS;
        if ($index < 0 || $index >= count($questions)) {
            return ['awaiting_decision', $this->prompt('awaiting_decision'), 'answers_complete'];
        }

        $currentState = 'awaiting_aporte_'.($index + 1);
        $normalized = $this->classifier->normalize($this->body($event));
        $commands = [
            'PROPOR', 'GERAR PROPOSTA', 'PROPOSTA',
            'RECUSAR', 'RECUSAR PROPOSTA',
            'REVISAR', 'SOLICITAR REVISAO', 'SOLICITAR REVISÃO',
            'CONFIRMO APORTE', 'CONFIRMAR APORTE',
        ];
        if (in_array($normalized, $commands, true)) {
            return [$currentState, 'Responda primeiro à pergunta '.($index + 1).'/4. '.$this->prompt($currentState), 'awaiting_expected_answer'];
        }

        $session = $this->aporteSession($journey);
        $result = $this->aporte->answer(
            $session,
            $questions[$index],
            trim($this->body($event)),
            $this->eventId($event),
            $now,
        );

        ++$index;
        $journey->putPayload('answers_index', $index);

        if ($result['complete']) {
            return ['awaiting_decision', 'Quatro respostas registradas. '.$this->prompt('awaiting_decision'), 'phase_one_completed'];
        }

        $next = 'awaiting_aporte_'.($index + 1);

        return [$next, 'Resposta registrada. '.$this->prompt($next), 'aporte_answer_saved'];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: string, 1: string, 2: string}
     */
    private function generateProposal(
        Investor $investor,
        InvestorJourney $journey,
        array $event,
        DateTimeImmutable $now,
    ): array {
        $session = $this->aporteSession($journey);
        $assessment = SuitabilityAssessment::query()
            ->where('investor_id', $investor->id)
            ->where('status', 'valid')
            ->latest('assessed_at')
            ->first();

        if ($assessment === null) {
            return ['awaiting_decision', 'Suitability válido não encontrado. '.$this->prompt('awaiting_decision'), 'proposal_blocked'];
        }

        $candidates = $this->candidates($now);
        if ($candidates === []) {
            return ['awaiting_decision', 'Sem fontes de mercado confiáveis no momento; nenhuma proposta foi gerada.', 'proposal_blocked'];
        }

        $answers = is_array($session->answers) ? $session->answers : [];
        $financialChanges = str_starts_with($this->classifier->normalize((string) ($answers['financial_changes'] ?? '')), 'SIM');

        $proposal = $this->aporte->propose($session, $candidates, [
            'assessment' => $assessment,
            'financial_changes' => $financialChanges,
        ], $now);

        $portfolio = $this->ledger->getOrCreate($investor);
        $report = $this->reports->generate($investor, $portfolio, 'PROPOSTA', [
            'proposal_positions' => $proposal['positions'],
        ], $now);
        $journey->putPayload('proposal_report_id', $report->id);
        $this->sendReportBytes($investor, $report, $event, $now);

        return [
            'awaiting_confirmation',
            'Proposta validada enviada em PNG. Nenhuma posição foi alterada. '.$this->prompt('awaiting_confirmation'),
            'proposal_generated',
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: string, 1: string, 2: string}
     */
    private function confirmAporte(
        Investor $investor,
        InvestorJourney $journey,
        array $event,
        DateTimeImmutable $now,
    ): array {
        $session = $this->aporteSession($journey);
        $proposal = is_array($session->proposal) ? $session->proposal : [];
        $targets = is_array($proposal['allocation']['targets'] ?? null) ? $proposal['allocation']['targets'] : [];
        $target = null;

        foreach ($targets as $candidate) {
            if (is_array($candidate) && ($candidate['symbol'] ?? '') !== 'CASH') {
                $target = $candidate;
                break;
            }
        }

        if ($target === null) {
            return ['awaiting_confirmation', 'Proposta sem instrumento elegível; envie REVISAR.', 'confirmation_blocked'];
        }

        $instrument = Instrument::query()->where('symbol', (string) $target['symbol'])->first();
        if ($instrument === null) {
            return ['awaiting_confirmation', 'Instrumento da proposta indisponível; envie REVISAR.', 'confirmation_blocked'];
        }

        $amount = max(1, (int) ($target['amount_cents'] ?? $session->amount_cents));
        $externalId = 'bot-aporte-'.($this->eventId($event) !== '' ? $this->eventId($event) : (string) Str::uuid());
        $result = $this->aporte->confirm($session, $externalId, $instrument, $amount, $now);

        if (! $result['applied']) {
            $journey->putPayload('confirmation_event_id', $this->eventId($event));

            return ['completed', 'Esta ordem já havia sido aplicada. Nenhuma nova posição foi criada.', 'duplicate_execution_blocked'];
        }

        $portfolio = $this->ledger->getOrCreate($investor);
        $report = $this->reports->generate($investor, $portfolio, 'REALIZADO', [
            'returns_cents' => 0,
            'market_value_cents' => $amount,
            'source' => 'BOT_JOURNEY',
        ], $now);
        $journey->putPayload('realized_report_id', $report->id);
        $journey->putPayload('confirmation_event_id', $this->eventId($event));
        $this->sendReportBytes($investor, $report, $event, $now);

        return ['completed', 'Aporte confirmado uma única vez. Nenhuma nova ordem será gerada sem NOVO APORTE.', 'aporte_confirmed'];
    }

    /** @param array<string, mixed> $event */
    private function sendReport(Investor $investor, array $event, DateTimeImmutable $now): string
    {
        $portfolio = $this->ledger->getOrCreate($investor);
        $report = $this->reports->generate($investor, $portfolio, 'REALIZADO', [
            'source' => 'BOT_JOURNEY',
        ], $now);
        $this->sendReportBytes($investor, $report, $event, $now);

        return 'Relatório realizado enviado em PNG. Valores indisponíveis foram sinalizados, nunca inventados.';
    }

    /** @param array<string, mixed> $event */
    private function sendReportBytes(Investor $investor, \App\Models\Report $report, array $event, DateTimeImmutable $now): void
    {
        $conversation = WhatsAppConversation::query()
            ->where('investor_id', $investor->id)
            ->latest('last_inbound_at')
            ->first();

        $this->outbound->queueImageBytes(
            $investor,
            (string) $report->png,
            $now,
            'AporteAI '.$report->label,
            $conversation,
            'report:'.$report->id,
        );
    }

    private function onboardingSession(Investor $investor, DateTimeImmutable $now): OnboardingSession
    {
        $journey = InvestorJourney::query()->where('investor_id', $investor->id)->firstOrFail();
        $id = $journey->payloadValue('onboarding_session_id');

        if (is_string($id) && $id !== '') {
            $session = OnboardingSession::query()->find($id);
            if ($session !== null) {
                return $session;
            }
        }

        return $this->onboarding->start($investor, $now);
    }

    private function aporteSession(InvestorJourney $journey): AporteSession
    {
        $id = $journey->payloadValue('aporte_session_id');
        if (! is_string($id) || $id === '') {
            throw new AporteException('Sessão de aporte não iniciada.');
        }

        return AporteSession::query()->findOrFail($id);
    }

    /** @return list<array{instrument: Instrument, quote: MarketQuote}> */
    private function candidates(DateTimeImmutable $now): array
    {
        $candidates = [];

        foreach (array_keys((array) config('allocation.universe', [])) as $symbol) {
            $symbol = (string) $symbol;
            $instrument = Instrument::query()
                ->where('symbol', $symbol)
                ->where('status', 'active')
                ->first();
            $rule = config('allocation.universe.'.$symbol);

            if ($instrument === null || ! is_array($rule) || ! isset($rule['source'])) {
                continue;
            }

            try {
                $source = $this->sources->get((string) $rule['source']);
            } catch (Throwable) {
                continue;
            }

            $quote = MarketQuote::query()
                ->where('instrument_id', $instrument->id)
                ->where('source_id', $source['id'])
                ->where('quality_status', 'approved')
                ->where('as_of', '>=', $now->modify('-1 day'))
                ->latest('as_of')
                ->first();

            if ($quote !== null) {
                $candidates[] = ['instrument' => $instrument, 'quote' => $quote];
            }
        }

        return $candidates;
    }

    /** @return array<string, string>|null */
    private function suitabilityAnswers(string $normalized): ?array
    {
        if (! str_starts_with($normalized, 'PERFIL ')) {
            return null;
        }

        $parts = preg_split('/\s+/', trim(substr($normalized, 7))) ?: [];
        if (count($parts) < 5) {
            return null;
        }

        $objectives = ['GROWTH' => 'growth', 'CRESCIMENTO' => 'growth', 'RENDA' => 'income', 'INCOME' => 'income', 'PRESERVACAO' => 'preservation', 'PRESERVAÇÃO' => 'preservation', 'PRESERVATION' => 'preservation'];
        $horizons = ['SHORT' => 'short', 'CURTO' => 'short', 'MEDIUM' => 'medium', 'MEDIO' => 'medium', 'MÉDIO' => 'medium', 'LONG' => 'long', 'LONGO' => 'long'];
        $knowledge = ['LIMITED' => 'limited', 'BASICO' => 'limited', 'BÁSICO' => 'limited', 'INTERMEDIATE' => 'intermediate', 'INTERMEDIARIO' => 'intermediate', 'INTERMEDIÁRIO' => 'intermediate', 'ADVANCED' => 'advanced', 'AVANCADO' => 'advanced', 'AVANÇADO' => 'advanced'];
        $liquidity = ['DAILY' => 'daily', 'DIARIO' => 'daily', 'DIÁRIO' => 'daily', 'MONTHLY' => 'monthly', 'MENSAL' => 'monthly', 'QUARTERLY' => 'quarterly', 'TRIMESTRAL' => 'quarterly'];

        $objective = $objectives[$parts[0]] ?? null;
        $horizon = $horizons[$parts[1]] ?? null;
        $level = $knowledge[$parts[2]] ?? null;
        $liquidityValue = $liquidity[$parts[3]] ?? null;

        if ($objective === null || $horizon === null || $level === null || $liquidityValue === null) {
            return null;
        }

        $restrictions = [];
        $raw = $parts[4];
        if (! in_array($raw, ['NENHUMA', 'NENHUM', 'NONE', 'SEM'], true)) {
            foreach (explode(',', $raw) as $restriction) {
                $value = strtolower(str_replace('-', '_', trim($restriction)));
                if ($value !== '') {
                    $restrictions[] = $value;
                }
            }
        }

        return [
            'objective' => $objective,
            'horizon' => $horizon,
            'knowledge' => $level,
            'liquidity' => $liquidityValue,
            'restrictions' => $restrictions,
        ];
    }

    private function amountCents(string $body): ?int
    {
        $raw = str_replace(['R$', ' '], '', trim($body));
        if ($raw === '') {
            return null;
        }

        if (! str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', ',', $raw);
        }

        try {
            $cents = Cents::fromBrl($raw);
        } catch (Throwable) {
            return null;
        }

        return $cents > 0 ? $cents : null;
    }

    private function prompt(string $state): string
    {
        return self::PROMPTS[$state] ?? 'Envie CONTINUAR para retomar a jornada.';
    }

    private function currentState(Investor $investor): string
    {
        return (string) (InvestorJourney::query()->where('investor_id', $investor->id)->value('state') ?? 'new');
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function ignored(InvestorJourney $journey, string $reason): array
    {
        return [
            'reply' => 'Mensagem já processada ou fora de ordem. '.$this->prompt((string) $journey->state),
            'llm_calls' => 0,
            'journey_state' => (string) $journey->state,
            'journey_action' => $reason,
        ];
    }

    /** @param array<string, mixed> $event */
    private function commandFromDecision(AccessDecision $decision, array $event): ?string
    {
        $reason = $decision->reason;
        if (str_starts_with($reason, 'administrative_command:')) {
            return substr($reason, strlen('administrative_command:'));
        }

        return $this->classifier->command($this->body($event));
    }

    /** @param array<string, mixed> $event */
    private function body(array $event): string
    {
        return (string) ($event['body'] ?? '');
    }

    /** @param array<string, mixed> $event */
    private function eventId(array $event): string
    {
        foreach (['inbox_event_id', 'external_id'] as $key) {
            $value = (string) ($event[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $event */
    private function occurredAt(array $event, DateTimeImmutable $fallback): DateTimeImmutable
    {
        $value = $event['occurred_at'] ?? null;

        return $value instanceof DateTimeImmutable ? $value : $fallback;
    }

    /** @param array<string, mixed> $metadata */
    private function auditOnce(
        Investor $investor,
        string $action,
        array $event,
        array $metadata,
        DateTimeImmutable $now,
    ): void {
        $id = $this->eventId($event);
        if ($id !== '' && AuditEvent::query()
            ->where('action', $action)
            ->where('metadata->inbox_event_id', $id)
            ->exists()) {
            return;
        }

        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'investor',
            'actor_id' => (string) $investor->id,
            'action' => $action,
            'subject_type' => 'investor',
            'subject_id' => (string) $investor->id,
            'trace_id' => (string) Str::uuid(),
            'metadata' => array_replace([
                'inbox_event_id' => $id !== '' ? $id : null,
                'external_id' => (string) ($event['external_id'] ?? ''),
            ], $metadata),
            'occurred_at' => $now,
        ]);
    }
}
