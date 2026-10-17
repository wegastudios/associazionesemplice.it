@extends('site.layout')
@section('title', 'Centro assistenza')
@section('content')
    <h1>Come possiamo aiutarti?</h1>
    <form method="get" action="{{ route('help.search') }}" class="card actions" role="search">
        <div style="flex:1;min-width:220px"><label for="q" style="margin-top:0">Cerca nelle guide</label><input id="q" name="q" type="search" minlength="2" required></div>
        <button type="submit">Cerca</button>
    </form>
    <div class="row">
        @forelse ($topics as $topic)
            <section class="card">
                <h2 style="margin-top:0"><a href="{{ route('help.topic', $topic->slug) }}">{{ $topic->name }}</a></h2>
                @if ($topic->description)<p class="muted">{{ $topic->description }}</p>@endif
                <ul>@foreach ($topic->sections as $section)<li>{{ $section->name }} <span class="muted">({{ $section->articles_count }})</span></li>@endforeach</ul>
            </section>
        @empty
            <p class="muted">Le guide sono in preparazione.</p>
        @endforelse
    </div>
    <p class="card">Non trovi quello che cerchi? <a class="btn" href="{{ route('ticket.create') }}">Apri una richiesta</a></p>
@endsection
