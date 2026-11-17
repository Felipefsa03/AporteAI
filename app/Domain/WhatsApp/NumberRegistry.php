<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

/** RB-027/A12: o numero comercial e humano; somente o bot usa a Cloud API. */
final class NumberRegistry
{
    public function botPhoneNumberId(): string
    {
        $id = config('whatsapp.bot.phone_number_id');

        if (! is_string($id) || $id === '') {
            throw new WhatsAppException('Numero do bot nao configurado.');
        }

        return $id;
    }

    public function botNumberE164(): string
    {
        $number = config('whatsapp.bot.number_e164');

        if (! is_string($number) || $number === '') {
            throw new WhatsAppException('Numero do bot nao configurado.');
        }

        return $number;
    }

    public function commercialNumberE164(): string
    {
        $number = config('whatsapp.commercial.number_e164');

        if (! is_string($number) || $number === '') {
            throw new WhatsAppException('Numero comercial nao configurado.');
        }

        return $number;
    }

    public function assertDistinct(): void
    {
        if ($this->botNumberE164() === $this->commercialNumberE164()) {
            throw new WhatsAppException('Bot e numero comercial devem ser distintos.');
        }
    }
}
