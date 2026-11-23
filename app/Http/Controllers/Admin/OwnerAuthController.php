<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AdminAuth\GoogleNotConfigured;
use App\Domain\AdminAuth\InvalidCredentials;
use App\Domain\AdminAuth\InvalidGoogleCredential;
use App\Domain\AdminAuth\InvalidMfaCode;
use App\Domain\AdminAuth\MfaChallengeExpired;
use App\Domain\AdminAuth\OwnerGoogleLogin;
use App\Domain\AdminAuth\OwnerMfaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class OwnerAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly OwnerMfaService $mfa,
        private readonly OwnerGoogleLogin $google,
    ) {
    }

    public function startPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'trace_id' => ['required', 'uuid'],
        ]);

        $key = $this->rateKey($request, 'password:'.mb_strtolower(trim($data['email'])));
        if ($this->tooMany($key)) {
            return $this->throttled($key);
        }

        try {
            $result = $this->mfa->start($data['email'], $data['password'], $data['trace_id']);
        } catch (InvalidCredentials) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        RateLimiter::clear($key);

        return response()->json([
            'challenge_token' => $result['challengeToken'],
            'expires_at' => $result['expiresAt']->format(DATE_ATOM),
            'masked_phone' => $result['maskedPhone'],
        ]);
    }

    public function verifyPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
            'trace_id' => ['required', 'uuid'],
        ]);

        $key = $this->rateKey($request, 'otp:'.hash('sha256', $data['challenge_token']));
        if ($this->tooMany($key)) {
            return $this->throttled($key);
        }

        try {
            $result = $this->mfa->verify($data['challenge_token'], $data['code'], $data['trace_id']);
        } catch (MfaChallengeExpired $exception) {
            return response()->json(['message' => $exception->getMessage()], 410);
        } catch (InvalidMfaCode $exception) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return response()->json(['message' => $exception->getMessage()], 401);
        } catch (InvalidCredentials $exception) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return response()->json(['message' => $exception->getMessage()], 401);
        }

        RateLimiter::clear($key);

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expiresAt']->format(DATE_ATOM),
        ]);
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:4096', 'regex:/^\S+$/'],
            'trace_id' => ['required', 'uuid'],
        ]);

        $key = $this->rateKey($request, 'google');
        if ($this->tooMany($key)) {
            return $this->throttled($key);
        }

        try {
            $result = $this->google->login($data['credential'], $data['trace_id']);
        } catch (GoogleNotConfigured $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (InvalidGoogleCredential|InvalidCredentials $exception) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return response()->json(['message' => 'Conta Google não autorizada para o painel.'], 401);
        }

        RateLimiter::clear($key);

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expiresAt']->format(DATE_ATOM),
        ]);
    }

    private function rateKey(Request $request, string $scope): string
    {
        return 'owner-auth:'.$scope.':'.hash('sha256', (string) $request->ip());
    }

    private function tooMany(string $key): bool
    {
        return RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS);
    }

    private function throttled(string $key): JsonResponse
    {
        return response()
            ->json(['message' => 'Muitas tentativas. Tente novamente em breve.'], 429)
            ->header('Retry-After', (string) max(1, RateLimiter::availableIn($key)));
    }
}
