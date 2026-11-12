@extends('admin.layout')
@section('title', 'Promozioni')
@section('content')
    <h1>Promozioni</h1>
    @if (session('status'))<p class="card" role="status">{{ session('status') }}</p>@endif
    @isset($problem)<p class="card error" role="alert">{{ $problem }}</p>@endisset
    <p class="muted">Ogni promozione vale per un prodotto (uno o più piani), mai combinata con altre. Con un codice si sconta solo chi lo inserisce; senza codice vale per tutti nel periodo. Sconto in percentuale o in euro (IVA esclusa). Le modifiche stanno nella bozza finché non premi «Pubblica» nel <a href="{{ route('platform.catalog') }}">catalogo</a>.</p>

    <form method="post" action="{{ route('platform.promos.save') }}">
        @csrf
        @php $all = array_merge($rows, [['code' => '', 'name' => '', 'type' => 'percent', 'value' => '', 'plans' => [], 'valid_from' => '', 'valid_to' => '', 'max_uses' => '']]); @endphp
        @foreach ($all as $i => $r)
            <fieldset class="card">
                <legend>{{ $r['name'] === '' ? 'Nuova promozione' : $r['name'] }}</legend>
                <div class="grid">
                    <div><label for="pr{{ $i }}-name">Nome</label><input id="pr{{ $i }}-name" name="promos[{{ $i }}][name]" value="{{ $r['name'] }}" maxlength="80"></div>
                    <div><label for="pr{{ $i }}-code">Codice (vuoto = automatica)</label><input id="pr{{ $i }}-code" name="promos[{{ $i }}][code]" value="{{ $r['code'] }}" maxlength="40"></div>
                </div>
                <div class="grid">
                    <div>
                        <label for="pr{{ $i }}-type">Sconto</label>
                        <select id="pr{{ $i }}-type" name="promos[{{ $i }}][type]">
                            <option value="percent" @selected($r['type'] === 'percent')>Percentuale (%)</option>
                            <option value="amount_cents" @selected($r['type'] === 'amount_cents')>Importo (€)</option>
                        </select>
                    </div>
                    <div><label for="pr{{ $i }}-value">Valore</label><input id="pr{{ $i }}-value" name="promos[{{ $i }}][value]" value="{{ $r['value'] }}" inputmode="decimal"></div>
                    <div><label for="pr{{ $i }}-uses">Usi massimi (vuoto = illimitati)</label><input id="pr{{ $i }}-uses" name="promos[{{ $i }}][max_uses]" value="{{ $r['max_uses'] }}" inputmode="numeric"></div>
                </div>
                <div class="grid">
                    <div><label for="pr{{ $i }}-from">Dal (AAAA-MM-GG)</label><input id="pr{{ $i }}-from" name="promos[{{ $i }}][valid_from]" value="{{ $r['valid_from'] }}" placeholder="2026-12-01"></div>
                    <div><label for="pr{{ $i }}-to">Al (AAAA-MM-GG)</label><input id="pr{{ $i }}-to" name="promos[{{ $i }}][valid_to]" value="{{ $r['valid_to'] }}" placeholder="2026-12-31"></div>
                </div>
                <p class="muted">Piani a cui si applica (nessuno spuntato = tutti):</p>
                @foreach ($plans as $code => $name)
                    <label class="inline"><input type="checkbox" name="promos[{{ $i }}][plans][]" value="{{ $code }}" @checked(in_array($code, $r['plans'], true))> {{ $name }}</label>
                @endforeach
                @if ($r['name'] !== '')
                    <label class="inline"><input type="checkbox" name="promos[{{ $i }}][delete]" value="1"> Togli questa promozione</label>
                @endif
            </fieldset>
        @endforeach

        @if ($canWrite)
            <button class="primary" type="submit">Controlla e salva nella bozza</button>
        @else
            <p class="muted">Il tuo ruolo è di sola lettura.</p>
        @endif
    </form>
@endsection
