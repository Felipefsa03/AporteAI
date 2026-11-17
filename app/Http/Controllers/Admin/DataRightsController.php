<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Privacy\DataRightsException;
use App\Domain\Privacy\DataRightsService;
use App\Http\Controllers\Controller;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DataRightsController extends Controller
{
    public function __construct(private readonly DataRightsService $service)
    {
    }

    public function export(string $investorId): JsonResponse
    {
        return response()->json(
            $this->service->export(Investor::query()->findOrFail($investorId), now()->toDateTimeImmutable()),
        );
    }

    public function review(string $investorId): JsonResponse
    {
        return response()->json(
            $this->service->review(Investor::query()->findOrFail($investorId), now()->toDateTimeImmutable()),
        );
    }

    public function correct(Request $request, string $investorId): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string'],
            'value' => ['required'],
        ]);

        try {
            $investor = $this->service->correct(
                Investor::query()->findOrFail($investorId),
                $data['field'],
                $data['value'],
                now()->toDateTimeImmutable(),
            );
        } catch (DataRightsException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['updated_field' => $data['field'], 'lifecycle_status' => $investor->lifecycle_status]);
    }

    public function delete(Request $request, string $investorId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string']]);
        $requestRecord = $this->service->requestDeletion(
            Investor::query()->findOrFail($investorId),
            $data['reason'],
            now()->toDateTimeImmutable(),
        );

        return response()->json([
            'request_id' => $requestRecord->id,
            'status' => $requestRecord->status,
            'scheduled_for' => $requestRecord->scheduled_for,
        ], 202);
    }
}
