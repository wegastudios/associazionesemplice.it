<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f5f6fa" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#11122a" media="(prefers-color-scheme: dark)">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/assets/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/brand/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
    <title>Associazione Semplice</title>
    {{-- Il font del testo e il componente della pagina partono subito, in parallelo a app.js, invece che dopo. --}}
    <link rel="preload" href="{{ Vite::asset('resources/fonts/montserrat-500-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js', "resources/js/pages/{$page['component']}.vue"])
    @inertiaHead
    @if (\App\Modules\Core\Contracts\DemoScale::restricted())
        {{-- Demo: l'avviso è alto --demo-banner-h e sta in cima; ciò che nell'app è fisso o resta in cima scende di quell'altezza, così nulla è coperto. --}}
        <style>
            :root { --demo-banner-h: 2rem; }
            .demo-banner { position: sticky; top: 0; z-index: 35; box-sizing: border-box; height: var(--demo-banner-h); display: flex; align-items: center; justify-content: center; padding: 0 12px; background: #fff4c2; color: #4a3b00; font: 600 clamp(11px, 3.4vw, 14px)/1 system-ui, sans-serif; white-space: nowrap; overflow: hidden; }
            .fixed.top-0 { top: var(--demo-banner-h); }
            .fixed.top-14 { top: calc(3.5rem + var(--demo-banner-h)); }
            header.sticky.top-0 { top: var(--demo-banner-h); }
            header.sticky.top-14, nav.sticky.top-14 { top: calc(3.5rem + var(--demo-banner-h)); }
            @media (min-width: 1024px) { nav.sticky.lg\:top-0 { top: var(--demo-banner-h); } }
            @media print { .demo-banner { display: none; } }
        </style>
    @endif
</head>
<body class="min-h-full bg-surface text-ink antialiased">
    {{-- Demo: avviso in cima a ogni pagina (gestionale, accesso, moduli pubblici e area soci). Sta nel flusso (sticky) e alto --demo-banner-h:
         gli elementi fissi dell'app (intestazione, menu laterale, intestazioni che restano in cima) si spostano di quell'altezza (stile nel head). --}}
    @if (\App\Modules\Core\Contracts\DemoScale::restricted())
        <div class="demo-banner" role="status">Dati finti: la demo si azzera ogni notte alle 03:30.</div>
    @endif
    @inertia
</body>
</html>
