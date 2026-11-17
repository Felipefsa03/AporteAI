<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

use Illuminate\Support\Facades\Http;

final class MetaCloudApiGateway implements WhatsAppGateway
{
    public function sendTemplate(string $phoneE164, string $templateName, string $botPhoneNumberId): string
    {
        $token = config('whatsapp.cloud_api.access_token');
        $baseUrl = config('whatsapp.cloud_api.base_url');

        if (! is_string($token) || $token === '' || ! is_string($baseUrl) || $baseUrl === '') {
            throw new WhatsAppException('Cloud API nao configurada.');
        }

        $response = Http::withToken($token)->post(
            rtrim($baseUrl, '/').'/'.rawurlencode($botPhoneNumberId).'/messages',
            [
                'messaging_product' => 'whatsapp',
                'to' => $phoneE164,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => 'pt_BR'],
                ],
            ],
        );

        if ($response->failed()) {
            throw new WhatsAppException('Falha no envio do template pelo Cloud API.');
        }

        $messageId = $response->json('messages.0.id');
        if (! is_string($messageId) || $messageId === '') {
            throw new WhatsAppException('Cloud API nao devolveu provider_message_id.');
        }

        return $messageId;
    }
}
