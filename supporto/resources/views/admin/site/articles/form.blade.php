@extends('admin.layout')
@section('title', $article->exists ? 'Modifica articolo' : 'Nuovo articolo')
@section('content')
    <h1>{{ $article->exists ? 'Modifica articolo' : 'Nuovo articolo' }}</h1>
    <form method="post" action="{{ $article->exists ? route('admin.articles.update', $article) : route('admin.articles.store') }}" class="card" style="max-width:860px">
        @csrf
        <label for="section_id">Sezione</label>
        <select id="section_id" name="section_id" required>
            @foreach ($sections as $s)<option value="{{ $s->id }}" @selected((int) old('section_id', $article->section_id) === $s->id)>{{ $s->topic->name }} · {{ $s->name }}</option>@endforeach
        </select>
        <label for="title">Titolo</label>
        <input id="title" name="title" value="{{ old('title', $article->title) }}" maxlength="200" required>
        <label for="body">Testo</label>
        <textarea id="body" name="body" rows="16" required>{{ old('body', $article->body) }}</textarea>
        <label><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $article->is_published))> Pubblicato</label>
        <p class="actions" style="margin-top:18px"><button type="submit">Salva</button><a class="btn secondary" href="{{ route('admin.articles.index') }}">Indietro</a></p>
    </form>
    @if ($article->exists)
        <form method="post" action="{{ route('admin.articles.destroy', $article) }}" onsubmit="return confirm('Eliminare l\'articolo?')">@csrf <button class="danger outline" type="submit">Elimina articolo</button></form>
    @endif
@endsection
