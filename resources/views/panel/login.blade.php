@extends('panel.layout')

@section('title', 'Entrar no painel')

@section('content')
<div class="grid">
    <section class="card" style="max-width:520px;margin:0 auto">
        <h2>Acesso exclusivo do proprietário</h2>
        <p class="muted">Conta única, com MFA. Clientes não possuem credencial de painel nem cadastro público.</p>
        <form method="post" action="{{ route('panel.login.submit') }}">
            @csrf
            <label>E-mail
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            </label>
            <label>Senha
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <label>Código MFA (6 dígitos)
                <input type="text" name="mfa_code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="Somente se o MFA estiver ativo">
            </label>
            <button class="btn primary" type="submit">Entrar</button>
        </form>
    </section>
</div>
@endsection
