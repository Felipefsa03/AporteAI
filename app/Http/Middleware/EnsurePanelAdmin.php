<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminSession;
use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sessão do painel exclusivo do proprietário; nunca cria credencial de cliente. */
final class EnsurePanelAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->session()->get('panel_token');

        if (! is_string($token) || $token === '') {
            return redirect()->guest(route('panel.login'));
        }

        $session = AdminSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($session === null || $session->mfa_completed_at === null) {
            $request->session()->forget('panel_token');

            return redirect()->guest(route('panel.login'))->with('panel_error', 'Sessão inválida ou expirada.');
        }

        $admin = AdminUser::query()->find($session->admin_user_id);
        if ($admin === null || $admin->active !== true) {
            $request->session()->forget('panel_token');

            return redirect()->guest(route('panel.login'))->with('panel_error', 'Conta do proprietário desativada.');
        }

        $request->attributes->set('panel_admin_id', $admin->id);

        return $next($request);
    }
}
