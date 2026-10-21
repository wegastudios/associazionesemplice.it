@extends('admin.layout')
@section('title', 'Articoli')
@section('content')
    <h1>Articoli</h1>
    <p class="actions"><a class="btn" href="{{ route('admin.articles.create') }}">Nuovo articolo</a></p>
    <div class="card tablewrap">
        <table>
            <thead><tr><th>Titolo</th><th>Argomento · sezione</th><th>Stato</th><th>Modificato</th></tr></thead>
            <tbody>
            @forelse ($articles as $a)
                <tr>
                    <td><a href="{{ route('admin.articles.edit', $a) }}">{{ $a->title }}</a></td>
                    <td>{{ $a->section->topic->name }} · {{ $a->section->name }}</td>
                    <td>{{ $a->is_published ? 'pubblicato' : 'bozza' }}</td>
                    <td>{{ $a->updated_at->format('d/m/Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Nessun articolo. Crea prima almeno un argomento con una sezione.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $articles->links() }}
@endsection
