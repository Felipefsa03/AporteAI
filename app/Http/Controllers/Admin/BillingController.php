<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\AccessPolicy;
use App\Domain\Billing\BillingCatalog;
use App\Domain\Billing\BillingException;
use App\Domain\Billing\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Models\PaymentRecord;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BillingController extends Controller
{
    public function __construct(
        private readonly BillingCatalog $catalog,
        private readonly SubscriptionService $service,
        private readonly AccessPolicy $access,
    ) {
    }

    public function plans(): JsonResponse
    {
        $features = $this->catalog->features();

        return response()->json([
            'data' => array_map(
                static fn (array $plan): array => $plan + ['features' => $features],
                $this->catalog->plans(),
            ),
        ]);
    }

    public function overdue(): JsonResponse
    {
        $subscriptions = Subscription::query()
            ->whereIn('status', ['past_due', 'suspended_nonpayment'])
            ->get()
            ->map(static fn (Subscription $subscription): array => [
                'id' => $subscription->id,
                'investor_id' => $subscription->investor_id,
                'plan_code' => $subscription->plan_code,
                'due_at' => $subscription->due_at?->format(DATE_ATOM),
                'grace_days' => $subscription->grace_days,
                'access_status' => $subscription->access_status,
            ]);

        return response()->json(['data' => $subscriptions]);
    }

    public function reconcile(Request $request, string $subscriptionId): JsonResponse
    {
        $data = $request->validate([
            'provider_ref' => ['required', 'string'],
            'external_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:paid,processing,failed,refunded'],
        ]);

        $subscription = Subscription::query()->findOrFail($subscriptionId);
        $payment = PaymentRecord::query()
            ->where('subscription_id', $subscription->id)
            ->where('provider_ref', $data['provider_ref'])
            ->firstOrFail();

        try {
            $changed = $this->service->reconcile(
                $subscription,
                $payment,
                $data['external_id'],
                $data['status'],
                now()->toDateTimeImmutable(),
            );
        } catch (BillingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'reconciled' => $changed,
            'access' => $this->access->label($subscription),
        ]);
    }

    public function suspend(Request $request, string $subscriptionId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string']]);
        $subscription = Subscription::query()->findOrFail($subscriptionId);

        try {
            $this->service->suspend($subscription, $data['reason'], now()->toDateTimeImmutable());
        } catch (BillingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['access' => $this->access->label($subscription)]);
    }

    public function reactivate(Request $request, string $subscriptionId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string']]);
        $subscription = Subscription::query()->findOrFail($subscriptionId);

        try {
            $this->service->reactivate($subscription, $data['reason'], now()->toDateTimeImmutable());
        } catch (BillingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['access' => $this->access->label($subscription)]);
    }
}
