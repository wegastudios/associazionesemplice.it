@extends('site.layout')
@section('title', 'Centro assistenza')
@section('content')
    <h1>Come possiamo aiutarti?</h1>
    <form method="get" action="{{ route('help.search') }}" class="card actions" role="search">
        <div style="flex:1;min-width:220px"><label for="q" style="margin-top:0">Cerca nelle guide</label><input id="q" name="q" type="search" minlength="2" required placeholder="Es. password, tessera" autocomplete="off"></div>
        <button type="submit">Cerca</button>
    </form>
    <div class="row">
        @php $productLabels = ['plugin' => 'Plugin', 'saas' => 'SaaS']; @endphp
        @forelse ($topics->sortBy(fn ($t) => $t->product === null ? 2 : ($t->product === 'plugin' ? 0 : 1))->values() as $topic)
            <section class="card">
                @if ($topic->product !== null && $topics->pluck('product')->unique()->count() > 1)<p class="muted" style="margin:0">{{ $productLabels[$topic->product] ?? '' }}</p>@elseif ($topic->product === null && $topics->pluck('product')->unique()->count() > 1)<p class="muted" style="margin:0">Per tutti</p>@endif
                <h2 style="margin-top:0"><a href="{{ route('help.topic', $topic->slug) }}">{{ $topic->name }}</a></h2>
                @if ($topic->description)<p class="muted">{{ $topic->description }}</p>@endif
                <ul>@foreach ($topic->sections as $section)<li><a href="{{ route('help.topic', $topic->slug) }}#sezione-{{ $section->id }}">{{ $section->name }}</a> <span class="muted">({{ $section->articles_count }})</span></li>@endforeach</ul>
            </section>
        @empty
            <p class="muted">Non trovi una guida? Scrivici: la risposta arriva per email.</p>
        @endforelse
    </div>
    <div class="card"><p style="margin-top:0">Non trovi quello che cerchi? Scrivici: ti rispondiamo per email.</p><p style="margin-bottom:0"><a class="btn" href="{{ route('ticket.create') }}">Apri una richiesta</a></p><p class="muted" style="margin-bottom:0">Per scrivere al supporto entri con l'email della tua licenza: al primo accesso ti mandiamo un link.</p></div>
@endsection
