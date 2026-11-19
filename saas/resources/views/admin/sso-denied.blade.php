@extends('admin.layout')
@section('title', 'Accesso non riuscito')
@section('content')
    <div class="card">
        <h1>Accesso non riuscito</h1>
        <p>Il link non è valido o è scaduto. Entra di nuovo dall'accesso staff.</p>
        <p><a href="{{ $login }}">Vai all'accesso staff</a></p>
    </div>
@endsection
