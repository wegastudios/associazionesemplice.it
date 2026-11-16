@extends('site.layout')
@section('title', $article->title)
@section('description', \Illuminate\Support\Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace(['**', '__', '#', '`', '|'], ' ', \App\Support\SaasGuides::cleanBody((string) $article->body))))), 155))

@section('content')
    <p><a href="{{ route('help.topic', $article->section->topic->slug) }}">← {{ $article->section->topic->name }}</a></p>
    <h1 style="text-transform:none">{{ $article->title }}</h1>
    <article class="card prose">{!! \App\Support\ArticleBody::html(\App\Support\SaasGuides::cleanBody($article->body)) !!}</article>
    <div class="card"><p style="margin-top:0"><strong>Non hai risolto?</strong> Scrivici: ti rispondiamo per email.</p><p style="margin-bottom:0"><a class="btn" href="{{ route('ticket.create') }}">Apri una richiesta</a> <a class="btn secondary" href="{{ route('help.topic', $article->section->topic->slug) }}">Altre guide: {{ $article->section->topic->name }}</a></p></div>
@endsection
