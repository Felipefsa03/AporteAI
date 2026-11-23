@extends('panel.layout')

@section('title', 'Verificação por SMS')

@section('content')
<div class="auth-shell">
    <section class="card auth-card">
        <img class="auth-logo" src="{{ asset('images/aporteai-logo.png') }}" alt="Logo AporteAI" width="72" height="72">
        <h2>Confirme o código enviado por SMS</h2>
        <p class="muted">
            Digite os 6 dígitos enviados para {{ $maskedPhone !== '' ? $maskedPhone : 'o número cadastrado' }}.
            @if ($expiresAt !== '')
                Valido até {{ $expiresAt }}.
            @endif
        </p>

        <form method="post" action="{{ route('panel.sms.verify') }}">
            @csrf
            <label>Código de 6 dígitos
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                       autocomplete="one-time-code" required autofocus>
            </label>
            <button class="btn primary" type="submit">Entrar no painel</button>
        </form>

        <a class="btn" href="{{ route('panel.login') }}">Voltar</a>
    </section>
</div>
@endsection
