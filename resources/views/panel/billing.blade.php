@extends('panel.layout')

@section('title', 'Cobrança')

@section('content')
<h1>Planos e cobrança</h1>
<p class="muted">Receita exclusivamente de assinaturas. Tolerância de 3 dias corridos antes de suspender apenas serviços pagos.</p>

<div class="grid" style="margin-top:18px">
    @forelse ($plans as $plan)
    <section class="card">
        <h2>{{ $plan['name'] ?? $plan['code'] ?? 'Plano' }}</h2>
        <p><strong>R$ {{ number_format(($plan['price_cents'] ?? 0) / 100, 2, ',', '.') }}</strong> @if (($plan['installments'] ?? 1) > 1)em {{ $plan['installments'] }}x @endif</p>
        <p class="muted">{{ $plan['billing_period'] ?? '' }}</p>
        @foreach (($plan['features'] ?? []) as $feature)<span class="tag ok">{{ is_array($feature) ? ($feature['label'] ?? '') : $feature }}</span>@endforeach
    </section>
    @empty
    <section class="card empty">Nenhum plano disponível no piloto.</section>
    @endforelse
</div>

<section class="card" style="margin-top:16px">
    <h2>Assinaturas em atraso</h2>
    <table>
        <thead><tr><th>Cliente</th><th>Plano</th><th>Vencimento</th><th>Tolerância</th><th>Acesso</th></tr></thead>
        <tbody>
        @forelse ($overdue as $item)
            <tr>
                <td data-label="Cliente">{{ $item['investor_id'] }}</td>
                <td data-label="Plano">{{ $item['plan_code'] }}</td>
                <td data-label="Vencimento">{{ $item['due_at'] ?? '—' }}</td>
                <td data-label="Tolerância">{{ $item['grace_days'] ?? 3 }} dias</td>
                <td data-label="Acesso"><span class="tag warn">{{ $item['access_status'] }}</span></td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Nenhuma assinatura em atraso.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="muted">Falha do provedor permanece “em verificação”, sem presumir dívida. Renovação anual: aviso único aos 30 dias; desativação registrada pelo bot bloqueia a renovação imediatamente.</p>
</section>
@endsection
