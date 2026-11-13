@extends('admin.layout')
@section('title', 'Catalogo')
@section('content')
    <h1>Catalogo</h1>
    @if (session('status'))<p class="card" role="status">{{ session('status') }}</p>@endif
    @error('publish')<p class="card error" role="alert">{{ $message }}</p>@enderror

    <div class="card">
        <h2>In vigore: revisione {{ $rev }}</h2>
        <p class="muted">Pubblicata il {{ $publishedAt ?? '—' }}. Piani: @foreach ($plans as $p){{ $p['code'] }} v{{ $p['plan_version'] }}@if (! $loop->last), @endif @endforeach</p>
        <p>Il portale legge questo catalogo e nessun'altra fonte.</p>
        <p><a href="{{ route('platform.plans') }}">Piani</a> · <a href="{{ route('platform.addons') }}">Pacchetti e servizi</a> · <a href="{{ route('platform.promos') }}">Promozioni</a></p>
    </div>

    <div class="card">
        <h2>Bozza</h2>
        @if ($hasDraft)
            <p>C'è una bozza non ancora pubblicata. Rispetto al catalogo in vigore cambia:</p>
            @if (count($changes) > 0)
                <ul>@foreach ($changes as $change)<li>{{ $change }}</li>@endforeach</ul>
            @else
                <p class="muted">Nessuna differenza: la bozza è uguale al catalogo in vigore.</p>
            @endif
        @else
            <p>Nessuna bozza: il catalogo in vigore è quello pubblicato.</p>
        @endif
        @if (auth('platform')->user()?->canWrite() && $hasDraft)
            <form method="post" action="{{ route('platform.catalog.publish') }}" onsubmit="return confirm('Pubblicare la revisione? I prezzi cambiano subito sul portale.')">
                @csrf
                <label for="note">Nota sulla modifica (facoltativa, resta nel registro)</label>
                <input id="note" name="note" maxlength="300">
                <button class="primary" type="submit">Pubblica come revisione {{ $rev + 1 }}</button>
            </form>
            <form method="post" action="{{ route('platform.catalog.discard') }}" onsubmit="return confirm('Scartare la bozza? Le modifiche non pubblicate si perdono.')">@csrf<button type="submit">Scarta la bozza</button></form>
        @endif
    </div>

    <details class="card scroll">
        <summary>Avanzato: il catalogo in JSON</summary>
        <p><a href="{{ route('platform.catalog.draft') }}">Modifica la bozza come JSON</a></p>
        <pre>{{ $json }}</pre>
    </details>
@endsection
