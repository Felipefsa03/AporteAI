<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

interface WhatsAppGateway
{
    /** Envia template aprovado exclusivamente pelo numero do bot. */
    public function sendTemplate(string $phoneE164, string $templateName, string $botPhoneNumberId): string;

    /** Resposta de texto dentro da janela de atendimento. */
    public function sendText(string $phoneE164, string $text, string $botPhoneNumberId): string;

    /** Midia ja hospedada ou enviada previamente a Cloud API. */
    public function sendImage(
        string $phoneE164,
        string $botPhoneNumberId,
        string $mediaIdOrUrl,
        ?string $caption = null,
    ): string;

    /** Faz upload de um PNG e envia a imagem pelo numero do bot. */
    public function sendImageBytes(
        string $phoneE164,
        string $botPhoneNumberId,
        string $pngBytes,
        ?string $caption = null,
    ): string;
}
