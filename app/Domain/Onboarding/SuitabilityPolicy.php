<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use DateTimeImmutable;

/** RB-003: perfil completo, versionado, classificado e com validade explicita. */
final class SuitabilityPolicy
{
    public const PROFILES = ['conservative', 'moderate', 'aggressive'];

    private const REQUIRED = ['objective', 'horizon', 'knowledge', 'liquidity', 'restrictions'];

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'objective' => ['growth', 'income', 'preservation'],
        'horizon' => ['short', 'medium', 'long'],
        'knowledge' => ['limited', 'intermediate', 'advanced'],
        'liquidity' => ['daily', 'monthly', 'quarterly'],
    ];

    /** @var list<string> */
    private const ALLOWED_RESTRICTIONS = ['no_credit', 'no_equity', 'no_derivatives', 'no_funds'];

    /** @param array<string, mixed> $answers */
    public function classify(array $answers): string
    {
        $normalized = $this->normalize($answers);

        if ($normalized === null) {
            return 'incomplete';
        }

        if ($normalized['knowledge'] === 'limited' || $normalized['horizon'] === 'short') {
            return 'conservative';
        }

        return $normalized['knowledge'] === 'advanced' && $normalized['horizon'] === 'long'
            ? 'aggressive'
            : 'moderate';
    }

    /** @param array<string, mixed> $answers */
    public function isComplete(array $answers): bool
    {
        return $this->classify($answers) !== 'incomplete';
    }

    /**
     * Restricoes declaradas sao validas, mas qualquer restricao impede a recomendacao.
     *
     * @param array<string, mixed> $answers
     */
    public function hasNoRestrictions(array $answers): bool
    {
        $normalized = $this->normalize($answers);

        return $normalized !== null && $normalized['restrictions'] === [];
    }

    public function expiresAt(DateTimeImmutable $now): DateTimeImmutable
    {
        $days = max(1, (int) config('compliance.suitability_validity_days', 180));

        return $now->modify('+'.$days.' days');
    }

    /**
     * @param array<string, mixed> $answers
     * @return array{objective: string, horizon: string, knowledge: string, liquidity: string, restrictions: list<string>}|null
     */
    private function normalize(array $answers): ?array
    {
        $normalized = [];

        foreach (self::ALLOWED as $field => $allowed) {
            if (! array_key_exists($field, $answers) || ! is_string($answers[$field])) {
                return null;
            }

            $value = strtolower(trim($answers[$field]));
            if (! in_array($value, $allowed, true)) {
                return null;
            }

            $normalized[$field] = $value;
        }

        if (! array_key_exists('restrictions', $answers)
            || ! is_array($answers['restrictions'])
            || ! array_is_list($answers['restrictions'])) {
            return null;
        }

        $restrictions = [];
        foreach ($answers['restrictions'] as $restriction) {
            if (! is_string($restriction)) {
                return null;
            }

            $value = strtolower(trim($restriction));
            if (! in_array($value, self::ALLOWED_RESTRICTIONS, true)) {
                return null;
            }

            $restrictions[] = $value;
        }

        $normalized['restrictions'] = $restrictions;

        foreach (self::REQUIRED as $field) {
            if (! array_key_exists($field, $normalized)) {
                return null;
            }
        }

        return $normalized;
    }
}
