<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Transporte SMS do piloto: registra a mensagem em arquivo local, sem provider real.
 * Em produção, trocar a vinculação por um gateway autorizado; nenhuma credencial vive aqui.
 */
final class LogSmsGateway implements SmsGateway
{
    public function send(string $toE164, string $message): void
    {
        // Nunca gravar o corpo: o OTP viaja apenas para o gateway autorizado.
        Log::channel('sms')->info('sms.outbound', [
            'to' => $this->mask($toE164),
            'template' => 'owner_mfa_code',
            'code' => '[redacted]',
            'length' => strlen($message),
            'dry_run' => true,
        ]);
    }

    private function mask(string $phone): string
    {
        if (strlen($phone) < 6) {
            return '***';
        }

        return substr($phone, 0, 3).str_repeat('*', max(0, strlen($phone) - 6)).substr($phone, -3);
    }
}
