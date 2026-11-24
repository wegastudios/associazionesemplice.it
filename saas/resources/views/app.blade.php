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
</head>
<body class="min-h-full bg-surface text-ink antialiased">
    {{-- Demo: avviso fisso in cima a ogni pagina (gestionale, accesso, moduli pubblici e area soci). --}}
    @if (\App\Modules\Core\Contracts\DemoScale::restricted())
        <div role="status" style="position:sticky;top:0;z-index:60;background:#fff4c2;color:#4a3b00;padding:6px 12px;text-align:center;font:600 14px/1.4 system-ui,sans-serif">
            Dati finti: la demo si azzera ogni notte alle 03:30.
        </div>
    @endif
    @inertia
</body>
</html>
