@extends('panel.layout')

@section('title', 'Visão geral')

@section('content')
<h1>Visão geral do piloto</h1>
<p class="muted">Dados fictícios. Receita de assinaturas e patrimônio acompanhado são métricas distintas.</p>

<div class="grid" style="margin-top:18px">
    <div class="card"><h3>Clientes fictícios</h3><div class="metric">{{ $metrics['investors'] ?? 0 }}</div></div>
    <div class="card"><h3>Assinaturas ativas</h3><div class="metric">{{ $metrics['retention']['active_subscriptions'] ?? 0 }}</div></div>
    <div class="card"><h3>Suspensas por atraso</h3><div class="metric">{{ $metrics['retention']['suspended_subscriptions'] ?? 0 }}</div></div>
    <div class="card"><h3>Receita SaaS (R$)</h3><div class="metric">{{ number_format(($metrics['saas_revenue_cents'] ?? 0) / 100, 2, ',', '.') }}</div></div>
    <div class="card"><h3>Patrimônio acompanhado (R$)</h3><div class="metric">{{ number_format(($metrics['overseen_portfolio_cents'] ?? 0) / 100, 2, ',', '.') }}</div></div>
</div>

<div class="grid" style="margin-top:16px">
    <section class="card">
        <h2>Funil</h2>
        <table>
            <tbody>
            @forelse (($metrics['funnel'] ?? []) as $label => $value)
                <tr><td data-label="Etapa">{{ str_replace('_', ' ', $label) }}</td><td data-label="Quantidade">{{ $value }}</td></tr>
            @empty
                <tr><td colspan="2" class="empty">Funil sem dados no piloto.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    <section class="card">
        <h2>Fila de revisão</h2>
        @forelse ($reviews as $case)
            <p><a href="{{ route('panel.reviews') }}">{{ $case['reason'] ?? $case['id'] }}</a> <span class="tag warn">{{ $case['status'] ?? 'pendente' }}</span></p>
        @empty
            <p class="muted">Nenhum caso aguardando revisão.</p>
        @endforelse
        <a class="btn" href="{{ route('panel.reviews') }}">Abrir fila</a>
    </section>
    <section class="card">
        <h2>Atrasos em acompanhamento</h2>
        @forelse ($overdue as $item)
            <p>{{ $item['investor_id'] }} · {{ $item['plan_code'] }} · <span class="tag warn">{{ $item['access_status'] }}</span></p>
        @empty
            <p class="muted">Nenhuma assinatura em atraso.</p>
        @endforelse
        <a class="btn" href="{{ route('panel.billing') }}">Ver cobrança</a>
    </section>
    <section class="card">
        <h2>Atendimento humano</h2>
        @foreach ((array) $handoff as $key => $value)
            @if (is_scalar($value))<p><strong>{{ str_replace('_', ' ', $key) }}:</strong> {{ $value }}</p>@endif
        @endforeach
        <p class="muted">Bot simulado: +55 75 99260-5555. Número comercial não integrado.</p>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <h2>Gates de liberação</h2>
    <p>C01–C07 e GO permanecem <strong>abertos</strong> e sujeitos a validação humana. Recomendações públicas e liberação comercial continuam desabilitadas.</p>
    <a class="btn primary" href="{{ route('panel.clients') }}">Cadastrar cliente fictício</a>
</section>
@endsection
