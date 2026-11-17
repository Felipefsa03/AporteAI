<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\ClientCRM\InvestorRegistry;
use App\Http\Controllers\Controller;
use App\Models\Investor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InvestorController extends Controller
{
    public function __construct(private readonly InvestorRegistry $registry)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Investor::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string'],
            'phone_e164' => ['required', 'string'],
            'email' => ['required', 'email'],
            'profession' => ['nullable', 'string'],
            'income_monthly_brl' => ['nullable', 'numeric', 'min:0'],
            'lifecycle_status' => ['sometimes', 'string'],
            'source_note' => ['nullable', 'string'],
        ]);

        try {
            $investor = $this->registry->create($data, $this->adminId($request), $this->traceId($request));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $investor], 201);
    }

    public function update(Request $request, string $investorId): JsonResponse
    {
        $investor = Investor::query()->findOrFail($investorId);

        $data = $request->validate([
            'full_name' => ['required', 'string'],
            'phone_e164' => ['required', 'string'],
            'email' => ['required', 'email'],
            'profession' => ['nullable', 'string'],
            'income_monthly_brl' => ['nullable', 'numeric', 'min:0'],
            'lifecycle_status' => ['sometimes', 'string'],
            'source_note' => ['nullable', 'string'],
        ]);

        try {
            $this->registry->update($investor, $data, $this->adminId($request), $this->traceId($request));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $investor]);
    }

    private function adminId(Request $request): string
    {
        return (string) $request->attributes->get('admin_user_id');
    }

    private function traceId(Request $request): string
    {
        $header = $request->header('X-Trace-Id');

        return is_string($header) && $header !== '' ? $header : (string) Str::uuid();
    }
}
