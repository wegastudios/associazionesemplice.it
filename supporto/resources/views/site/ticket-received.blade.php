@extends('site.layout')
@section('title', 'Richiesta ricevuta')
@section('content')
    <h1>Richiesta ricevuta</h1>
    <div class="card" style="max-width:720px">
        <p>Abbiamo preso in carico la tua richiesta. Ti abbiamo inviato un'email di conferma con il link per seguire la richiesta.</p>
        <p><a class="btn secondary" href="{{ route('help.index') }}">Torna al centro assistenza</a></p>
    </div>
@endsection
