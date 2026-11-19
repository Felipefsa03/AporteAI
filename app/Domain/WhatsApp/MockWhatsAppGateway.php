<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

/** Adapter padrao: determinístico, sem rede e sem destinatario real. */
final class MockWhatsAppGateway implements WhatsAppGateway
{
    public function sendTemplate(string $phoneE164, string $templateName, string $botPhoneNumberId): string
    {
        return $this->providerId('template', $phoneE164, $botPhoneNumberId, $templateName);
    }

    public function sendText(string $phoneE164, string $text, string $botPhoneNumberId): string
    {
        return $this->providerId('text', $phoneE164, $botPhoneNumberId, $text);
    }

    public function sendImage(
        string $phoneE164,
        string $botPhoneNumberId,
        string $mediaIdOrUrl,
        ?string $caption = null,
    ): string {
        return $this->providerId('image', $phoneE164, $botPhoneNumberId, $mediaIdOrUrl.($caption ?? ''));
    }

    public function sendImageBytes(
        string $phoneE164,
        string $botPhoneNumberId,
        string $pngBytes,
        ?string $caption = null,
    ): string {
        return $this->providerId('image-bytes', $phoneE164, $botPhoneNumberId, $pngBytes.($caption ?? ''));
    }

    private function providerId(string $kind, string $phone, string $bot, string $content): string
    {
        $digest = hash('sha256', implode("\0", [$kind, $phone, $bot, $content]));

        return 'mock-wamid-'.substr($digest, 0, 24);
    }
}
