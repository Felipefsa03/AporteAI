<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Gateway SMS real via Twilio. Credenciais vêm somente do ambiente. */
final class TwilioSmsGateway implements SmsGateway
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01/Accounts';

    public function send(string $toE164, string $message): void
    {
        $sid = (string) config('services.twilio.sid', '');
        $token = (string) config('services.twilio.token', '');
        $from = (string) config('services.twilio.from', '');

        if ($sid === '' || $token === '' || $from === '') {
            throw new RuntimeException('Twilio não configurada: defina TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN e TWILIO_FROM_NUMBER.');
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->timeout(10)
            ->post(self::BASE_URL.'/'.rawurlencode($sid).'/Messages.json', [
                'To' => $toE164,
                'From' => $from,
                'Body' => $message,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Falha ao enviar SMS pela Twilio: '.$response->status());
        }
    }
}
