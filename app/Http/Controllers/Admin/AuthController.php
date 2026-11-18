<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AdminAuth\AdminAuthenticator;
use App\Domain\AdminAuth\InvalidCredentials;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    public function __construct(private readonly AdminAuthenticator $authenticator)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'mfa_code' => ['nullable', 'string', 'size:6'],
            'trace_id' => ['required', 'uuid'],
        ]);

        $key = 'admin-login:'.hash('sha256', $request->ip().'|'.strtolower(trim($data['email'])));

        if (RateLimiter::tooManyAttempts($key, self::LOGIN_MAX_ATTEMPTS)) {
            return response()
                ->json(['message' => 'Muitas tentativas de login. Tente novamente em breve.'], 429)
                ->header('Retry-After', (string) max(1, RateLimiter::availableIn($key)));
        }

        try {
            $result = $this->authenticator->login(
                $data['email'],
                $data['password'],
                $data['mfa_code'] ?? null,
                $data['trace_id'],
            );
        } catch (InvalidCredentials) {
            RateLimiter::hit($key, self::LOGIN_DECAY_SECONDS);

            return response()->json(['message' => 'Credenciais invalidas.'], 401);
        }

        RateLimiter::clear($key);

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expiresAt']->format(DATE_ATOM),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $authorization = $request->header('Authorization', '');
        $token = preg_match('/^Bearer\s+(\S+)$/', $authorization, $matches) === 1 ? $matches[1] : '';
        $traceId = $request->header('X-Trace-Id');

        if ($token !== '' && is_string($traceId)) {
            $this->authenticator->revoke(hash('sha256', $token), $traceId);
        }

        return response()->json(['message' => 'Sessao encerrada.']);
    }
}
