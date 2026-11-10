@extends('admin.layout')
@section('title', 'Accesso')
@section('content')
    <form class="card" method="post" action="{{ route('platform.login') }}" autocomplete="on">
        @csrf
        <h1>Accesso all'amministrazione</h1>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button class="primary" type="submit">Continua</button>
        <p class="muted">Dopo la password serve il codice a due passaggi.</p>
    </form>
@endsection
