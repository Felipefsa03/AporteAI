<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization', '');

        if (preg_match('/^Bearer\s+(\S+)$/', $authorization, $matches) !== 1) {
            return response()->json(['message' => 'Autenticacao do proprietario requerida.'], 401);
        }

        $session = AdminSession::query()
            ->where('token_hash', hash('sha256', $matches[1]))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($session === null || $session->mfa_completed_at === null) {
            return response()->json(['message' => 'Sessao do proprietario invalida ou expirada.'], 401);
        }

        $request->attributes->set('admin_user_id', $session->admin_user_id);
        $request->attributes->set('admin_session_id', $session->id);

        return $next($request);
    }
}
