@extends('panel.layout')

@section('title', 'Dados e retenção')

@section('content')
<h1>Dados do titular</h1>
<p class="muted">Inventário, retenção e privacidade preservados. A extração é restrita ao escopo do proprietário.</p>

<section class="card" style="margin-top:18px">
    <h2>Revisão</h2>
    <pre style="white-space:pre-wrap;color:var(--muted)">{{ json_encode($review, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
    <form method="post" action="{{ route('panel.client.data.export', $investorId) }}">
        @csrf<button class="btn primary" type="submit">Exportar dados</button>
    </form>
    <form method="post" action="{{ route('panel.client.data.delete', $investorId) }}" style="margin-top:12px">
        @csrf
        <label>Motivo da solicitação<input name="reason" required></label>
        <button class="btn danger" type="submit">Solicitar remoção</button>
    </form>
</section>
@endsection
