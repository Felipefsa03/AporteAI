<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use RuntimeException;

/** RB-033: falha temporária do provedor nunca vira inadimplência confirmada. */
final class PaymentGatewayException extends RuntimeException
{
}
