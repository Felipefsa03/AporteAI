<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

/** Código SMS corretamente recebido, mas diferente do esperado. */
final class InvalidMfaCode extends InvalidCredentials
{
}
