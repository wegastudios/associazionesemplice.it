<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · Associazione Semplice</title>
    <style>
        /* Colori: docs/brand/tokens.css. Senza risorse esterne: la pagina d'errore deve reggere anche quando il resto no. */
        :root { --bg: #f5f6fa; --fg: #181a2e; --muted: #5d6078; --card: #fff; --line: #e2e5ee; --brand: #1f218c; --brand-ink: #fff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #11122a; --fg: #eceefa; --muted: #a9acc6; --card: #1b1d3d; --line: #2f3260; --brand: #a6a9ff; --brand-ink: #11122a; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg); font: 16px/1.5 'Montserrat', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .bar { display: flex; align-items: center; height: 56px; padding: 0 16px; border-bottom: 1px solid var(--line); background: var(--card); font-weight: 700; }
        .bar a { color: var(--fg); text-decoration: none; }
        main { max-width: 36rem; margin: 0 auto; padding: 48px 16px; }
        h1 { font-size: 1.5rem; line-height: 1.3; margin: 0 0 8px; }
        p { margin: 0 0 24px; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .btn { display: inline-flex; align-items: center; min-height: 48px; padding: 0 20px; border-radius: 12px; font-weight: 600; text-decoration: none; border: 1px solid var(--brand); color: var(--brand); }
        .btn.primary { background: var(--brand); color: var(--brand-ink); }
    </style>
</head>
<body>
<div class="bar"><a href="https://associazionesemplice.it/">Associazione Semplice</a></div>
<main>
    <h1>@yield('heading')</h1>
    <p>@yield('message')</p>
    <div class="actions">
        <a class="btn primary" href="https://associazionesemplice.it/account">Torna al tuo account</a>
        <a class="btn" href="https://associazionesemplice.it/">Vai alla home</a>
    </div>
</main>
</body>
</html>
