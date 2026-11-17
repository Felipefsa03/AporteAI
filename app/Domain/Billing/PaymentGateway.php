<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\PaymentRecord;

interface PaymentGateway
{
    /** @throws PaymentGatewayException */
    public function status(PaymentRecord $payment): string;

    /** @throws PaymentGatewayException */
    public function validatedPaymentUrl(PaymentRecord $payment): ?string;
}
