<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Aporte\AporteException;
use App\Domain\Aporte\AporteFlowService;
use App\Domain\Billing\Cents;
use App\Domain\Review\ReviewException;
use App\Http\Controllers\Controller;
use App\Models\AporteSession;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\MarketQuote;
use App\Models\ReviewCase;
use App\Models\SuitabilityAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AporteController extends Controller
{
    public function __construct(private readonly AporteFlowService $service)
    {
    }

    public function start(Request $request, string $investorId): JsonResponse
    {
        $data = $request->validate(['amount_brl' => ['required', 'string']]);
        $session = $this->service->start(
            Investor::query()->findOrFail($investorId),
            Cents::fromBrl($data['amount_brl']),
            now()->toDateTimeImmutable(),
        );

        return response()->json(['session_id' => $session->id, 'phase' => $session->phase], 201);
    }

    public function answer(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string'],
            'value' => ['required', 'string'],
            'idempotency_key' => ['required', 'string'],
        ]);

        try {
            $result = $this->service->answer(
                AporteSession::query()->findOrFail($sessionId),
                $data['question'],
                $data['value'],
                $data['idempotency_key'],
                now()->toDateTimeImmutable(),
            );
        } catch (AporteException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'phase' => $result['session']->phase,
            'complete' => $result['complete'],
            'pending' => $result['pending'],
        ]);
    }

    public function propose(Request $request, string $sessionId): JsonResponse
    {
        $session = AporteSession::query()->findOrFail($sessionId);
        try {
            $this->assertNoCriticalBlock($session->investor_id);
        } catch (ReviewException $exception) {
            return response()->json(['message' => $exception->getMessage()], 423);
        }
        $assessment = SuitabilityAssessment::query()
            ->where('investor_id', $session->investor_id)
            ->where('status', 'valid')
            ->latest('assessed_at')
            ->firstOrFail();

        $candidates = [];
        foreach (Instrument::query()->where('status', 'active')->get() as $instrument) {
            $quote = MarketQuote::query()->where('instrument_id', $instrument->id)->latest('as_of')->first();
            if ($quote !== null) {
                $candidates[] = ['instrument' => $instrument, 'quote' => $quote];
            }
        }

        try {
            $result = $this->service->propose(
                $session,
                $candidates,
                ['assessment' => $assessment],
                now()->toDateTimeImmutable(),
            );
        } catch (AporteException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    public function confirm(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate([
            'external_id' => ['required', 'string'],
            'instrument_id' => ['sometimes', 'nullable', 'uuid'],
            'amount_brl' => ['required', 'string'],
        ]);
        $session = AporteSession::query()->findOrFail($sessionId);
        try {
            $this->assertNoCriticalBlock($session->investor_id);
        } catch (ReviewException $exception) {
            return response()->json(['message' => $exception->getMessage()], 423);
        }
        $instrument = isset($data['instrument_id'])
            ? Instrument::query()->findOrFail($data['instrument_id'])
            : null;

        try {
            $result = $this->service->confirm(
                $session,
                $data['external_id'],
                $instrument,
                Cents::fromBrl($data['amount_brl']),
                now()->toDateTimeImmutable(),
            );
        } catch (AporteException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }

    private function assertNoCriticalBlock(string $investorId): void
    {
        if (ReviewCase::query()
            ->where('investor_id', $investorId)
            ->where('critical', true)
            ->where('status', 'pending')
            ->exists()) {
            throw new ReviewException('Revisão crítica pendente: nenhuma proposta ou ordem pode avançar.');
        }
    }
}
