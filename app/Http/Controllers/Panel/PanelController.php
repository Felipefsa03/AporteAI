<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Models\AdminSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Painel exclusivo do proprietário: camada HTML sobre a API administrativa existente.
 * Nenhuma regra de negócio é duplicada aqui; todas as ações passam pela API e seu middleware MFA.
 */
final class PanelController
{
    public function __construct(private readonly Router $router)
    {
    }

    public function loginForm(): View|RedirectResponse
    {
        if (session('panel_token') !== null) {
            return redirect()->route('panel.dashboard');
        }

        return view('panel.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'mfa_code' => ['nullable', 'string', 'size:6'],
        ]);

        [$status, $payload] = $this->api('POST', 'admin/auth/login', [
            'email' => $data['email'],
            'password' => $data['password'],
            'mfa_code' => $data['mfa_code'] ?? null,
            'trace_id' => (string) Str::uuid(),
        ]);

        if ($status !== 200 || ! is_string($payload['token'] ?? null)) {
            return back()
                ->withInput($request->except('password', 'mfa_code'))
                ->with('panel_error', $this->errorMessage($payload, 'Não foi possível entrar no painel.'));
        }

        $request->session()->put('panel_token', $payload['token']);
        $request->session()->put('panel_owner_email', mb_strtolower(trim($data['email'])));

