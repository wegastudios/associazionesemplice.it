<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#1f218c">
    <meta name="description" content="@yield('description', 'Guide e assistenza per Associazione Semplice: installazione, licenza, soci e tessere, corsi ed eventi, pagamenti.')">
    <title>@yield('title', 'Centro assistenza') · {{ config('app.name') }}</title>
    @include('partials.brand')
</head>
<body>
<header class="site">
    <div class="bar">
        <a class="logo" href="{{ config('support.sso.portal') }}/" aria-label="Associazione Semplice, vai alla home">@include("partials.logo", ["h" => 38])</a>
        <span class="place">Supporto</span>
        @if (session('customer'))
            <a class="account" href="{{ config('support.sso.portal') }}/account">Il mio account</a>
        @else
            <a class="account" href="{{ config('support.sso.portal') }}/accedi">Accedi</a>
        @endif
    </div>
</header>
<nav class="menu" aria-label="Menu del supporto">
    <a href="{{ route('help.index') }}" @if (request()->routeIs('help.*')) aria-current="page" @endif>Centro assistenza</a>
    <a href="{{ route('ticket.create') }}" @if (request()->routeIs('ticket.create')) aria-current="page" @endif>Apri una richiesta</a>
    @if (session('customer'))<a href="{{ route('ticket.mine') }}" @if (request()->routeIs('ticket.mine')) aria-current="page" @endif>Le mie richieste</a>@endif
</nav>
<main>
    @if (session('ok'))<div class="flash" role="status">{{ session('ok') }}</div>@endif
    @if ($errors->any())
        <div class="errors" role="alert"><ul style="margin:0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            @if (session('new_ticket_url'))<p style="margin-bottom:0"><a href="{{ session('new_ticket_url') }}">Apri una nuova richiesta collegata a questa</a></p>@endif
        </div>
    @endif
    @yield('content')
</main>
<footer class="site">{{ config('app.name') }} · <a href="{{ config('support.sso.portal') }}/privacy">Privacy</a> · <a href="{{ config('support.sso.portal') }}/cookie">Cookie</a> · <a href="{{ config('support.sso.portal') }}/account">Il mio account</a></footer>
</body>
</html>
