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

    /** Opt-out explícito prevalece até que exista aceite mais recente. */
    public function isOptedOut(Investor $investor): bool
    {
        $latest = WhatsAppOptin::query()
            ->where('investor_id', $investor->id)
            ->latest('accepted_at')
            ->first();

        return $latest !== null && $latest->revoked_at !== null;
    }

    public function revokeActive(Investor $investor, DateTimeImmutable $at): void
    {
        WhatsAppOptin::query()
            ->where('investor_id', $investor->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $at]);
    }
}
