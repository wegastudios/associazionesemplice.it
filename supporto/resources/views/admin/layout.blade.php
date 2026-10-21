<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#1f218c">
    <title>@yield('title', 'Supporto') · Associazione Semplice</title>
    @include('partials.brand')
</head>
<body>
<header class="site">
    <div class="bar">
        <a class="logo" href="{{ route('admin.home') }}" aria-label="Associazione Semplice · Supporto, vai ai ticket">@include("partials.logo", ["h" => 38])</a>
        @auth
            <nav aria-label="Navigazione principale">
                @if (config('support.role') === 'hub')
                    <a href="{{ route('admin.tickets.index') }}" @if (request()->routeIs('admin.tickets.*')) aria-current="page" @endif>Casella</a>
                    <a href="{{ route('admin.sites.index') }}" @if (request()->routeIs('admin.sites.*')) aria-current="page" @endif>Portali</a>
                    <a href="{{ route('admin.settings.edit') }}" @if (request()->routeIs('admin.settings.*')) aria-current="page" @endif>Impostazioni</a>
                @else
                    <a href="{{ route('admin.tickets.index') }}" @if (request()->routeIs('admin.tickets.*')) aria-current="page" @endif>Ticket</a>
                    <a href="{{ route('admin.topics.index') }}" @if (request()->routeIs('admin.topics.*', 'admin.sections.*')) aria-current="page" @endif>Argomenti</a>
                    <a href="{{ route('admin.articles.index') }}" @if (request()->routeIs('admin.articles.*')) aria-current="page" @endif>Articoli</a>
                    <a href="{{ route('admin.plans.index') }}" @if (request()->routeIs('admin.plans.*')) aria-current="page" @endif>Piani</a>
                    <a href="{{ route('admin.hub.status') }}" @if (request()->routeIs('admin.hub.*')) aria-current="page" @endif>Hub</a>
                @endif
                <a href="{{ route('admin.mail.edit') }}" @if (request()->routeIs('admin.mail.*')) aria-current="page" @endif>Email</a>
                <a href="{{ rtrim(config('support.sso.portal'), '/') }}/admin">Gestione licenze</a>
            </nav>
            <a class="who" href="{{ route('admin.password.edit') }}" @if (request()->routeIs('admin.password.*')) aria-current="page" @endif>{{ auth()->user()->email }}</a>
            <form method="post" action="{{ route('admin.logout') }}">@csrf <button class="secondary" type="submit">Esci</button></form>
        @endauth
    </div>
</header>
<main>
    @if (session('ok'))<div class="flash" role="status">{{ session('ok') }}</div>@endif
    @if ($errors->any())
        <div class="errors" role="alert"><ul style="margin:0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
</main>
<footer class="site">Associazione Semplice · Supporto</footer>
</body>
</html>
