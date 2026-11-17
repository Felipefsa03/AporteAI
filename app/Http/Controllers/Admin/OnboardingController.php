<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Onboarding\ComplianceException;
use App\Domain\Onboarding\OnboardingService;
use App\Http\Controllers\Controller;
use App\Models\AdvisoryContract;
use App\Models\Investor;
use App\Models\OnboardingSession;
use App\Models\SuitabilityAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $service)
    {
    }

    public function start(string $investorId): JsonResponse
    {
        $session = $this->service->start(Investor::query()->findOrFail($investorId), now()->toDateTimeImmutable());

        return response()->json($this->state($session), 201);
    }

    public function answer(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string'],
            'answers' => ['required', 'array'],
        ]);

        try {
            $result = $this->service->answer(
                OnboardingSession::query()->findOrFail($sessionId),
                $data['idempotency_key'],
                $data['answers'],
                now()->toDateTimeImmutable(),
            );
        } catch (ComplianceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'reply' => $result['reply'],
            'state' => $this->state($result['session']),
        ]);
    }

    /** @return array<string, mixed> */
    private function state(OnboardingSession $session): array
    {
        return [
            'session_id' => $session->id,
            'step' => $session->step,
            'status' => $session->status,
            'payload' => $session->payload,
            'contract_version' => AdvisoryContract::query()->where('investor_id', $session->investor_id)->max('version'),
            'suitability_version' => SuitabilityAssessment::query()->where('investor_id', $session->investor_id)->max('version'),
        ];
    }
}
