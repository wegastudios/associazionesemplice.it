@extends('site.layout')
@section('title', 'Richiesta ricevuta')
@section('content')
    <h1>Richiesta ricevuta</h1>
    <div class="card" style="max-width:720px">
        <p>Abbiamo ricevuto la tua richiesta. Ti rispondiamo per email, di solito entro due giorni lavorativi (uno per chi usa il gestionale). Ti abbiamo mandato una conferma con il link per seguirla.</p>
        <p><a class="btn secondary" href="{{ route('help.index') }}">Torna al centro assistenza</a></p>
    </div>
@endsection
