@extends('panel.layout')

@section('title', 'Métricas')

@section('content')
<h1>Métricas do proprietário</h1>
<p class="muted">{{ $metrics['revenue_vs_portfolio'] ?? 'Receita SaaS e patrimônio acompanhado são métricas distintas.' }}</p>

<div class="grid" style="margin-top:18px">
    <div class="card"><h3>Escopo</h3><div class="metric" style="font-size:20px">{{ $metrics['scope'] ?? 'owner' }}</div></div>
    <div class="card"><h3>Investidores</h3><div class="metric">{{ $metrics['investors'] ?? 0 }}</div></div>
    <div class="card"><h3>Receita SaaS (R$)</h3><div class="metric">{{ number_format(($metrics['saas_revenue_cents'] ?? 0) / 100, 2, ',', '.') }}</div></div>
    <div class="card"><h3>Patrimônio acompanhado (R$)</h3><div class="metric">{{ number_format(($metrics['overseen_portfolio_cents'] ?? 0) / 100, 2, ',', '.') }}</div></div>
</div>

<section class="card" style="margin-top:16px">
    <h2>Funil por investidor</h2>
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

<section class="card" style="margin-top:16px">
    <h2>Retenção</h2>
    @foreach (($metrics['retention'] ?? []) as $label => $value)
        <p><strong>{{ str_replace('_', ' ', $label) }}:</strong> {{ $value }}</p>
    @endforeach
</section>
@endsection
