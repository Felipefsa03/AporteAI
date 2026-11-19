<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

/** Barreira contra saída inventada, PII, URLs e instruções injetadas na resposta. */
final class LlmOutputValidator
{
    private const ALLOWED_TOKENS = ['BRL', 'LGPD', 'CPF', 'CNPJ'];

    /** @param array{min_chars?: int, max_chars?: int} $limits */
    public function __construct(private readonly array $limits = [])
    {
    }

    public function validate(string $output, string $prompt): void
    {
        $output = trim($output);
        $min = max(1, (int) ($this->limits['min_chars'] ?? 20));
        $max = max($min, (int) ($this->limits['max_chars'] ?? 1200));

        if (strlen($output) < $min || strlen($output) > $max) {
            throw new LlmOutputRejectedException('llm_output_length');
        }

        foreach ([
            '~https?://~i',
            '~www\.~i',
            '~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i',
            '~\b\d{3}\.\d{3}\.\d{3}-\d{2}\b~',
            '~\b(?:ignore|ignored|system prompt|senha|api key|chave de api|token de acesso)\b~i',
            '~<\|~',
        ] as $pattern) {
            if (preg_match($pattern, $output) === 1) {
                throw new LlmOutputRejectedException('llm_output_unsafe');
            }
        }

        $allowedNumbers = $this->numbers($prompt);
        foreach ($this->numbers($output) as $number) {
            if (! in_array($number, $allowedNumbers, true)) {
                throw new LlmOutputRejectedException('llm_output_invented_number');
            }
        }

        $allowedTokens = $this->uppercaseTokens($prompt);
        foreach ($this->uppercaseTokens($output) as $token) {
            if (! in_array($token, $allowedTokens, true)) {
                throw new LlmOutputRejectedException('llm_output_invented_token');
            }
        }
    }

    /** @return list<string> */
    private function numbers(string $text): array
    {
        preg_match_all('~\d+(?:[.,]\d+)*(?:%)?~u', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /** @return list<string> */
    private function uppercaseTokens(string $text): array
    {
        preg_match_all('~\b[A-Z][A-Z0-9][A-Z0-9_-]*\b~', $text, $matches);
        $tokens = array_merge(self::ALLOWED_TOKENS, $matches[0] ?? []);

        foreach ($matches[0] ?? [] as $token) {
            foreach (explode('-', (string) $token) as $part) {
                if (preg_match('~^[A-Z][A-Z0-9]+$~', $part) === 1) {
                    $tokens[] = $part;
                }
            }
        }

        return array_values(array_unique($tokens));
    }
}
