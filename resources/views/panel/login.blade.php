@extends('panel.layout')

@section('title', 'Entrar no painel')

@section('content')
<div class="auth-shell">
    <section class="card auth-card">
        <img class="auth-logo" src="{{ asset('images/aporteai-logo.png') }}" alt="Logo AporteAI" width="96" height="96">
        <h2>Acesso exclusivo do proprietário</h2>
        <p class="muted">Conta única e humana. Clientes não possuem credencial de painel nem cadastro público.</p>

        <div class="auth-divider"><span>Entrar com Google</span></div>

        @if ($googleConfigured)
            <div id="g_id_onload"
                 data-client_id="{{ $googleClientId }}"
                 data-callback="aporteaiGoogleCredential"
                 data-auto_prompt="false"></div>
            <div class="g_id_signin" data-type="standard" data-size="large" data-theme="outline"
                 data-text="sign_in_with" data-shape="rectangular" data-logo_alignment="left"></div>
            <form id="google-form" method="post" action="{{ route('panel.google') }}">
                @csrf
                <input type="hidden" name="credential" id="google-credential" value="">
            </form>
            <script src="https://accounts.google.com/gsi/client" async defer></script>
            <script>
                function aporteaiGoogleCredential(response) {
                    document.getElementById('google-credential').value = response.credential || '';
                    document.getElementById('google-form').submit();
                }
            </script>
        @else
            <button class="btn" type="button" disabled title="Configure GOOGLE_CLIENT_ID para habilitar">Entrar com Google</button>
            <p class="muted" style="font-size:12px">Login Google preparado; aguardando configuração do cliente OAuth no ambiente.</p>
        @endif

        <div class="auth-divider"><span>ou use e-mail e senha</span></div>

        <form method="post" action="{{ route('panel.login.submit') }}">
            @csrf
            <label>E-mail
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            </label>
            <label>Senha
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn primary" type="submit">Continuar</button>
        </form>

        <p class="muted" style="font-size:12px;margin-bottom:0">
            Após a senha, um código de 6 dígitos é enviado por SMS ao número cadastrado do proprietário.
        </p>
    </section>
</div>
@endsection
