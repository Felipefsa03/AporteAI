<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\LocalPaymentGateway;
use App\Domain\Billing\PaymentGateway;
use App\Domain\Conversation\LlmGateway;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, LocalPaymentGateway::class);
        $this->app->bind(LlmGateway::class, static fn (): ?LlmGateway => null);
    }
}
