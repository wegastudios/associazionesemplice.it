@extends('admin.layout')
@section('title', 'Impostazioni')
@section('content')
    <h1>Impostazioni</h1>
    <form method="post" action="{{ route('admin.settings.update') }}" class="card" style="max-width:640px">
        @csrf
        <h2 style="margin-top:0">Livelli di priorità</h2>
        <p class="hint">Solo i nomi: i livelli restano da 0 (minima) a 4 (massima).</p>
        <div class="row">
            @foreach ($labels as $level => $label)
                <div>
                    <label for="label{{ $level }}">Livello {{ $level }}</label>
                    <input id="label{{ $level }}" name="labels[{{ $level }}]" value="{{ old('labels.'.$level, $label) }}" maxlength="30">
                </div>
            @endforeach
        </div>

        <h2>Avvisi per le priorità 3 e 4</h2>
        <p class="hint">Sempre avvisati (ambiente): <strong>{{ implode(', ', $fixed) }}</strong>, più l'email di ogni portale e i suoi indirizzi aggiuntivi.</p>
        <label for="notify_emails">Altri indirizzi per tutti i portali (uno per riga)</label>
        <textarea id="notify_emails" name="notify_emails" rows="3">{{ old('notify_emails', implode("\n", $extra)) }}</textarea>

        <p class="actions" style="margin-top:18px"><button type="submit">Salva</button></p>
    </form>
@endsection
