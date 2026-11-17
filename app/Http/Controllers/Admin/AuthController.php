<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AdminAuth\AdminAuthenticator;
use App\Domain\AdminAuth\InvalidCredentials;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
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

        try {
            $result = $this->authenticator->login(
                $data['email'],
                $data['password'],
                $data['mfa_code'] ?? null,
                $data['trace_id'],
            );
        } catch (InvalidCredentials) {
            return response()->json(['message' => 'Credenciais invalidas.'], 401);
        }

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
