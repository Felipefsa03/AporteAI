<?php

declare(strict_types=1);

namespace App\Support;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** OPS: remove segredos e PII de mensagens e contextos antes de qualquer handler. */
final class RedactingProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEY = '/(?:pass(word|wd)?|secret|token|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|phone|celular|telefone|email|cpf|cnpj|address|endereco)/iu';

    private const REDACTED = '[REDACTED]';

    public function __invoke(LogRecord $record): LogRecord
    {
        /** @var array{message: string, context: array<string, mixed>, extra: array<string, mixed>} $clean */
        $clean = $this->redact([
            'message' => $record->message,
            'context' => $record->context,
            'extra' => $record->extra,
        ]);

        return $record->with(message: $clean['message'], context: $clean['context'], extra: $clean['extra']);
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function redact(array $record): array
    {
        $record['message'] = $this->redactString((string) ($record['message'] ?? ''));
        $record['context'] = $this->redactValue($record['context'] ?? []);
        $record['extra'] = $this->redactValue($record['extra'] ?? []);

        return $record;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $clean[$key] = is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1
                ? self::REDACTED
                : $this->redactValue($item);
        }

        return $clean;
    }

    private function redactString(string $value): string
    {
        $patterns = [
            '/Bearer\s+[A-Za-z0-9._~+\/=-]+/iu' => 'Bearer '.self::REDACTED,
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u' => self::REDACTED,
            '/(?:\+?55)?\s?\(?\d{2}\)?\s?\d{4,5}-?\d{4}/u' => self::REDACTED,
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/u' => self::REDACTED,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return $value;
    }
}
