<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class MetaCloudApiGateway implements WhatsAppGateway
{
    public function sendTemplate(string $phoneE164, string $templateName, string $botPhoneNumberId): string
    {
        return $this->postMessage($botPhoneNumberId, [
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => 'pt_BR'],
            ],
        ], $phoneE164);
    }

    public function sendText(string $phoneE164, string $text, string $botPhoneNumberId): string
    {
        return $this->postMessage($botPhoneNumberId, [
            'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => false],
        ], $phoneE164);
    }

    public function sendImage(
        string $phoneE164,
        string $botPhoneNumberId,
        string $mediaIdOrUrl,
        ?string $caption = null,
    ): string {
        $image = ['link' => $mediaIdOrUrl];
        if (filter_var($mediaIdOrUrl, FILTER_VALIDATE_URL) === false) {
            $image = ['id' => $mediaIdOrUrl];
        }

        if ($caption !== null && $caption !== '') {
            $image['caption'] = $caption;
        }

        return $this->postMessage($botPhoneNumberId, [
            'type' => 'image',
            'image' => $image,
        ], $phoneE164);
    }

    public function sendImageBytes(
        string $phoneE164,
        string $botPhoneNumberId,
        string $pngBytes,
        ?string $caption = null,
    ): string {
        $baseUrl = $this->baseUrl();
        $token = $this->token();

        $upload = Http::withToken($token)
            ->attach('file', $pngBytes, 'aporteai-report.png', ['Content-Type' => 'image/png'])
            ->post(rtrim($baseUrl, '/').'/'.rawurlencode($botPhoneNumberId).'/media', [
                'messaging_product' => 'whatsapp',
            ]);

        $this->assertSuccessful($upload);

        $mediaId = $upload->json('id');
        if (! is_string($mediaId) || $mediaId === '') {
            throw new WhatsAppException('Cloud API nao devolveu media id.');
        }

        return $this->sendImage($phoneE164, $botPhoneNumberId, $mediaId, $caption);
    }

    /** @param array<string, mixed> $message */
    private function postMessage(string $botPhoneNumberId, array $message, string $phoneE164): string
    {
        $payload = array_replace([
            'messaging_product' => 'whatsapp',
            'to' => $phoneE164,
        ], $message);

        $response = Http::withToken($this->token())->post(
            rtrim($this->baseUrl(), '/').'/'.rawurlencode($botPhoneNumberId).'/messages',
            $payload,
        );

        $this->assertSuccessful($response);

        $messageId = $response->json('messages.0.id');
        if (! is_string($messageId) || $messageId === '') {
            throw new WhatsAppException('Cloud API nao devolveu provider_message_id.');
        }

        return $messageId;
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = $response->json();
        $code = data_get($body, 'error.error_data.0.code') ?? data_get($body, 'error.code') ?? 0;
        $code = is_numeric($code) ? (int) $code : 0;

        $transientCodes = config('whatsapp.delivery.transient_codes', []);
        $transient = is_array($transientCodes) ? $transientCodes : [];

        if ($status === 429 || $status >= 500 || in_array($code, $transient, true)) {
            $retryAfter = $response->header('Retry-After');

            throw new WhatsAppTransientException(
                'Falha transitória na Cloud API (codigo '.$code.').',
                $status,
                is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        $permanentReasons = config('whatsapp.delivery.permanent_reasons', []);
        $permanentReasons = is_array($permanentReasons) ? $permanentReasons : [];
        $reason = is_string($permanentReasons[$code] ?? null) ? $permanentReasons[$code] : 'provider_rejected';

        throw new WhatsAppPermanentException('Falha permanente na Cloud API (codigo '.$code.').', $reason);
    }

    private function token(): string
    {
        $token = config('whatsapp.cloud_api.access_token');
        if (! is_string($token) || $token === '') {
            throw new WhatsAppException('Cloud API nao configurada.');
        }

        return $token;
    }

    private function baseUrl(): string
    {
        $baseUrl = config('whatsapp.cloud_api.base_url');
        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new WhatsAppException('Cloud API nao configurada.');
        }

        return $baseUrl;
    }
}
