@extends('admin.layout')
@section('title', 'Verifica')
@section('content')
    <form class="card" method="post" action="{{ route('platform.verify') }}">
        @csrf
        <h1>Codice a due passaggi</h1>
        <label for="code">Codice a 6 cifre dell'app di autenticazione</label>
        <input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code">
        @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button class="primary" type="submit">Entra</button>
    </form>
@endsection
