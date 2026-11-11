<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Invito · Associazione Semplice</title>
    <style>
        :root { --bg: #f5f6fa; --fg: #1b1c33; --muted: #5b5d7a; --card: #fff; --line: #dcdeea; --brand: #3b3fa8; }
        @media (prefers-color-scheme: dark) { :root { --bg: #11122a; --fg: #eceefb; --muted: #a3a6c8; --card: #1b1c3a; --line: #2e3060; --brand: #8f93f0; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, sans-serif; }
        main { max-width: 480px; margin: 0 auto; padding: 24px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input { width: 100%; padding: 10px 12px; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
        button { margin-top: 16px; padding: 10px 18px; font: inherit; font-weight: 600; color: #fff; background: var(--brand); border: 0; border-radius: 8px; cursor: pointer; }
        .error { color: #b3261e; margin: 6px 0 0; }
        .muted { color: var(--muted); }
    </style>
</head>
<body>
<main>
    @if ($done)
        <div class="card">
            <h1>Password impostata</h1>
            <p>Ora puoi accedere alle aree dello staff con la tua email e la password che hai scelto.</p>
        </div>
    @elseif ($invite === null)
        <div class="card">
            <h1>Invito non valido</h1>
            <p class="muted">Questo invito è già stato usato o è scaduto (dura 7 giorni). Chiedi di riceverne uno nuovo.</p>
        </div>
    @else
        <form class="card" method="post" action="{{ url('/saas/staff/invito/'.$code) }}" autocomplete="off">
            <h1>Scegli la tua password</h1>
            <p class="muted">Account: {{ $invite->email }}</p>
            <label for="password">Password (almeno 12 caratteri)</label>
            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" autofocus>
            <label for="password_confirmation">Ripeti la password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
            @foreach ($errors as $error)
                <p class="error" role="alert">{{ $error }}</p>
            @endforeach
            <button type="submit">Imposta la password</button>
        </form>
    @endif
</main>
</body>
</html>
