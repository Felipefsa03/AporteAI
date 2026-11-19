<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Adapter HTTP compatível com a API Chat Completions; configurável e desligado por padrão. */
final class HttpLlmGateway implements LlmGateway, UsageAwareLlmGateway
{
    /** @var array{total_tokens: int, prompt_tokens: int, completion_tokens: int} */
    private array $usage = ['total_tokens' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0];

    public function complete(string $prompt): string
    {
        $apiKey = config('llm.api_key');
        $baseUrl = config('llm.base_url');
        $model = config('llm.model');

        if (! is_string($apiKey) || $apiKey === '' || ! is_string($baseUrl) || $baseUrl === '' || ! is_string($model) || $model === '') {
            throw new LlmProviderException('Provedor de IA não configurado.', 0, 'llm_unconfigured');
        }

        try {
            /** @var Response $response */
            $response = Http::withToken($apiKey)
                ->timeout((int) config('llm.timeout_seconds', 8))
                ->post(rtrim($baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'temperature' => (float) config('llm.temperature', 0),
                    'max_tokens' => (int) config('llm.max_tokens', 400),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Você explica apenas dados estruturados fornecidos. Nunca invente fatos, números, datas ou fontes.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);
        } catch (Throwable) {
            throw new LlmProviderException('Falha de transporte do provedor de IA.', 0, 'llm_transport_error');
        }

        if (! $response->successful()) {
            throw new LlmProviderException(
                'Provedor de IA retornou erro.',
                $response->status(),
                $response->status() >= 500 || $response->status() === 429 ? 'llm_provider_unavailable' : 'llm_provider_rejected',
            );
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new LlmProviderException('Provedor de IA retornou saída vazia.', $response->status(), 'llm_empty_output');
        }

        $usage = $response->json('usage', []);
        $total = is_array($usage) ? (int) ($usage['total_tokens'] ?? 0) : 0;
        $promptTokens = is_array($usage) ? (int) ($usage['prompt_tokens'] ?? 0) : 0;
        $completionTokens = is_array($usage) ? (int) ($usage['completion_tokens'] ?? 0) : 0;
        $this->usage = [
            'total_tokens' => max(0, $total),
            'prompt_tokens' => max(0, $promptTokens),
            'completion_tokens' => max(0, $completionTokens),
        ];

        return $content;
    }

    /** @return array{total_tokens: int, prompt_tokens: int, completion_tokens: int} */
    public function lastUsage(): array
    {
        return $this->usage;
    }
}
