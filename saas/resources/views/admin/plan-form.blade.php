@extends('admin.layout')
@section('title', $isNew ? 'Nuovo piano' : 'Piano '.$values['name'])
@section('content')
    <h1>{{ $isNew ? 'Nuovo piano' : $values['name'] }}</h1>
    @if (session('status'))<p class="card" role="status">{{ session('status') }}</p>@endif
    @isset($problem)<p class="card error" role="alert">{{ $problem }}</p>@endisset

    @if (! $isNew)
        <p class="muted">Stai guardando la versione {{ $version }} della bozza.
            @if ($willCreateVersion)<strong>Questa versione è già in vigore: se salvi, nasce la versione {{ $version + 1 }}</strong> e la attuale resta com'è per chi l'ha comprata.@endif</p>
    @endif

    @if (! $isNew && count($preview) > 0)
        <div class="card scroll">
            <h2>Quanto costa (IVA esclusa)</h2>
            <table>
                <thead><tr><th>Soci</th><th>Al mese</th><th>All'anno</th></tr></thead>
                <tbody>@foreach ($preview as $row)<tr><td>{{ $row['members'] }}</td><td>{{ $row['month'] }} €</td><td>{{ $row['year'] }} €</td></tr>@endforeach</tbody>
            </table>
            <p class="muted">Calcolato con le stesse regole del portale, sulla bozza salvata.</p>
        </div>
    @endif

    <form class="card" method="post" action="{{ $isNew ? route('platform.plans.create') : route('platform.plans.save', $values['code']) }}">
        @csrf
        @if ($isNew)
            <label for="code">Codice (minuscole, cifre e «_»; non cambia più)</label>
            <input id="code" name="code" value="{{ $values['code'] }}" maxlength="40" required>
        @endif

        <div class="grid">
            <div><label for="name">Nome</label><input id="name" name="name" value="{{ $values['name'] }}" maxlength="80" required></div>
            <div><label for="order">Posizione nell'elenco</label><input id="order" name="order" value="{{ $values['order'] }}" inputmode="numeric"></div>
            <div><label for="trial_days">Giorni di prova</label><input id="trial_days" name="trial_days" value="{{ $values['trial_days'] }}" inputmode="numeric"></div>
        </div>
        <label for="audience">A chi è rivolto</label>
        <input id="audience" name="audience" value="{{ $values['audience'] }}" maxlength="160">

        <div class="grid">
            <div>
                <label for="support_level">Assistenza</label>
                <select id="support_level" name="support_level">
                    @foreach (['free' => 'Gratuita (solo piani gratuiti)', 'paid' => 'Standard', 'top' => 'Top'] as $v => $label)
                        <option value="{{ $v }}" @selected($values['support_level'] === $v)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="inline"><input type="checkbox" name="visible" value="1" @checked($values['visible'])> Visibile in vendita</label>
            </div>
        </div>

        <h2>Limiti</h2>
        <div class="grid">
            <div><label for="l-members">Soci inclusi (vuoto = senza limite)</label><input id="l-members" name="limits[members]" value="{{ $values['limits']['members'] ?? '' }}" inputmode="numeric"></div>
            <div><label for="l-operators">Operatori inclusi</label><input id="l-operators" name="limits[operators]" value="{{ $values['limits']['operators'] ?? '' }}" inputmode="numeric"></div>
            <div><label for="l-spm">Spazio file per socio (MB)</label><input id="l-spm" name="limits[storage_mb_per_member]" value="{{ $values['limits']['storage_mb_per_member'] ?? '' }}" inputmode="numeric"></div>
            <div><label for="l-min">Spazio file minimo (MB)</label><input id="l-min" name="limits[storage_mb_min]" value="{{ $values['limits']['storage_mb_min'] ?? '' }}" inputmode="numeric"></div>
        </div>

        <h2>Cosa comprende</h2>
        <p class="muted">Per ogni voce: inclusa, con un limite (scrivi il testo che vedrà il cliente) o non compresa.</p>
        @php $group = null; @endphp
        @foreach ($features as $f)
            @if ($f['group'] !== $group)<h3>{{ $f['group'] }}</h3>@php $group = $f['group']; @endphp@endif
            <div class="grid">
                <div>
                    <label for="f-{{ $f['id'] }}">{{ $f['text'] }}</label>
                    <select id="f-{{ $f['id'] }}" name="features[{{ $f['id'] }}][state]">
                        <option value="included" @selected($f['state'] === 'included')>Inclusa</option>
                        <option value="limit" @selected($f['state'] === 'limit')>Con un limite</option>
                        <option value="excluded" @selected($f['state'] === 'excluded')>Non compresa</option>
                    </select>
                </div>
                <div>
                    <label for="ft-{{ $f['id'] }}">Testo del limite</label>
                    <input id="ft-{{ $f['id'] }}" name="features[{{ $f['id'] }}][text]" value="{{ $f['limit_text'] }}" maxlength="160">
                </div>
            </div>
        @endforeach

        <h2>Prezzi</h2>
        <p class="muted">Importi in euro, IVA esclusa (es. 15 o 15,50).</p>
        @include('admin.partials.prices', ['rows' => $priceRows])

        @if ($canWrite)
            <button class="primary" type="submit">Controlla e salva nella bozza</button>
        @else
            <p class="muted">Il tuo ruolo è di sola lettura.</p>
        @endif
    </form>
    <p><a href="{{ route('platform.plans') }}">Torna ai piani</a></p>
@endsection
