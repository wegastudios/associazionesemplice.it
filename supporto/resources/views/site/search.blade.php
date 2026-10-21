@extends('site.layout')
@section('title', 'Ricerca')
@section('content')
    <p><a href="{{ route('help.index') }}">← Centro assistenza</a></p>
    <h1>Risultati per "{{ $q }}"</h1>
    <div class="card">
        <ul>
            @forelse ($results as $article)
                <li><a href="{{ route('help.article', $article) }}">{{ $article->title }}</a> <span class="muted">· {{ $article->section->topic->name }}</span></li>
            @empty
                <li class="muted">Nessun risultato. Prova con altre parole o <a href="{{ route('ticket.create') }}">apri una richiesta</a>.</li>
            @endforelse
        </ul>
    </div>
@endsection
