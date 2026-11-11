<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Accesso · Associazione Semplice</title>
    <style>
        :root { --bg: #f5f6fa; --fg: #1b1c33; --muted: #5b5d7a; --card: #fff; --line: #dcdeea; --brand: #3b3fa8; }
        @media (prefers-color-scheme: dark) { :root { --bg: #11122a; --fg: #eceefb; --muted: #a3a6c8; --card: #1b1c3a; --line: #2e3060; --brand: #8f93f0; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, sans-serif; }
        main { max-width: 480px; margin: 0 auto; padding: 24px 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        h1 { font-size: 1.4rem; margin: 0 0 8px; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input { width: 100%; padding: 10px 12px; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
        button { margin-top: 16px; padding: 10px 18px; font: inherit; font-weight: 600; color: #fff; background: var(--brand); border: 0; border-radius: 8px; cursor: pointer; }
        .link { display: inline-block; margin-top: 14px; color: var(--brand); }
        .error { color: #b3261e; margin: 6px 0 0; }
        .muted { color: var(--muted); }
        ul { list-style: none; padding: 0; margin: 12px 0 0; }
        li form { margin: 0; }
        li button { width: 100%; text-align: left; background: var(--bg); color: var(--fg); border: 1px solid var(--line); margin-top: 8px; }
    </style>
</head>
<body>
<main>
    @switch ($mode)
        @case('login')
            <form class="card" method="post" action="{{ url('/saas/staff/accedi') }}">
                @csrf
                <h1>Accedi</h1>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" required maxlength="254" autocomplete="username" value="{{ old('email') }}" autofocus>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required maxlength="1024" autocomplete="current-password">
                @foreach ($errors->all() as $error)
                    <p class="error" role="alert">{{ $error }}</p>
                @endforeach
                <button type="submit">Entra</button>
                <br><a class="link" href="{{ url('/saas/staff/password-dimenticata') }}">Password dimenticata?</a>
            </form>
            @break

        @case('code')
            <form class="card" method="post" action="{{ url('/saas/staff/codice') }}">
                @csrf
                <h1>Codice di verifica</h1>
                <p class="muted">Scrivi il codice a sei cifre della tua app di autenticazione.</p>
                <label for="code">Codice</label>
                <input id="code" name="code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" autofocus>
                @foreach ($errors->all() as $error)
                    <p class="error" role="alert">{{ $error }}</p>
                @endforeach
                <button type="submit">Verifica</button>
            </form>
            @break

        @case('areas')
            <div class="card">
                <h1>Ciao {{ $name }}</h1>
                <p class="muted">Dove vuoi andare?</p>
                @if ($areas === [])
                    <p>Per ora non hai un'area a cui entrare da qui. Chiedi di essere abilitato.</p>
                @else
                    <ul>
                        @foreach ($areas as $area => $level)
                            <li>
                                <form method="post" action="{{ url('/saas/staff/vai') }}">
                                    @csrf
                                    <input type="hidden" name="area" value="{{ $area }}">
                                    <button type="submit">{{ $names[$area] ?? $area }} <span class="muted">· {{ $level }}</span></button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="post" action="{{ url('/saas/staff/esci') }}">
                    @csrf
                    <button type="submit">Esci</button>
                </form>
            </div>
            @break

        @case('go')
            <form class="card" method="post" action="{{ $dest }}">
                <h1>{{ $area }}</h1>
                <p class="muted">Il tuo accesso è pronto.</p>
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" autofocus>Entra</button>
            </form>
            @break

        @case('forgot')
            <form class="card" method="post" action="{{ url('/saas/staff/password-dimenticata') }}">
                @csrf
                <h1>Password dimenticata</h1>
                <p class="muted">Scrivi la tua email: se è registrata ti mandiamo un link per scegliere una nuova password.</p>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" required maxlength="254" autocomplete="username" autofocus>
                @foreach ($errors->all() as $error)
                    <p class="error" role="alert">{{ $error }}</p>
                @endforeach
                <button type="submit">Manda il link</button>
            </form>
            @break

        @case('forgot_sent')
            <div class="card">
                <h1>Controlla la posta</h1>
                <p>Se l'email è registrata, in pochi minuti arriva un link per scegliere una nuova password (vale un'ora).</p>
                <a class="link" href="{{ url('/saas/staff/accedi') }}">Torna all'accesso</a>
            </div>
            @break

        @case('reset')
            <form class="card" method="post" action="{{ url('/saas/staff/reimposta/'.$code) }}" autocomplete="off">
                @csrf
                <h1>Scegli la nuova password</h1>
                <label for="password">Password (almeno 12 caratteri)</label>
                <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" autofocus>
                <label for="password_confirmation">Ripeti la password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
                @foreach (($problems ?? []) as $problem)
                    <p class="error" role="alert">{{ $problem }}</p>
                @endforeach
                <button type="submit">Salva la password</button>
            </form>
            @break

        @case('reset_done')
            <div class="card">
                <h1>Password cambiata</h1>
                <p>Gli accessi aperti sono stati chiusi. Ora puoi entrare con la nuova password.</p>
                <a class="link" href="{{ url('/saas/staff/accedi') }}">Vai all'accesso</a>
            </div>
            @break

        @default
            <div class="card">
                <h1>Link non valido</h1>
                <p class="muted">Il link è già stato usato o è scaduto.</p>
                <a class="link" href="{{ url('/saas/staff/password-dimenticata') }}">Chiedine uno nuovo</a>
            </div>
    @endswitch
</main>
</body>
</html>
