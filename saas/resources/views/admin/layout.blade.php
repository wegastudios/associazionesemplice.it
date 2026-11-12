<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @stack('head')
    <title>@yield('title', 'Amministrazione') · Associazione Semplice</title>
    <style>
        @font-face { font-family: 'Montserrat'; font-weight: 500; font-display: swap; src: url('{{ asset('assets/fonts/Montserrat-Medium.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Montserrat'; font-weight: 600; font-display: swap; src: url('{{ asset('assets/fonts/Montserrat-SemiBold.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Montserrat'; font-weight: 700; font-display: swap; src: url('{{ asset('assets/fonts/Montserrat-Bold.ttf') }}') format('truetype'); }
        /* Colori e misure: docs/brand/tokens.css (unica fonte, docs/GRAFICA_UX.md). */
        :root { --bg: #f5f6fa; --fg: #181a2e; --muted: #5d6078; --card: #fff; --line: #e2e5ee; --brand: #1f218c; --brand-ink: #fff; --danger: #b42318; }
        @media (prefers-color-scheme: dark) { :root { --bg: #11122a; --fg: #eceefa; --muted: #a9acc6; --card: #1b1d3d; --line: #2f3260; --brand: #a6a9ff; --brand-ink: #11122a; --danger: #ff8a80; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.5 'Montserrat', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        a { color: var(--brand); }
        .common-bar { display: flex; align-items: center; gap: 12px; height: 56px; padding: 0 16px; border-bottom: 1px solid var(--line); background: var(--card); }
        .common-bar img { height: 32px; width: auto; display: block; }
        .common-bar .place { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-left: 12px; border-left: 1px solid var(--line); font-weight: 700; }
        .common-bar a.account { display: inline-flex; align-items: center; min-height: 44px; padding: 0 8px; font-weight: 600; text-decoration: none; }
        .common-bar .logo-wide { display: none; }
        @media (min-width: 640px) { .common-bar .logo-wide { display: block; } .common-bar .logo-mark { display: none; } }
        nav.menu { display: flex; flex-wrap: wrap; gap: 4px 20px; align-items: center; padding: 8px 16px; border-bottom: 1px solid var(--line); background: var(--card); }
        nav.menu a, nav.menu button { color: var(--fg); text-decoration: none; background: none; border: 0; font: inherit; font-weight: 600; cursor: pointer; padding: 10px 0; min-height: 44px; }
        nav.menu .grow { flex: 1; }
        main { max-width: 960px; margin: 0 auto; padding: 24px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 20px; margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input[type=checkbox] { width: auto; }
        input, select, textarea { width: 100%; min-height: 48px; padding: 10px 12px; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 12px; }
        button.primary { margin-top: 16px; min-height: 44px; padding: 10px 18px; font: inherit; font-weight: 600; color: var(--brand-ink); background: var(--brand); border: 0; border-radius: 12px; cursor: pointer; }
        .error { color: var(--danger); margin: 6px 0 0; }
        .muted { color: var(--muted); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .scroll { overflow-x: auto; }
        .qr { background: #fff; display: inline-block; padding: 8px; border-radius: 12px; }
        code { word-break: break-all; }
        select, textarea { padding: 10px 12px; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
        fieldset { border: 1px solid var(--line); border-radius: 8px; margin: 12px 0; padding: 8px 14px 14px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0 12px; }
        .inline { display: flex; align-items: center; gap: 8px; font-weight: 600; margin: 12px 0 4px; }
        .tag { display: inline-block; font-size: 12px; border: 1px solid var(--line); border-radius: 99px; padding: 0 8px; color: var(--muted); }
        .ok { color: #1b7a3a; }
    </style>
</head>
<body>
{{-- Barra comune a portale, supporto e gestionale (docs/GRAFICA_UX.md §2): logo → portale, nome del posto, Il mio account. --}}
<div class="common-bar">
    <a href="https://associazionesemplice.it/" aria-label="Associazione Semplice, vai alla home">
        <picture class="logo-wide">
            <source srcset="{{ asset('assets/brand/logo-orizzontale-negativo.svg') }}" media="(prefers-color-scheme: dark)">
            <img src="{{ asset('assets/brand/logo-orizzontale-colore.svg') }}" alt="">
        </picture>
        <img class="logo-mark" src="{{ asset('assets/brand/simbolo-colore.svg') }}" alt="">
    </a>
    <span class="place">Gestionale</span>
    <a class="account" href="https://associazionesemplice.it/account">Il mio account</a>
</div>
@auth('platform')
    <nav class="menu" aria-label="Sezioni">
        <span class="grow"></span>
        <a href="{{ route('platform.home') }}">Riepilogo</a>
        <a href="{{ route('platform.subscriptions') }}">Abbonamenti</a>
        <a href="{{ route('platform.plans') }}">Piani</a>
        <a href="{{ route('platform.addons') }}">Pacchetti</a>
        <a href="{{ route('platform.promos') }}">Promozioni</a>
        <a href="{{ route('platform.catalog') }}">Catalogo</a>
        <a href="{{ route('platform.audit') }}">Registro</a>
        @if (config('saas.unified_login.enabled') === true)
            {{-- Esci è uno solo, del portale (GET: «Vuoi uscire?»): chiude la sessione unica e quindi anche questa. --}}
            <a href="https://associazionesemplice.it/account/esci">Esci</a>
        @else
            <form method="post" action="{{ route('platform.logout') }}">@csrf<button type="submit">Esci</button></form>
        @endif
    </nav>
@endauth
<main>@yield('content')</main>
</body>
</html>
