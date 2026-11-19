<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use App\Models\AuditEvent;
use Illuminate\Support\Str;
use Throwable;

/** Decorator que aplica circuit breaker, limites, validação de saída e fallback seguro. */
final class GuardedLlmGateway implements LlmGateway
{
    public function __construct(
        private readonly LlmGateway $inner,
        private readonly LlmCircuitBreaker $breaker,
        private readonly LlmBudget $budget,
        private readonly LlmOutputValidator $validator,
    ) {
    }

    public function complete(string $prompt): string
    {
        $this->breaker->assertClosed();
        $this->budget->assertAllowed();

        try {
            $output = $this->inner->complete($prompt);
        } catch (LlmProviderException $exception) {
            $this->breaker->recordFailure();
            $this->audit('llm.provider_failed', [
                'reason' => $exception->reason,
                'http_status' => $exception->httpStatus,
            ]);

            throw $exception;
        } catch (Throwable) {
            $this->breaker->recordFailure();
            $this->audit('llm.provider_failed', ['reason' => 'llm_unexpected_error']);

            throw new LlmProviderException('Falha inesperada no provedor de IA.', 0, 'llm_unexpected_error');
        }

        try {
            $this->validator->validate($output, $prompt);
        } catch (LlmOutputRejectedException $exception) {
            $this->audit('llm.output_rejected', ['reason' => $exception->reason]);

            throw $exception;
        }

        $this->breaker->recordSuccess();
        $tokens = $this->inner instanceof UsageAwareLlmGateway
            ? $this->inner->lastUsage()['total_tokens'] ?? 0
            : 0;
        $costCents = $this->budget->record((int) $tokens);
        $this->audit('llm.completed', ['tokens' => (int) $tokens, 'cost_cents' => $costCents]);

        return $output;
    }

    /** @param array<string, int|string> $metadata */
    private function audit(string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => 'system',
            'actor_id' => 'llm-gateway',
            'action' => $action,
            'subject_type' => 'system',
            'subject_id' => 'llm-gateway',
            'trace_id' => (string) Str::uuid(),
            'metadata' => $metadata,
            'occurred_at' => now()->toDateTimeImmutable(),
        ]);
    }
}