        return redirect()->route('panel.dashboard')->with('panel_notice', 'Sessão iniciada com MFA.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->api('POST', 'admin/auth/logout');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login')->with('panel_notice', 'Sessão encerrada.');
    }

    public function dashboard(): View
    {
        [, $metrics] = $this->api('GET', 'admin/metrics');
        [, $clients] = $this->api('GET', 'admin/investors');
        [, $reviews] = $this->api('GET', 'admin/reviews');
        [, $billing] = $this->api('GET', 'admin/billing/overdue');
        [, $handoff] = $this->api('GET', 'admin/handoff');

        return view('panel.dashboard', [
            'metrics' => $metrics,
            'clients' => $clients['data'] ?? [],
            'reviews' => $reviews['cases'] ?? [],
            'overdue' => $billing['data'] ?? [],
            'handoff' => $handoff,
        ]);
    }

    public function clients(): View
    {
        [, $payload] = $this->api('GET', 'admin/investors');

        return view('panel.clients', ['clients' => $payload['data'] ?? []]);
    }

    public function storeClient(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string'],
            'phone_e164' => ['required', 'string'],
            'email' => ['required', 'email'],
            'profession' => ['nullable', 'string'],
            'income_monthly_brl' => ['nullable', 'numeric', 'min:0'],
            'source_note' => ['nullable', 'string'],
        ]);

        [$status, $payload] = $this->api('POST', 'admin/investors', $data);
        if ($status !== 201) {
            return back()->withInput($request->except('income_monthly_brl'))->with('panel_error', $this->errorMessage($payload));
        }

        return redirect()->route('panel.client', $payload['data']['id'] ?? '')
            ->with('panel_notice', 'Cliente fictício cadastrado pelo proprietário.');
    }

    public function client(string $investorId): View
    {
        [, $activation] = $this->api('GET', "admin/clients/{$investorId}/activation");
        [, $portfolio] = $this->api('GET', "admin/clients/{$investorId}/portfolio");
        [, $metrics] = $this->api('GET', "admin/clients/{$investorId}/metrics");
        [, $data] = $this->api('GET', "admin/clients/{$investorId}/data/review");

        $client = collect($this->api('GET', 'admin/investors')[1]['data'] ?? [])
            ->firstWhere('id', $investorId);

        return view('panel.client', [
            'client' => $client,
            'activation' => $activation,
            'portfolio' => $portfolio,
            'metrics' => $metrics,
            'dataReview' => $data,
        ]);
    }

    public function optIn(Request $request, string $investorId): RedirectResponse
    {
        $data = $request->validate(['evidence_uri' => ['required', 'string']]);
        [$status, $payload] = $this->api('POST', "admin/clients/{$investorId}/opt-ins", $data);

        return back()->with($status === 201
            ? ['panel_notice' => 'Opt-in registrado com evidência.']
            : ['panel_error' => $this->errorMessage($payload)]);
    }

    public function activate(Request $request, string $investorId): RedirectResponse
    {
        [$status, $payload] = $this->api('POST', "admin/clients/{$investorId}/activate");
        if ($status !== 202) {
            return back()->with('panel_error', $this->errorMessage($payload));
        }

        return back()->with('panel_notice', 'Ativação solicitada; elegibilidade validada pela API.');
    }

    public function manualSettlement(Request $request, string $paymentId): RedirectResponse
    {
        $data = $request->validate(['evidence_uri' => ['required', 'string']]);
        [$status, $payload] = $this->api('POST', "admin/payments/{$paymentId}/manual-settlement", $data);
        if ($status !== 200) {
            return back()->with('panel_error', $this->errorMessage($payload));
        }

        return back()->with('panel_notice', 'Baixa manual registrada e auditada.');
    }

    public function reviews(): View
    {
        [, $payload] = $this->api('GET', 'admin/reviews');

        return view('panel.reviews', ['reviews' => $payload['cases'] ?? []]);
    }

    public function resolveReview(Request $request, string $caseId): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'string'],
            'justification' => ['required', 'string'],
        ]);
        [$status, $payload] = $this->api('POST', "admin/reviews/{$caseId}/resolve", $data);
        if ($status !== 200) {
            return back()->with('panel_error', $this->errorMessage($payload));
        }

        return back()->with('panel_notice', 'Revisão concluída com registro de data e motivo.');
    }

    public function billing(): View
    {
        [, $plans] = $this->api('GET', 'admin/billing/plans');
        [, $overdue] = $this->api('GET', 'admin/billing/overdue');

        return view('panel.billing', [
            'plans' => $plans['data'] ?? [],
            'overdue' => $overdue['data'] ?? [],
        ]);
    }

    public function metrics(): View
    {
        [, $snapshot] = $this->api('GET', 'admin/metrics');

        return view('panel.metrics', ['metrics' => $snapshot]);
    }

    public function dataReview(string $investorId): View
    {
        [$status, $payload] = $this->api('GET', "admin/clients/{$investorId}/data/review");
        abort_if($status !== 200, $status, $this->errorMessage($payload));

        return view('panel.data', ['investorId' => $investorId, 'review' => $payload]);
    }

    public function exportData(string $investorId): Response
    {
        [$status, $payload] = $this->api('GET', "admin/clients/{$investorId}/data/export");
        abort_if($status !== 200, $status, $this->errorMessage($payload));

        return response(
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="aporteai-export-'.Str::slug($investorId).'.json"',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function deleteData(Request $request, string $investorId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        [$status, $payload] = $this->api('POST', "admin/clients/{$investorId}/data/deletion", $data);
        if ($status !== 202) {
            return back()->with('panel_error', $this->errorMessage($payload));
        }

        return back()->with('panel_notice', 'Solicitação de remoção registrada; retenção preservada.');
    }

    public function executeDataRequest(Request $request, string $requestId): RedirectResponse
    {
        $data = $request->validate(['dry_run' => ['required', 'boolean']]);
        [$status, $payload] = $this->api('POST', "admin/data-rights/requests/{$requestId}/execution", $data);
        if ($status !== 200) {
            return back()->with('panel_error', $this->errorMessage($payload));
        }

        return back()->with('panel_notice', 'Execução de direitos de dados concluída.');
    }

    /** @param array<string, mixed> $payload */
    private function errorMessage(array $payload, string $fallback = 'Operação recusada pela API.'): string
    {
        $message = $payload['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    /**
     * Executa a ação da API administrativa sobre a rota já registrada, preservando o corpo
     * da requisição e os atributos de admin que o middleware MFA normalmente define.
     *
     * @param  array<string, mixed>  $payload
     * @return array{int, array<string, mixed>}
     */
    private function api(string $method, string $uri, array $payload = []): array
    {
        $token = session('panel_token');
        $request = Request::create('/api/v1/'.$uri, $method, $payload, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.(is_string($token) ? $token : ''),
            'HTTP_X_TRACE_ID' => (string) Str::uuid(),
            'HTTP_ACCEPT' => 'application/json',
        ], $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR));

        $adminSession = is_string($token)
            ? AdminSession::query()
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->first()
            : null;
        if ($adminSession !== null) {
            $request->attributes->set('admin_user_id', $adminSession->admin_user_id);
            $request->attributes->set('admin_session_id', $adminSession->id);
        }

        try {
            $route = $this->router->getRoutes()->match($request);
            $route->bind($request);
            $action = $route->getActionName();
            if (! is_string($action) || ! str_contains($action, '@')) {
                return [500, ['message' => 'Rota administrativa inválida.']];
            }
            [$class, $actionMethod] = explode('@', $action, 2);
            $previousRequest = app()->bound(Request::class) ? app(Request::class) : null;
            app()->instance(Request::class, $request);
            try {
                $response = app()->call([app($class), $actionMethod], $route->parameters());
            } finally {
                if ($previousRequest !== null) {
                    app()->instance(Request::class, $previousRequest);
                }
            }
        } catch (ValidationException $exception) {
            return [422, ['message' => 'Dados inválidos.', 'errors' => $exception->errors()]];
        } catch (ModelNotFoundException) {
            return [404, ['message' => 'Recurso não encontrado.']];
        }

        $decoded = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }
}
