@extends('admin.layout')
@section('title', 'Accesso negato')
@section('content')
    <div class="card">
        <h1>Accesso non riuscito</h1>
        <p>Il collegamento non è valido o è scaduto. Entra di nuovo dall'accesso staff.</p>
        <p><a href="{{ $login }}">Vai all'accesso staff</a></p>
    </div>
@endsection
