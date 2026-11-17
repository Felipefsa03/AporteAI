<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Review\ReviewException;
use App\Domain\Review\ReviewService;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ReviewCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $service)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'cases' => ReviewCase::query()->orderByDesc('created_at')->get()->toArray(),
        ]);
    }

    public function resolve(Request $request, string $caseId): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'string'],
            'justification' => ['required', 'string'],
        ]);
        $admin = AdminUser::query()->findOrFail($request->attributes->get('admin_user_id'));

        try {
            $case = $this->service->resolve(
                ReviewCase::query()->findOrFail($caseId),
                $admin,
                $data['outcome'],
                $data['justification'],
                now()->toDateTimeImmutable(),
            );
        } catch (ReviewException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['status' => $case->status, 'resolved_at' => $case->resolved_at]);
    }
}
