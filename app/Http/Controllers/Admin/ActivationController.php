<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\WhatsApp\ActivationService;
use App\Domain\WhatsApp\HumanHandoff;
use App\Domain\WhatsApp\WhatsAppException;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Investor;
use App\Models\WhatsAppOptin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ActivationController extends Controller
{
    public function __construct(
        private readonly ActivationService $service,
        private readonly HumanHandoff $handoff,
    ) {
    }

    public function optIn(Request $request, string $investorId): JsonResponse
    {
        $investor = Investor::query()->findOrFail($investorId);
        $data = $request->validate([
            'evidence_uri' => ['required', 'string'],
            'phone_e164' => ['sometimes', 'string'],
        ]);

        $phone = $data['phone_e164'] ?? $investor->phone_e164;
        $now = now()->toDateTimeImmutable();
        $key = hash('sha256', $investor->id.'|'.$phone.'|'.$data['evidence_uri']);

        $optin = WhatsAppOptin::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'id' => (string) Str::uuid(),
                'investor_id' => $investor->id,
                'phone_e164' => $phone,
                'evidence_uri' => $data['evidence_uri'],
                'accepted_at' => $now,
            ],
        );

        return response()->json(['optin_id' => $optin->id], 201);
    }

    public function activate(Request $request, string $investorId): JsonResponse
    {
        $investor = Investor::query()->findOrFail($investorId);

        try {
            $admin = AdminUser::query()->findOrFail($request->attributes->get('admin_user_id'));
            $activation = $this->service->requestActivation($investor, $admin, now()->toDateTimeImmutable());
        } catch (WhatsAppException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'activation_request_id' => $activation->id,
            'status' => $activation->status,
        ], 202);
    }

    public function status(string $investorId): JsonResponse
    {
        return response()->json($this->service->status(Investor::query()->findOrFail($investorId)));
    }

    public function handoff(): JsonResponse
    {
        return response()->json($this->handoff->instructions());
    }
}
