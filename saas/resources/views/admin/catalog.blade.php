@extends('admin.layout')
@section('title', 'Catalogo')
@section('content')
    <h1>Piani e prezzi</h1>
    @if (session('status'))<p class="card" role="status">{{ session('status') }}</p>@endif
    @error('publish')<p class="card error" role="alert">{{ $message }}</p>@enderror

    <div class="card">
        <h2>In vigore: revisione {{ $rev }}</h2>
        <p class="muted">Pubblicata il {{ $publishedAt ?? '—' }}. Piani: @foreach ($plans as $p){{ $p['code'] }} v{{ $p['plan_version'] }}@if (! $loop->last), @endif @endforeach</p>
        <p>Il portale legge questo catalogo e nessun'altra fonte.</p>
    </div>

    <div class="card">
        <h2>Bozza</h2>
        <p>{{ $hasDraft ? 'C\'è una bozza non ancora pubblicata.' : 'Nessuna bozza: il catalogo in vigore è quello pubblicato.' }}</p>
        <p><a href="{{ route('platform.catalog.draft') }}">{{ $hasDraft ? 'Modifica la bozza' : 'Modifica il catalogo' }}</a></p>
        @if (auth('platform')->user()?->canWrite() && $hasDraft)
            <form method="post" action="{{ route('platform.catalog.publish') }}">
                @csrf
                <label for="note">Nota (facoltativa)</label>
                <input id="note" name="note" maxlength="300">
                <button class="primary" type="submit">Pubblica come revisione {{ $rev + 1 }}</button>
            </form>
            <form method="post" action="{{ route('platform.catalog.discard') }}">@csrf<button type="submit">Scarta la bozza</button></form>
        @endif
    </div>

    <div class="card scroll">
        <h2>Catalogo in vigore (JSON)</h2>
        <pre>{{ $json }}</pre>
    </div>
@endsection
