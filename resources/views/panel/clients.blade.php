@extends('panel.layout')

@section('title', 'Clientes')

@section('content')
<h1>Clientes fictícios</h1>
<p class="muted">Somente o proprietário cadastra e remove clientes. Não existe cadastro público.</p>

<div class="grid" style="margin-top:18px">
    <section class="card" style="grid-column:1/-1">
        <h2>Cadastrar cliente</h2>
        <form method="post" action="{{ route('panel.clients.store') }}">
            @csrf
            <div class="grid">
                <label>Nome completo<input name="full_name" required></label>
                <label>Telefone E.164<input name="phone_e164" placeholder="+55759926990001" required></label>
                <label>E-mail<input name="email" type="email" required></label>
                <label>Profissão<input name="profession"></label>
                <label>Renda mensal (R$)<input name="income_monthly_brl" inputmode="decimal"></label>
                <label>Origem do dado<input name="source_note" placeholder="gerado para piloto fictício"></label>
            </div>
            <button class="btn primary" type="submit">Cadastrar</button>
        </form>
    </section>

    <section class="card" style="grid-column:1/-1">
        <h2>Lista</h2>
        <table>
            <thead><tr><th>Nome</th><th>Telefone</th><th>Ciclo de vida</th><th></th></tr></thead>
            <tbody>
            @forelse ($clients as $client)
                <tr>
                    <td data-label="Nome">{{ $client['full_name'] ?? '' }}</td>
                    <td data-label="Telefone">{{ $client['phone_e164'] ?? '' }}</td>
                    <td data-label="Ciclo de vida"><span class="tag">{{ $client['lifecycle_status'] ?? '—' }}</span></td>
                    <td data-label="Ações"><a href="{{ route('panel.client', $client['id']) }}">Abrir</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Nenhum cliente cadastrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
