<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Privacy\DataRightsException;
use App\Domain\Privacy\DataRightsService;
use App\Domain\Privacy\RetentionExecutionService;
use App\Http\Controllers\Controller;
use App\Models\DataRightsRequest;
use App\Models\Investor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DataRightsController extends Controller
{
    public function __construct(
        private readonly DataRightsService $service,
        private readonly RetentionExecutionService $executions,
    ) {
    }

    public function export(Request $request, string $investorId): JsonResponse
    {
        $payload = $this->service->export(
            $this->scopedInvestor($request, $investorId),
            now()->toDateTimeImmutable(),
        );

        return response()->json($payload)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function review(Request $request, string $investorId): JsonResponse
    {
        return response()->json(
            $this->service->review(
                $this->scopedInvestor($request, $investorId),
                now()->toDateTimeImmutable(),
            ),
        );
    }

    public function correct(Request $request, string $investorId): JsonResponse
    {
        $correctable = (array) config('retention.correctable', []);
        $field = $request->input('field');
        $valueRules = is_string($field)
            ? (array) config('retention.correction_rules.'.$field, ['required'])
            : ['required'];
        $data = $request->validate([
            'field' => ['required', 'string', Rule::in($correctable)],
            'value' => $valueRules,
        ]);

        try {
            $investor = $this->service->correct(
                $this->scopedInvestor($request, $investorId),
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
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $requestRecord = $this->service->requestDeletion(
            $this->scopedInvestor($request, $investorId),
            $data['reason'],
            now()->toDateTimeImmutable(),
        );

        return response()->json([
            'request_id' => $requestRecord->id,
            'status' => $requestRecord->status,
            'scheduled_for' => $requestRecord->scheduled_for,
        ], 202);
    }

    public function execute(Request $request, string $requestId): JsonResponse
    {
        $data = $request->validate(['dry_run' => ['sometimes', 'boolean']]);
        $record = DataRightsRequest::query()->findOrFail($requestId);
        $this->assertScope($request, Investor::query()->findOrFail($record->investor_id));

        $dryRun = array_key_exists('dry_run', $data)
            ? (bool) $data['dry_run']
            : (bool) config('retention.dry_run', true);

        try {
            $report = $this->executions->execute(
                $record,
                $dryRun,
                now()->toDateTimeImmutable(),
            );
        } catch (DataRightsException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($report);
    }

    private function scopedInvestor(Request $request, string $investorId): Investor
    {
        $investor = Investor::query()->findOrFail($investorId);
        $this->assertScope($request, $investor);

        return $investor;
    }

    private function assertScope(Request $request, Investor $investor): void
    {
        $adminId = (string) $request->attributes->get('admin_user_id');
        if ($adminId === '' || (string) $investor->created_by_admin_id !== $adminId) {
            throw new AuthorizationException('Escopo do proprietário não permite acessar este titular.');
        }
    }
}
