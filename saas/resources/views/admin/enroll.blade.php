@extends('admin.layout')
@section('title', 'Attiva la verifica')
@section('content')
    <form class="card" method="post" action="{{ route('platform.enroll') }}">
        @csrf
        <h1>Attiva la verifica in due passaggi</h1>
        <p>Per entrare serve un'app di autenticazione. Inquadra il codice QR, poi scrivi qui il codice a 6 cifre che mostra.</p>
        <div class="qr">{!! $qr !!}</div>
        <p class="muted">Se non puoi inquadrarlo, inserisci a mano questa chiave: <code>{{ $secret }}</code></p>
        <label for="code">Codice a 6 cifre</label>
        <input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
        @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button class="primary" type="submit">Attiva ed entra</button>
    </form>
@endsection
