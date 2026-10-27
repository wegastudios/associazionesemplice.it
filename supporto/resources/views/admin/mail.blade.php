@extends('admin.layout')
@section('title', 'Email')
@section('content')
    <h1>Email</h1>
    <form method="post" action="{{ route('admin.mail.update') }}" class="card" style="max-width:640px">
        @csrf
        <div class="row">
            <div>
                <label for="host">Server di posta (SMTP)</label>
                <input id="host" name="host" value="{{ old('host', $values['host']) }}" required>
            </div>
            <div>
                <label for="port">Porta</label>
                <input id="port" name="port" type="number" min="1" max="65535" value="{{ old('port', $values['port']) }}" required>
            </div>
            <div>
                <label for="encryption">Sicurezza</label>
                <select id="encryption" name="encryption">
                    <option value="starttls" @selected(old('encryption', $values['encryption']) === 'starttls')>STARTTLS (porta 587)</option>
                    <option value="ssl" @selected(old('encryption', $values['encryption']) === 'ssl')>SSL/TLS (porta 465)</option>
                </select>
            </div>
        </div>
        <div class="row">
            <div>
                <label for="username">Utente</label>
                <input id="username" name="username" value="{{ old('username', $values['username']) }}" autocomplete="off">
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" placeholder="{{ $hasPassword ? '•••••••• (lascia vuoto per non cambiarla)' : '' }}">
            </div>
        </div>
        <div class="row">
            <div>
                <label for="from_address">Indirizzo mittente</label>
                <input id="from_address" name="from_address" type="email" value="{{ old('from_address', $values['from_address']) }}" required>
            </div>
            <div>
                <label for="from_name">Nome mittente</label>
                <input id="from_name" name="from_name" value="{{ old('from_name', $values['from_name']) }}" required>
            </div>
        </div>
        <p class="actions" style="margin-top:18px"><button type="submit">Salva</button></p>
    </form>
    <form method="post" action="{{ route('admin.mail.test') }}" class="card" style="max-width:640px">
        @csrf
        <p style="margin-top:0">Invia un messaggio di prova al tuo indirizzo (<strong>{{ auth()->user()->email }}</strong>) per verificare le impostazioni salvate.</p>
        @error('mail')<p class="hint" style="color:var(--danger)">{{ $message }}</p>@enderror
        <button class="secondary" type="submit">Invia messaggio di prova</button>
    </form>
@endsection
