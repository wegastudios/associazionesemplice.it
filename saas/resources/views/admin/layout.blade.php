@php
    /*
     * Stessa grafica e stessa organizzazione di /wordpress/admin (portale/views/wordpress-admin/_nav.php): intestazione del
     * sito, «Gestione» con scorciatoie, schede su due livelli. Il CSS è una copia di portale/public/assets/portale.css
     * (docs/GRAFICA_UX.md): cambiano le voci, non l'aspetto.
     */
    $portal = rtrim((string) config('saas.portal.url'), '/');
    $route = (string) optional(request()->route())->getName();
    $sections = [
        'cruscotto' => ['Cruscotto', ['cruscotto' => [route('platform.home'), 'Riepilogo']]],
        'piani' => ['Piani', [
            'piani' => [route('platform.plans'), 'Piani e prezzi'],
            'pacchetti' => [route('platform.addons'), 'Pacchetti'],
            'promozioni' => [route('platform.promos'), 'Promozioni'],
            'condizioni' => [route('platform.terms'), 'Condizioni'],
            'catalogo' => [route('platform.catalog'), 'Catalogo'],
        ]],
        'clienti' => ['Clienti', ['abbonamenti' => [route('platform.subscriptions'), 'Abbonamenti']]],
        'impostazioni' => ['Impostazioni', [
            'generali' => [route('platform.settings'), 'Generali'],
            'registro' => [route('platform.audit'), 'Registro'],
        ]],
    ];
    $nav = match (true) {
        str_starts_with($route, 'platform.plans') => 'piani',
        str_starts_with($route, 'platform.addons') => 'pacchetti',
        str_starts_with($route, 'platform.promos') => 'promozioni',
        str_starts_with($route, 'platform.terms') => 'condizioni',
        str_starts_with($route, 'platform.catalog') => 'catalogo',
        $route === 'platform.subscriptions' => 'abbonamenti',
        str_starts_with($route, 'platform.settings') => 'generali',
        $route === 'platform.audit' => 'registro',
        default => 'cruscotto',
    };
    $current = 'cruscotto';
    foreach ($sections as $key => $section) {
        if (isset($section[1][$nav])) {
            $current = $key;
        }
    }
    $staff = auth('platform')->user();
    $unified = config('saas.unified_login.enabled') === true;
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#1f218c">
    @stack('head')
    <title>@yield('title', 'Gestione') · Gestisci SaaS · Associazione Semplice</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/brand/favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/portale.css') }}">
    <style>
        /* Le pagine di questa amministrazione usano ancora i nomi di classe del SaaS: stesso aspetto dei riquadri e delle tabelle del portale. */
        .admin h1 { font-size: 1.7rem; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 22px; margin-bottom: 20px; }
        .card h2, .card h3 { font-size: 1.15rem; margin-bottom: 14px; }
        button.primary { display: inline-flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; min-height: 44px; padding: 12px 22px; border-radius: 12px; border: 1px solid transparent; background: var(--brand); color: var(--brand-fg); font: inherit; font-weight: 700; cursor: pointer; }
        button.primary:hover { background: var(--brand-2); }
        .error { color: var(--danger); margin: 6px 0 0; } .ok { color: var(--ok); }
        .scroll { overflow-x: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; }
        .scroll table, .card table { width: 100%; border-collapse: collapse; font-size: .94rem; }
        .scroll th, .card th { text-align: left; font-size: .76rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); padding: 12px 16px; border-bottom: 1px solid var(--line); white-space: nowrap; }
        .scroll td, .card td { padding: 13px 16px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .scroll tr:last-child td, .card tr:last-child td { border-bottom: 0; }
        input:not([type=checkbox]):not([type=radio]), select, textarea { width: 100%; min-height: 48px; padding: 12px 14px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--bg); color: var(--fg); font: inherit; }
        input:focus, select:focus, textarea:focus { border-color: var(--brand); outline: none; box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 20%, transparent); }
        input[type=checkbox], input[type=radio] { width: 18px; height: 18px; accent-color: var(--brand); }
        fieldset { border: 1px solid var(--line); border-radius: 12px; margin: 12px 0; padding: 8px 14px 14px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0 12px; }
        .inline { display: flex; align-items: center; gap: 8px; font-weight: 700; margin: 12px 0 4px; }
        .tag { display: inline-block; font-size: 12px; border: 1px solid var(--line); border-radius: 99px; padding: 0 8px; color: var(--muted); }
        .qr { background: #fff; display: inline-block; padding: 8px; border-radius: 12px; }
        code { word-break: break-all; }
        nav.tabs form { display: inline; }
    </style>
</head>
<body>
<a class="skip" href="#main">Vai al contenuto</a>
<header class="site-header"><div class="wrap">
    <a class="logo" href="{{ $portal }}/" aria-label="Associazione Semplice">
        <picture class="logo-full">
            <source media="(prefers-color-scheme: dark)" srcset="{{ asset('assets/brand/logo-orizzontale-negativo.svg') }}">
            <img src="{{ asset('assets/brand/logo-orizzontale-colore.svg') }}" alt="Associazione Semplice" height="40">
        </picture>
        <picture class="logo-sym"><img src="{{ asset('assets/brand/simbolo-colore.svg') }}" alt="" height="40"></picture>
    </a>
    <nav class="nav" aria-label="Principale">
        <a href="{{ $portal }}/funzioni">Funzioni</a>
        <a href="{{ $portal }}/domande">Domande</a>
        <a href="{{ $portal }}/supporto/accedi">Supporto</a>
        @auth('platform')
            <a href="{{ $portal }}/wordpress/admin">Gestisci Plugin</a>
            <a href="{{ route('platform.home') }}" aria-current="page">Gestisci SaaS</a>
        @endauth
        <a class="nav-area" href="{{ $portal }}/account">Il mio account</a>
    </nav>
    <details class="menu">
        <summary aria-label="Menu"><span>Menu</span></summary>
        <div class="menu-panel">
            <a href="{{ $portal }}/funzioni">Funzioni</a><a href="{{ $portal }}/domande">Domande</a><a href="{{ $portal }}/supporto/accedi">Supporto</a>
            @auth('platform')
                <a href="{{ $portal }}/wordpress/admin">Gestisci Plugin</a><a href="{{ route('platform.home') }}">Gestisci SaaS</a>
            @endauth
            <a class="menu-area" href="{{ $portal }}/account">Il mio account</a>
        </div>
    </details>
</div></header>
<main id="main">
@auth('platform')
    <div class="wrap" style="padding-top:28px">
        <div class="admin-top"><h1>Gestisci SaaS</h1><span class="muted small">{{ $staff?->email }} ·
            <a href="{{ $portal }}/wordpress/admin">Gestisci Plugin</a> ·
            @if ($unified)
                {{-- Esci è uno solo, del portale (GET: «Vuoi uscire?»): chiude la sessione unica e quindi anche questa. --}}
                <a href="{{ $portal }}/account/esci">Esci</a>
            @else
                <form method="post" action="{{ route('platform.logout') }}" style="display:inline">@csrf<button type="submit" class="linklike">Esci</button></form>
            @endif
            · <a href="{{ $portal }}/account">La mia area cliente</a></span></div>
        <nav class="tabs" aria-label="Gestione">
            @foreach ($sections as $key => $section)
                <a href="{{ array_values($section[1])[0][0] }}"@if ($key === $current) aria-current="page"@endif>{{ $section[0] }}</a>
            @endforeach
        </nav>
        @if (count($sections[$current][1]) > 1)
            <nav class="tabs sub" aria-label="{{ $sections[$current][0] }}">
                @foreach ($sections[$current][1] as $key => $tab)
                    <a href="{{ $tab[0] }}"@if ($key === $nav) aria-current="page"@endif>{{ $tab[1] }}</a>
                @endforeach
            </nav>
        @endif
    </div>
@endauth
<section class="admin"><div class="wrap">@yield('content')</div></section>
</main>
<footer class="site-footer"><div class="wrap">
    <div>© {{ date('Y') }} Associazione Semplice</div>
    <div><a href="{{ $portal }}/saas">Gestionale online</a><a href="{{ $portal }}/wordpress">Plugin WordPress</a><a href="{{ $portal }}/supporto/accedi">Supporto</a></div>
</div></footer>
</body>
</html>
