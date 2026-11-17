<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use DateTimeImmutable;

/** RB-003: perfil completo, versionado, classificado e com validade explicita. */
final class SuitabilityPolicy
{
    private const REQUIRED = ['objective', 'horizon', 'knowledge', 'liquidity', 'restrictions'];

    /** @param array<string, mixed> $answers */
    public function classify(array $answers): string
    {
        foreach (self::REQUIRED as $field) {
            if (! array_key_exists($field, $answers)) {
                return 'incomplete';
            }
        }

        $knowledge = strtolower((string) $answers['knowledge']);
        $horizon = strtolower((string) $answers['horizon']);

        if ($knowledge === 'limited' || $horizon === 'short') {
            return 'conservative';
        }

        return $knowledge === 'advanced' && $horizon === 'long' ? 'aggressive' : 'moderate';
    }

    /** @param array<string, mixed> $answers */
    public function isComplete(array $answers): bool
    {
        return $this->classify($answers) !== 'incomplete';
    }

    public function expiresAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+'.(int) config('compliance.suitability_validity_days', 180).' days');
    }
}
