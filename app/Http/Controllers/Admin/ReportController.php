<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Report\ReportGenerator;
use App\Http\Controllers\Controller;
use App\Models\Investor;
use App\Models\Portfolio;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReportController extends Controller
{
    public function __construct(private readonly ReportGenerator $generator)
    {
    }

    public function store(Request $request, string $investorId): Response
    {
        $data = $request->validate([
            'label' => ['required', 'string'],
            'returns_brl' => ['sometimes', 'nullable', 'string'],
            'market_value_brl' => ['sometimes', 'nullable', 'string'],
        ]);
        $investor = Investor::query()->findOrFail($investorId);
        $portfolio = Portfolio::query()->where('investor_id', $investor->id)->firstOrFail();

        $context = [];
        if (isset($data['returns_brl'])) {
            $context['returns_cents'] = $data['returns_brl'] === null
                ? null
                : \App\Domain\Billing\Cents::fromBrl($data['returns_brl']);
        }
        if (isset($data['market_value_brl'])) {
            $context['market_value_cents'] = $data['market_value_brl'] === null
                ? null
                : \App\Domain\Billing\Cents::fromBrl($data['market_value_brl']);
        }

        $report = $this->generator->generate(
            $investor,
            $portfolio,
            $data['label'],
            $context,
            now()->toDateTimeImmutable(),
        );

        return response($report->png, 201, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="aporteai-'.strtolower($report->label).'.png"',
            'X-Report-Id' => $report->id,
        ]);
    }
}
