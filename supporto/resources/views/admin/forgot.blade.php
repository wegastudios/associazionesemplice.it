@extends('admin.layout')
@section('title', 'Recupero password')
@section('content')
    <div class="card login">
        <img src="{{ route('brand', 'logo-512.png') }}" alt="" width="92" height="92">
        <h1>Recupero password</h1>
        <form method="post" action="{{ route('admin.password.email') }}">
            @csrf
            <label for="email">Email dell'account</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            <p class="hint">Riceverai un link per scegliere una nuova password.</p>
            <p style="margin-top:16px"><button type="submit" style="width:100%">Invia il link</button></p>
            <p style="text-align:center"><a href="{{ route('admin.login') }}">Torna all'accesso</a></p>
        </form>
    </div>
@endsection
