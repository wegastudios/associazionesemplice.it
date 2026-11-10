<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Amministrazione') · Associazione Semplice</title>
    <style>
        :root { --bg: #f5f6fa; --fg: #1b1c33; --muted: #5b5d7a; --card: #fff; --line: #dcdeea; --brand: #3b3fa8; }
        @media (prefers-color-scheme: dark) { :root { --bg: #11122a; --fg: #eceefb; --muted: #a3a6c8; --card: #1b1c3a; --line: #2e3060; --brand: #8f93f0; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, sans-serif; }
        header { display: flex; flex-wrap: wrap; gap: 12px 20px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--card); }
        header a, header button { color: var(--fg); text-decoration: none; background: none; border: 0; font: inherit; cursor: pointer; padding: 4px 0; }
        header .grow { flex: 1; font-weight: 600; }
        main { max-width: 960px; margin: 0 auto; padding: 24px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input { width: 100%; padding: 10px 12px; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
        button.primary { margin-top: 16px; padding: 10px 18px; font: inherit; font-weight: 600; color: #fff; background: var(--brand); border: 0; border-radius: 8px; cursor: pointer; }
        .error { color: #b3261e; margin: 6px 0 0; }
        .muted { color: var(--muted); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .scroll { overflow-x: auto; }
        .qr { background: #fff; display: inline-block; padding: 8px; border-radius: 8px; }
        code { word-break: break-all; }
    </style>
</head>
<body>
@auth('platform')
    <header>
        <span class="grow">Associazione Semplice · Amministrazione</span>
        <a href="{{ route('platform.home') }}">Riepilogo</a>
        <a href="{{ route('platform.orgs') }}">Associazioni</a>
        <a href="{{ route('platform.catalog') }}">Catalogo</a>
        <a href="{{ route('platform.audit') }}">Registro</a>
        <form method="post" action="{{ route('platform.logout') }}">@csrf<button type="submit">Esci</button></form>
    </header>
@endauth
<main>@yield('content')</main>
</body>
</html>
