<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\PaymentWebhookAuthenticator;
use App\Domain\Billing\SubscriptionService;
use App\Models\PaymentRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentWebhookAuthenticator $authenticator,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->authenticator->verify($raw, $request->header('x-gateway-signature'))) {
            return response()->json(['message' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'invalid payload'], 422);
        }

        $externalId = isset($payload['id']) ? (string) $payload['id'] : '';
        $status = isset($payload['status']) ? (string) $payload['status'] : '';
        $providerRef = isset($payload['provider_ref']) ? (string) $payload['provider_ref'] : '';

        if ($externalId === '' || $status === '' || $providerRef === '') {
            return response()->json(['message' => 'missing fields'], 422);
        }

        $payment = PaymentRecord::query()->where('provider_ref', $providerRef)->first();

        if ($payment === null) {
            return response()->json(['applied' => false, 'reason' => 'unknown_payment'], 404);
        }

        $subscription = $payment->subscription()->firstOrFail();
        $now = now()->toDateTimeImmutable();

        $applied = $this->subscriptions->reconcile(
            $subscription,
            $payment,
            $externalId,
            $status,
            $now,
        );

        return response()->json([
            'applied' => $applied,
            'access' => $payment->status === 'paid' && $payment->reconciled_at !== null ? 'reconciled' : 'unchanged',
        ]);
    }
}
