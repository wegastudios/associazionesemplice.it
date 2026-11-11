@extends('admin.layout')
@section('title', 'Utenti')
@section('content')
    <h1>Utenti del supporto</h1>
    <p class="muted">Chi può entrare e con quale livello lo decide il portale: qui li vedi e li gestisci, ma la lista e i permessi stanno lì. Amministratore: gestisce argomenti, guide, ticket e utenti. Agente: risponde ai ticket.</p>

    @error('staff')<p class="alert" role="alert">{{ $message }}</p>@enderror
    @if ($error)<p class="alert" role="alert">{{ $error }}</p>@endif

    <form method="post" action="{{ route('admin.users.invite') }}" class="card filters">
        @csrf
        <div><label for="email">Invita per email</label><input id="email" name="email" type="email" maxlength="190" required></div>
        <div><label for="level">Livello</label><select id="level" name="level"><option value="agente">Agente</option><option value="admin">Amministratore</option></select></div>
        <div><button type="submit">Invia l'invito</button></div>
    </form>

    @if ($people !== null)
        <div class="card">
            <table>
                <thead><tr><th>Nome</th><th>Email</th><th>Livello</th><th>Stato</th><th></th></tr></thead>
                <tbody>
                @forelse ($people as $p)
                    <tr>
                        <td>{{ $p['name'] ?? '' }}</td>
                        <td>{{ $p['email'] ?? '' }}</td>
                        <td>{{ ($p['level'] ?? '') === 'admin' ? 'Amministratore' : 'Agente' }}</td>
                        <td>{{ ($p['active'] ?? false) ? 'Attivo' : 'Disattivato' }}@if ($p['locked'] ?? false) <span class="muted">(non modificabile)</span>@endif</td>
                        <td>
                            @unless ($p['locked'] ?? false)
                                <form method="post" action="{{ route('admin.users.grant') }}" class="actions">
                                    @csrf
                                    <input type="hidden" name="email" value="{{ $p['email'] }}">
                                    <select name="level" aria-label="Livello di {{ $p['email'] }}"><option value="agente" @selected(($p['level'] ?? '') === 'agente')>Agente</option><option value="admin" @selected(($p['level'] ?? '') === 'admin')>Amministratore</option></select>
                                    <button class="secondary" type="submit">Salva</button>
                                </form>
                                <form method="post" action="{{ route('admin.users.revoke') }}" class="actions" onsubmit="return confirm('Togliere a questa persona il ruolo nel supporto?')">@csrf <input type="hidden" name="email" value="{{ $p['email'] }}"><button class="danger outline" type="submit">Togli il ruolo</button></form>
                                <form method="post" action="{{ route('admin.users.deactivate') }}" class="actions" onsubmit="return confirm('Disattivare questa persona su tutte le aree?')">@csrf <input type="hidden" name="email" value="{{ $p['email'] }}"><button class="danger outline" type="submit">Disattiva</button></form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Nessun utente.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
@endsection
