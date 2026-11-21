@extends('operator-sso.layout')
@section('title', 'Accesso non collegato')
@section('content')
    <h1>Accesso non collegato</h1>
    <p>Il tuo utente del gestionale non ha ancora la verifica in due passaggi: senza, non si può collegare in sicurezza all'accesso unico.</p>
    <p>Chiedi aiuto al supporto dal tuo account.</p>
    <p><a href="{{ $account }}">Torna al tuo account</a></p>
@endsection
