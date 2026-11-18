<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/** FL-05/A14: classifica comandos administrativos antes de qualquer serviço pago. */
final class MessageClassifier
{
    private const ACCENTS = [
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A',
        'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O',
        'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C',
    ];

    public function normalize(string $message): string
    {
        $upper = strtr(mb_strtoupper(trim($message), 'UTF-8'), self::ACCENTS);

        return preg_replace('/\s+/', ' ', $upper) ?? $upper;
    }

    /** @return string|null */
    public function command(string $message): ?string
    {
        $normalized = $this->normalize($message);
        $groups = config('billing.commands', []);

        if (! is_array($groups)) {
            return null;
        }

        foreach ($groups as $command => $phrases) {
            if (! is_array($phrases)) {
                continue;
            }
            foreach ($phrases as $phrase) {
                if ($this->normalize((string) $phrase) === $normalized) {
                    return (string) $command;
                }
            }
        }

        return null;
    }
}
