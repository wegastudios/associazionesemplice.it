@extends('site.layout')
@section('title', $article->title)
@section('content')
    <p><a href="{{ route('help.topic', $article->section->topic->slug) }}">← {{ $article->section->topic->name }}</a></p>
    <h1 style="text-transform:none">{{ $article->title }}</h1>
    <article class="card prose">{!! \App\Support\ArticleBody::html($article->body) !!}</article>
    <p class="card">Questa guida non ha risolto il problema? <a class="btn" href="{{ route('ticket.create') }}">Apri una richiesta</a></p>
@endsection
