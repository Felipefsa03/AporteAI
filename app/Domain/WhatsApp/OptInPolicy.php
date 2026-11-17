<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use App\Models\Investor;
use App\Models\WhatsAppOptin;
use DateTimeImmutable;

/** RB-023: sem opt-in verificavel nao existe contato proativo. */
final class OptInPolicy
{
    public function isEligible(Investor $investor, DateTimeImmutable $at): bool
    {
        if ($investor->trashed()) {
            return false;
        }

        return WhatsAppOptin::query()
            ->where('investor_id', $investor->id)
            ->where('phone_e164', $investor->phone_e164)
            ->whereNotNull('evidence_uri')
            ->where('accepted_at', '<=', $at)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function revokeActive(Investor $investor, DateTimeImmutable $at): void
    {
        WhatsAppOptin::query()
            ->where('investor_id', $investor->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $at]);
    }
}
