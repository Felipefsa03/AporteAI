@extends('panel.layout')

@section('title', 'Fila de revisão')

@section('content')
<h1>Fila de revisão humana</h1>
<p class="muted">Perfil incompleto, vencido ou inconsistente bloqueia recomendações. A IA não libera perfis.</p>

@forelse ($reviews as $case)
<section class="card" style="margin-top:16px">
    <div class="row"><h2>{{ $case['reason'] ?? $case['id'] }}</h2><span class="tag warn">{{ $case['status'] ?? 'pendente' }}</span></div>
    <p class="muted">Investidor: {{ $case['investor_id'] ?? '—' }} · versão {{ $case['version'] ?? '—' }} · criado em {{ $case['created_at'] ?? '—' }}</p>
    @if (isset($case['payload']))
        <details><summary>Respostas e histórico</summary><pre style="white-space:pre-wrap;color:var(--muted)">{{ json_encode($case['payload'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
    @endif
    <form method="post" action="{{ route('panel.reviews.resolve', $case['id']) }}">
        @csrf
        <div class="grid">
            <label>Resultado
                <select name="outcome" required>
                    <option value="clarification_requested">Solicitar esclarecimento</option>
                    <option value="profile_confirmed">Confirmar perfil</option>
                    <option value="block_confirmed">Manter bloqueio crítico</option>
                </select>
            </label>
            <label>Motivo registrado<input name="justification" required></label>
        </div>
        <button class="btn primary" type="submit">Concluir revisão</button>
    </form>
</section>
@empty
<p class="empty" style="margin-top:18px">Nenhum caso na fila.</p>
@endforelse
@endsection
