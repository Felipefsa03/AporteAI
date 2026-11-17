<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Dashboard\DashboardMetricsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class MetricsController extends Controller
{
    public function __construct(private readonly DashboardMetricsService $metrics)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->metrics->snapshot());
    }

    public function client(string $investorId): JsonResponse
    {
        return response()->json($this->metrics->client($investorId));
    }
}
