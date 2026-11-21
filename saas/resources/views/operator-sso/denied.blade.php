@extends('operator-sso.layout')
@section('title', 'Accesso non collegato')
@section('content')
    <h1>Non riusciamo a collegare il tuo accesso</h1>
    <p>Il tuo utente del gestionale non ha ancora la verifica in due passaggi, e senza non possiamo collegarlo in sicurezza al tuo accesso unico.</p>
    <p>Chiedi aiuto al supporto dal tuo account.</p>
    <p><a href="{{ $account }}">Torna al tuo account</a></p>
@endsection
