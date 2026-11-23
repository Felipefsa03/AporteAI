<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

/** Desafio SMS inexistente, já consumido ou fora da janela de validade. */
final class MfaChallengeExpired extends InvalidCredentials
{
}
