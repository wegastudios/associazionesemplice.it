@extends('admin.layout')
@section('title', 'Nuova password')
@section('content')
    <div class="card login">
        <img src="{{ route('brand', 'logo-512.png') }}" alt="" width="92" height="92">
        <h1>Nuova password</h1>
        <form method="post" action="{{ route('admin.password.reset.store') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required>
            <label for="password">Nuova password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required autofocus>
            <p class="hint">Almeno 12 caratteri, con lettere e numeri.</p>
            <label for="password_confirmation">Ripeti la password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
            <p style="margin-top:16px"><button type="submit" style="width:100%">Salva la password</button></p>
        </form>
    </div>
@endsection
