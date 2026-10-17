<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#21248b">
    <title>@yield('title', 'Assistenza') · {{ config('app.name') }}</title>
    @include('partials.brand')
</head>
<body>
<header class="site">
    <div class="bar">
        <a class="logo" href="{{ route('help.index') }}" aria-label="{{ config('app.name') }}, vai al centro assistenza"><img src="{{ route('brand', 'logo.png') }}" alt="" width="38" height="38"><span class="wordmark">Associazione Semplice</span></a>
        <nav aria-label="Navigazione principale">
            <a href="{{ route('help.index') }}" @if (request()->routeIs('help.*')) aria-current="page" @endif>Centro assistenza</a>
            <a href="{{ route('ticket.create') }}" @if (request()->routeIs('ticket.create')) aria-current="page" @endif>Apri una richiesta</a>
            @if (session('customer'))
                <a href="{{ route('ticket.mine') }}" @if (request()->routeIs('ticket.mine')) aria-current="page" @endif>I miei ticket</a>
                <form method="post" action="{{ route('sso.logout') }}" style="margin-left:auto">@csrf<button type="submit" class="secondary">Esci</button></form>
            @else
                <a href="{{ rtrim(config('support.sso.portal'), '/') }}/supporto/accedi" style="margin-left:auto">Accedi</a>
            @endif
        </nav>
    </div>
</header>
<main>
    @if (session('ok'))<div class="flash" role="status">{{ session('ok') }}</div>@endif
    @if ($errors->any())
        <div class="errors" role="alert"><ul style="margin:0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            @if (session('new_ticket_url'))<p style="margin-bottom:0"><a href="{{ session('new_ticket_url') }}">Apri una nuova richiesta collegata a questa</a></p>@endif
        </div>
    @endif
    @yield('content')
</main>
<footer class="site">{{ config('app.name') }}</footer>
</body>
</html>
