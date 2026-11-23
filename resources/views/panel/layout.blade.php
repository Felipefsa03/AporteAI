<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Painel do proprietário') · AporteAI</title>
    <style>
        :root { --bg:#0b1220; --card:#121d33; --card2:#182942; --ink:#e8eef8; --muted:#9fb0c9; --accent:#5eead4; --danger:#fca5a5; --warn:#fcd34d; --line:#243a5a; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; }
        a { color:var(--accent); }
        .wrap { max-width:1120px; margin:0 auto; padding:24px 18px 64px; }
        header.top { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; border-bottom:1px solid var(--line); padding-bottom:16px; margin-bottom:24px; }
        .brand { font-weight:700; letter-spacing:.02em; font-size:18px; }
        .brand span { color:var(--accent); }
        nav { display:flex; flex-wrap:wrap; gap:8px; }
        nav a, .btn { display:inline-block; padding:8px 12px; border-radius:8px; border:1px solid var(--line); background:var(--card); color:var(--ink); text-decoration:none; font:inherit; cursor:pointer; }
        nav a:hover, .btn:hover { border-color:var(--accent); }
        .btn.primary { background:var(--accent); color:#06231f; border-color:var(--accent); font-weight:650; }
        .btn.danger { border-color:var(--danger); color:var(--danger); }
        .grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; }
        .card h2, .card h3 { margin:0 0 12px; font-size:16px; }
        .metric { font-size:28px; font-weight:700; color:var(--accent); }
        .muted { color:var(--muted); }
        .tag { display:inline-block; padding:2px 8px; border:1px solid var(--line); border-radius:999px; font-size:12px; margin:2px; }
        .tag.ok { border-color:var(--accent); color:var(--accent); }
        .tag.warn { border-color:var(--warn); color:var(--warn); }
        .tag.bad { border-color:var(--danger); color:var(--danger); }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { text-align:left; padding:9px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-weight:600; }
        .notice, .error { padding:12px 14px; border-radius:8px; margin-bottom:16px; }
        .notice { background:#10332e; border:1px solid var(--accent); }
        .error { background:#3a1d24; border:1px solid var(--danger); color:var(--danger); }
        form { display:grid; gap:10px; }
        form.inline { display:flex; flex-wrap:wrap; align-items:end; gap:8px; }
        label { display:grid; gap:4px; font-size:13px; color:var(--muted); }
        input, select, textarea { width:100%; padding:8px 10px; border-radius:8px; border:1px solid var(--line); background:var(--card2); color:var(--ink); font:inherit; }
        textarea { min-height:72px; }
        .row { display:flex; flex-wrap:wrap; gap:16px; align-items:center; justify-content:space-between; }
        .stack { display:grid; gap:14px; }
        code { font-size:12px; word-break:break-all; color:var(--muted); }
        @media (max-width:640px) {
            .wrap { padding:16px 12px 48px; }
            header.top { align-items:flex-start; }
            nav { width:100%; }
            nav a { flex:1 1 auto; text-align:center; }
            table, thead, tbody, tr, th, td { display:block; width:100%; }
            thead { display:none; }
            tr { border:1px solid var(--line); border-radius:10px; padding:8px; margin-bottom:10px; }
            td { border:0; padding:4px 0; }
            td::before { content:attr(data-label); display:block; color:var(--muted); font-size:11px; text-transform:uppercase; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <div class="brand">Aporte<span>AI</span> <span class="muted" style="font-weight:400">· proprietário</span></div>
        @if (session('panel_token'))
        <nav aria-label="Navegação do painel">
            <a href="{{ route('panel.dashboard') }}">Visão geral</a>
            <a href="{{ route('panel.clients') }}">Clientes</a>
            <a href="{{ route('panel.reviews') }}">Revisões</a>
            <a href="{{ route('panel.billing') }}">Cobrança</a>
            <a href="{{ route('panel.metrics') }}">Métricas</a>
            <form method="post" action="{{ route('panel.logout') }}" style="display:inline">@csrf<button class="btn" type="submit">Sair</button></form>
        </nav>
        @endif
    </header>

    @if (session('panel_notice'))<div class="notice" role="status">{{ session('panel_notice') }}</div>@endif
    @if (session('panel_error'))<div class="error" role="alert">{{ session('panel_error') }}</div>@endif
    @if ($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif

    @yield('content')

    <p class="muted" style="margin-top:32px;font-size:12px">Ambiente de piloto fictício · sem recomendações reais · gates humanos em aberto.</p>
</div>
</body>
</html>
