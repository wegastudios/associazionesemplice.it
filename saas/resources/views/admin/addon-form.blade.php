@extends('admin.layout')
@section('title', $isNew ? 'Nuovo pacchetto' : 'Pacchetto '.$values['name'])
@section('content')
    <h1>{{ $isNew ? 'Nuovo pacchetto o servizio' : $values['name'] }}</h1>
    @isset($problem)<p class="card error" role="alert">{{ $problem }}</p>@endisset

    @if (! $isNew && count($preview) > 0)
        <div class="card scroll">
            <h2>Quanto costa (IVA esclusa)</h2>
            <table>
                <thead><tr><th>Soci</th><th>Al mese</th><th>All'anno</th></tr></thead>
                <tbody>@foreach ($preview as $row)<tr><td>{{ $row['members'] }}</td><td>{{ $row['month'] }} €</td><td>{{ $row['year'] }} €</td></tr>@endforeach</tbody>
            </table>
        </div>
    @endif

    <form class="card" method="post" action="{{ $isNew ? route('platform.addons.create') : route('platform.addons.save', $values['code']) }}">
        @csrf
        @if ($isNew)
            <label for="code">Codice (minuscole, cifre e «_»; non cambia più)</label>
            <input id="code" name="code" value="{{ $values['code'] }}" maxlength="40" required>
            <label for="kind">Tipo (non si può cambiare dopo)</label>
            <select id="kind" name="kind">
                @foreach (['package' => 'Pacchetto di funzioni', 'operators' => 'Operatori', 'members' => 'Soci in più', 'storage' => 'Spazio file', 'domain' => 'Dominio personalizzato', 'dedicated_db' => 'Database dedicato', 'recharge' => 'Ricarica'] as $v => $label)
                    <option value="{{ $v }}" @selected($values['kind'] === $v)>{{ $label }}</option>
                @endforeach
            </select>
        @else
            <p class="muted">Tipo: <strong>{{ $values['kind'] }}</strong> (non si può cambiare: cambierebbe cosa ha comprato chi lo ha già).</p>
        @endif

        <label for="name">Nome</label>
        <input id="name" name="name" value="{{ $values['name'] }}" maxlength="80" required>
        <label class="inline"><input type="checkbox" name="visible" value="1" @checked($values['visible'])> Visibile in vendita</label>

        <h2>Cosa dà</h2>
        <p class="muted">Compila solo il campo del tipo scelto.</p>
        <div class="grid">
            <div><label for="go">Operatori aggiunti</label><input id="go" name="grant_operators" value="{{ $values['grant_operators'] }}" inputmode="numeric"></div>
            <div><label for="gm">Soci aggiunti</label><input id="gm" name="grant_members" value="{{ $values['grant_members'] }}" inputmode="numeric"></div>
            <div><label for="gs">Spazio aggiunto (GB)</label><input id="gs" name="grant_storage_gb" value="{{ $values['grant_storage_gb'] }}" inputmode="numeric"></div>
            <div><label for="gp">Codice del pacchetto di funzioni</label><input id="gp" name="grant_package" value="{{ $values['grant_package'] }}" maxlength="40"></div>
        </div>
        <label for="gl">Limiti del pacchetto (avanzato, JSON; vuoto = nessuno)</label>
        <input id="gl" name="grant_limits" value="{{ $values['grant_limits'] }}" placeholder='{"yearly": 50}'>

        <h2>Prezzi</h2>
        <p class="muted">Importi in euro, IVA esclusa (es. 19 o 19,90).</p>
        @include('admin.partials.prices', ['rows' => $priceRows])

        @if ($canWrite)
            <button class="primary" type="submit">Controlla e salva nella bozza</button>
        @else
            <p class="muted">Il tuo ruolo è di sola lettura.</p>
        @endif
    </form>
    <p><a href="{{ route('platform.addons') }}">Torna ai pacchetti</a></p>
@endsection
