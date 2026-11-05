@extends('site.layout')
@section('title', 'Ricerca')
@section('content')
    <p><a href="{{ route('help.index') }}">← Centro assistenza</a></p>
    <h1>Risultati per «{{ $q }}»</h1>
    <form method="get" action="{{ route('help.search') }}" class="card actions" role="search">
        <div style="flex:1;min-width:220px"><label for="q" style="margin-top:0">Cerca nelle guide</label><input id="q" name="q" type="search" minlength="2" required value="{{ $q }}" autocomplete="off"></div>
        <button type="submit">Cerca</button>
    </form>
    <div class="card">
        <ul>
            @forelse ($results as $article)
                <li><a href="{{ route('help.article', $article) }}">{{ $article->title }}</a> <span class="muted">· {{ $article->section->topic->name }}</span>@if ($article->search_snippet)<br><span class="muted">{{ $article->search_snippet }}</span>@endif</li>
            @empty
                <li class="muted">Nessun risultato. Prova con altre parole (anche una sola, per esempio «tessera») oppure <a href="{{ route('help.index') }}">sfoglia gli argomenti</a>. Se non trovi risposta, <a href="{{ route('ticket.create') }}">apri una richiesta</a>.</li>
            @endforelse
        </ul>
    </div>
@endsection
