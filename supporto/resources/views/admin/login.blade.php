@extends('admin.layout')
@section('title', 'Accesso')
@section('content')
    <div class="card login">
        @include("partials.logo", ["h" => 64])
        <h1>Accesso amministratore</h1>
        <form method="post" action="{{ route('admin.login.attempt') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <p style="margin-top:16px"><button type="submit" style="width:100%">Entra</button></p>
            <p style="text-align:center"><a href="{{ route('admin.password.forgot') }}">Password dimenticata?</a></p>
        </form>
    </div>
@endsection
