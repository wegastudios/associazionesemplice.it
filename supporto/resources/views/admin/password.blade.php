@extends('admin.layout')
@section('title', 'Cambio password')
@section('content')
    <h1>Cambio password</h1>
    <form method="post" action="{{ route('admin.password.update') }}" class="card" style="max-width:520px">
        @csrf
        <p class="muted" style="margin-top:0">Account: <strong>{{ auth()->user()->email }}</strong></p>

        <label for="current_password">Password attuale</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>

        <label for="password">Nuova password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required aria-describedby="pw-hint">
        <p id="pw-hint" class="hint">Almeno 12 caratteri, con lettere e numeri. Meglio una frase lunga o una password generata dal tuo gestore di password.</p>

        <label for="password_confirmation">Ripeti la nuova password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>

        <p style="margin-top:18px" class="actions">
            <button type="submit">Aggiorna password</button>
            <a class="btn secondary" href="{{ route('admin.home') }}">Annulla</a>
        </p>
    </form>
@endsection
