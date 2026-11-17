<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Allocation\AllocationEngine;
use App\Domain\Billing\Cents;
use App\Domain\Explanation\ProposalExplainer;
use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\MarketQuote;
use App\Models\SuitabilityAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AllocationController extends Controller
{
    public function __construct(
        private readonly AllocationEngine $engine,
        private readonly ProposalExplainer $explainer,
    ) {
    }

    public function propose(Request $request, string $investorId): JsonResponse
    {
        $data = $request->validate([
            'budget_brl' => ['required', 'string'],
            'reserve_brl' => ['sometimes', 'string'],
            'debts_brl' => ['sometimes', 'string'],
            'financial_changes' => ['sometimes', 'boolean'],
            'explain' => ['sometimes', 'boolean'],
        ]);

        $investor = Investor::query()->findOrFail($investorId);
        $assessment = SuitabilityAssessment::query()
            ->where('investor_id', $investor->id)
            ->where('status', 'valid')
            ->latest('assessed_at')
            ->firstOrFail();

        $candidates = [];
        foreach (Instrument::query()->where('status', 'active')->get() as $instrument) {
            $quote = MarketQuote::query()
                ->where('instrument_id', $instrument->id)
                ->latest('as_of')
                ->first();
            if ($quote !== null) {
                $candidates[] = ['instrument' => $instrument, 'quote' => $quote];
            }
        }

        $allocation = $this->engine->allocate(
            $assessment,
            Cents::fromBrl($data['budget_brl']),
            $candidates,
            [
                'reserve_cents' => isset($data['reserve_brl']) ? Cents::fromBrl($data['reserve_brl']) : 0,
                'debts_cents' => isset($data['debts_brl']) ? Cents::fromBrl($data['debts_brl']) : 0,
                'financial_changes' => (bool) ($data['financial_changes'] ?? false),
            ],
            now()->toDateTimeImmutable(),
        );

        $payload = $allocation->toArray();

        if (($data['explain'] ?? false) === true) {
            $payload['explanation'] = $this->explainer->explain(
                $investor,
                $allocation,
                now()->toDateTimeImmutable(),
                ['user_text' => (string) ($data['user_text'] ?? '')],
            );
        }

        return response()->json($payload);
    }
}
