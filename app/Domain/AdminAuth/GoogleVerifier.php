<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

interface GoogleVerifier
{
    /** @throws GoogleNotConfigured|InvalidGoogleCredential */
    public function verify(string $idToken): GoogleIdentity;
}
