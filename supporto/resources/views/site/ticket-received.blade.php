@extends('site.layout')
@section('title', 'Richiesta ricevuta')
@section('content')
    <h1>Richiesta ricevuta</h1>
    <div class="card" style="max-width:720px">
        <p>Abbiamo preso in carico la tua richiesta. Appena assegnato il numero del ticket ti scriviamo una email di conferma con il link per seguire la conversazione.</p>
        <p><a class="btn secondary" href="{{ route('help.index') }}">Torna al centro assistenza</a></p>
    </div>
@endsection
