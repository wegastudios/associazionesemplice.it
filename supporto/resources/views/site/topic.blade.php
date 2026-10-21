@extends('site.layout')
@section('title', $topic->name)
@section('content')
    <p><a href="{{ route('help.index') }}">← Centro assistenza</a></p>
    <h1>{{ $topic->name }}</h1>
    @if ($topic->description)<p class="muted">{{ $topic->description }}</p>@endif
    @foreach ($topic->sections as $section)
        <section class="card">
            <h2 style="margin-top:0">{{ $section->name }}</h2>
            <ul>
                @forelse ($section->articles as $article)
                    <li><a href="{{ route('help.article', $article) }}">{{ $article->title }}</a></li>
                @empty
                    <li class="muted">Nessun articolo.</li>
                @endforelse
            </ul>
        </section>
    @endforeach
@endsection
