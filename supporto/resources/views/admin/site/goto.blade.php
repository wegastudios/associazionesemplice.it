@extends('admin.layout')
@section('title', 'Vai a '.$label)
@section('content')
    <div class="card login">
        <h1>Vai a {{ $label }}</h1>
        <p>Il collegamento vale pochi secondi: premi il pulsante per entrare.</p>
        <form method="post" action="{{ $destination }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <p><button type="submit" style="width:100%">Entra in {{ $label }}</button></p>
        </form>
    </div>
@endsection
