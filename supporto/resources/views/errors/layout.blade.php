<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title') · Associazione Semplice</title>
    @include('partials.brand')
</head>
<body>
<header class="site">
    <div class="bar">
        <a class="logo" href="{{ config('support.sso.portal') }}/" aria-label="Associazione Semplice, vai alla home">@include("partials.logo", ["h" => 38])</a>
        <span class="place">Supporto</span>
        <a class="account" href="{{ config('support.sso.portal') }}/account">Il mio account</a>
    </div>
</header>
<main>
    <div class="card errorpage">
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <p class="actions">
            <a class="btn" href="{{ config('support.sso.portal') }}/account">Torna al tuo account</a>
            <a class="btn secondary" href="{{ config('support.sso.portal') }}/">Vai alla home</a>
        </p>
    </div>
</main>
</body>
</html>
