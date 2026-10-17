@extends('admin.layout')
@section('title', 'Argomenti')
@section('content')
    <h1>Argomenti e sezioni</h1>
    <form method="post" action="{{ route('admin.topics.store') }}" class="card filters">
        @csrf
        <div><label for="name">Nuovo argomento</label><input id="name" name="name" maxlength="120" required></div>
        <div><label for="description">Descrizione</label><input id="description" name="description" maxlength="1000"></div>
        <div><label for="sort">Ordine</label><input id="sort" name="sort" type="number" min="0" value="0"></div>
        <div><label><input type="checkbox" name="is_public" value="1" checked> Visibile al pubblico</label></div>
        <div><button type="submit">Aggiungi</button></div>
    </form>

    @foreach ($topics as $topic)
        <section class="card">
            <form method="post" action="{{ route('admin.topics.update', $topic) }}" class="filters">
                @csrf
                <div><label>Nome<input name="name" value="{{ $topic->name }}" maxlength="120" required></label></div>
                <div><label>Descrizione<input name="description" value="{{ $topic->description }}" maxlength="1000"></label></div>
                <div><label>Ordine<input name="sort" type="number" min="0" value="{{ $topic->sort }}"></label></div>
                <div><label><input type="checkbox" name="is_public" value="1" @checked($topic->is_public)> Pubblico</label></div>
                <div><button class="secondary" type="submit">Salva</button></div>
            </form>
            <form method="post" action="{{ route('admin.topics.destroy', $topic) }}" onsubmit="return confirm('Eliminare l\'argomento con tutte le sue sezioni e articoli?')" style="margin-top:8px">@csrf <button class="danger outline" type="submit">Elimina argomento</button></form>

            <h2>Sezioni</h2>
            @foreach ($topic->sections as $section)
                <div class="actions" style="margin-bottom:8px">
                    <form method="post" action="{{ route('admin.sections.update', $section) }}" class="actions">
                        @csrf
                        <input name="name" value="{{ $section->name }}" maxlength="120" required aria-label="Nome sezione">
                        <input name="sort" type="number" min="0" value="{{ $section->sort }}" aria-label="Ordine" style="width:90px">
                        <button class="secondary" type="submit">Salva</button>
                    </form>
                    <form method="post" action="{{ route('admin.sections.destroy', $section) }}" onsubmit="return confirm('Eliminare la sezione con i suoi articoli?')">@csrf <button class="danger outline" type="submit">Elimina</button></form>
                </div>
            @endforeach
            <form method="post" action="{{ route('admin.sections.store', $topic) }}" class="actions">
                @csrf
                <input name="name" placeholder="Nuova sezione" maxlength="120" required aria-label="Nuova sezione">
                <input name="sort" type="number" min="0" value="0" aria-label="Ordine" style="width:90px">
                <button type="submit">Aggiungi sezione</button>
            </form>
        </section>
    @endforeach
@endsection
