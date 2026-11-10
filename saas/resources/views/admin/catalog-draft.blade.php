@extends('admin.layout')
@section('title', 'Bozza del catalogo')
@section('content')
    <h1>Bozza del catalogo</h1>
    <p class="muted">Parti dalla revisione {{ $rev }}. Una versione di un piano già pubblicata non si modifica: per cambiare un prezzo o un limite aggiungi la versione successiva (<code>plan_version</code> + 1). Importi in centesimi, IVA esclusa. <code>rev</code> e data li mette la pubblicazione.</p>
    @isset($problem)<p class="card error" role="alert">{{ $problem }}</p>@endisset
    @if (auth('platform')->user()?->canWrite())
        <form class="card" method="post" action="{{ route('platform.catalog.save') }}">
            @csrf
            <label for="document">Catalogo (JSON)</label>
            <textarea id="document" name="document" rows="32" spellcheck="false" style="width:100%;font-family:monospace">{{ $json }}</textarea>
            <button class="primary" type="submit">Controlla e salva la bozza</button>
        </form>
    @else
        <div class="card scroll"><pre>{{ $json }}</pre><p class="muted">Il tuo ruolo è di sola lettura.</p></div>
    @endif
    <p><a href="{{ route('platform.catalog') }}">Torna al catalogo</a></p>
@endsection
