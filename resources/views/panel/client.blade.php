@extends('panel.layout')

@section('title', 'Cliente '.($client['full_name'] ?? ''))

@section('content')
<h1>{{ $client['full_name'] ?? 'Cliente' }}</h1>
<p class="muted">{{ $client['phone_e164'] ?? '' }} · {{ $client['email'] ?? '' }} · {{ $client['lifecycle_status'] ?? '—' }}</p>

<div class="grid" style="margin-top:18px">
    <section class="card">
        <h2>Ativação</h2>
        @foreach ((array) $activation as $key => $value)
            @if (is_scalar($value))<p><strong>{{ str_replace('_', ' ', $key) }}:</strong> {{ $value }}</p>@endif
        @endforeach
        <form class="inline" method="post" action="{{ route('panel.client.optin', $client['id'] ?? request()->route('investorId')) }}">
            @csrf
            <label>Evidência do opt-in<input name="evidence_uri" placeholder="piloto://optin/001" required></label>
            <button class="btn" type="submit">Registrar opt-in</button>
        </form>
        <form method="post" action="{{ route('panel.client.activate', $client['id'] ?? request()->route('investorId')) }}" style="margin-top:12px">
            @csrf
            <button class="btn primary" type="submit">Solicitar ativação</button>
        </form>
        <p class="muted">A ativação é bloqueada pela API quando não há opt-in ou elegibilidade.</p>
    </section>

    <section class="card">
        <h2>Carteira simulada</h2>
        @forelse ($portfolio['positions'] ?? [] as $position)
            <p>{{ $position['instrument_id'] ?? $position['instrument']['code'] ?? 'caixa' }} · <span class="tag">{{ $position['quantity'] ?? '' }}</span></p>
        @empty
            <p class="empty">Sem posições. Reserva e destinos são definidos pelo proprietário.</p>
        @endforelse
        <a class="btn" href="{{ route('panel.client.data', request()->route('investorId')) }}">Revisar dados</a>
    </section>

    <section class="card">
        <h2>Métricas do investidor</h2>
        @foreach (($metrics['funnel'] ?? []) as $label => $value)
            <p><strong>{{ str_replace('_', ' ', $label) }}:</strong> {{ $value }}</p>
        @endforeach
        <p class="muted">Receita SaaS: R$ {{ number_format(($metrics['saas_revenue_cents'] ?? 0) / 100, 2, ',', '.') }}</p>
        <p class="muted">Patrimônio acompanhado: R$ {{ number_format(($metrics['overseen_portfolio_cents'] ?? 0) / 100, 2, ',', '.') }}</p>
    </section>

    <section class="card">
        <h2>Dados e retenção</h2>
        @foreach ((array) $dataReview as $key => $value)
            @if (is_scalar($value))<p><strong>{{ str_replace('_', ' ', $key) }}:</strong> {{ $value }}</p>@endif
        @endforeach
        <form method="post" action="{{ route('panel.client.data.export', request()->route('investorId')) }}">
            @csrf<button class="btn" type="submit">Exportar dados</button>
        </form>
        <form method="post" action="{{ route('panel.client.data.delete', request()->route('investorId')) }}" style="margin-top:10px">
            @csrf
            <label>Motivo<input name="reason" required></label>
            <button class="btn danger" type="submit">Solicitar remoção</button>
        </form>
    </section>
</div>
@endsection
