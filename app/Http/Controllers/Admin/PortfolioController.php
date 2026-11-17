<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Cents;
use App\Domain\Portfolio\PortfolioLedger;
use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PortfolioController extends Controller
{
    public function __construct(private readonly PortfolioLedger $ledger)
    {
    }

    public function show(string $investorId): JsonResponse
    {
        $portfolio = $this->ledger->getOrCreate(Investor::query()->findOrFail($investorId));

        return response()->json([
            'portfolio_id' => $portfolio->id,
            'positions' => $this->ledger->positions($portfolio),
        ]);
    }

    public function recommend(Request $request, string $investorId): JsonResponse
    {
        $data = $request->validate([
            'allocation' => ['required', 'array'],
        ]);
        $portfolio = $this->ledger->getOrCreate(Investor::query()->findOrFail($investorId));
        $result = $this->ledger->recordRecommendation($portfolio, $data['allocation'], now()->toDateTimeImmutable());

        return response()->json([
            'recommendation_id' => $result['recommendation']->id,
            'positions' => $result['positions'],
        ], 201);
    }

    public function execute(Request $request, string $investorId): JsonResponse
    {
        $data = $request->validate([
            'external_id' => ['required', 'string'],
            'event_type' => ['required', 'string'],
            'instrument_id' => ['sometimes', 'nullable', 'uuid'],
            'amount_brl' => ['required', 'string'],
        ]);
        $portfolio = $this->ledger->getOrCreate(Investor::query()->findOrFail($investorId));
        $instrument = isset($data['instrument_id'])
            ? Instrument::query()->findOrFail($data['instrument_id'])
            : null;

        $result = $this->ledger->recordExecution(
            $portfolio,
            $data['external_id'],
            $data['event_type'],
            $instrument,
            Cents::fromBrl($data['amount_brl']),
            now()->toDateTimeImmutable(),
            ['source' => 'admin'],
        );

        return response()->json($result);
    }
}
