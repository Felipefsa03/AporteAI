<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
    ) {
    }
}
