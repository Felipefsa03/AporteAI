<?php

declare(strict_types=1);

namespace App\Domain\AdminAuth;

use Illuminate\Support\Facades\Http;

/**
 * Verifica o ID token do Google Identity Services com o endpoint tokeninfo.
 * O client ID vem de GOOGLE_CLIENT_ID; nenhum segredo é gravado no repositório.
 */
final class HttpGoogleVerifier implements GoogleVerifier
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function verify(string $idToken): GoogleIdentity
    {
        $clientId = (string) config('services.google.client_id', '');
        if ($clientId === '') {
            throw new GoogleNotConfigured('Login com Google não configurado neste ambiente.');
        }

        if ($idToken === '' || strlen($idToken) > 4096) {
            throw new InvalidGoogleCredential('Credencial Google inválida.');
        }

        $response = Http::timeout(5)->get(self::TOKENINFO_URL, ['id_token' => $idToken]);
        if (! $response->successful()) {
            throw new InvalidGoogleCredential('Credencial Google inválida.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();
        $audience = $payload['aud'] ?? null;
        $subject = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;
        $verified = $payload['email_verified'] ?? false;

        if (! is_string($subject) || $subject === ''
            || ! is_string($email) || $email === ''
            || ! is_string($audience) || ! hash_equals($clientId, $audience)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! $this->isVerified($verified)) {
            throw new InvalidGoogleCredential('Identidade Google não corresponde ao proprietário.');
        }

        return new GoogleIdentity($subject, mb_strtolower(trim($email)), true);
    }

    private function isVerified(mixed $verified): bool
    {
        return $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';
    }
}
