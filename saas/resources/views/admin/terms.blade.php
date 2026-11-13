@extends('admin.layout')
@section('title', 'Condizioni')
@section('content')
    <h1>Condizioni di vendita</h1>
    @if (session('status'))<p class="card" role="status">{{ session('status') }}</p>@endif
    @isset($problem)<p class="card error" role="alert">{{ $problem }}</p>@endisset
    <p class="muted">Valgono per tutti i piani. Come il resto del catalogo, si salvano nella bozza e i clienti le vedono solo dopo «Pubblica».</p>

    <form method="post" action="{{ route('platform.terms.save') }}">
        @csrf
        <fieldset class="card">
            <legend>IVA</legend>
            <label for="vat_mode">Come vendi</label>
            <select id="vat_mode" name="vat_mode">
                <option value="standard" @selected($t['vat_mode'] === 'standard')>Con IVA: i prezzi sono IVA esclusa e l'IVA si aggiunge</option>
                <option value="exempt" @selected($t['vat_mode'] === 'exempt')>Senza IVA (regime forfettario): il totale è quello dei prezzi</option>
            </select>
            <label for="vat_note">Dicitura sulla fattura e al pagamento (solo senza IVA)</label>
            <input id="vat_note" name="vat_note" value="{{ $t['vat_note'] }}" maxlength="300">
            <p class="muted small">Per esempio: «Operazione senza applicazione dell'IVA ai sensi dell'art. 1, commi 54-89, legge 190/2014». Scrivi la dicitura che ti ha dato il commercialista.</p>
        </fieldset>

        <fieldset class="card">
            <legend>Commissione sui pagamenti online dei soci</legend>
            <div class="grid">
                <div><label for="own_gateway_percent">Se l'associazione usa il proprio Stripe o PayPal (%)</label><input id="own_gateway_percent" name="own_gateway_percent" value="{{ $t['own_gateway_percent'] }}" inputmode="numeric"></div>
                <div><label for="collected_by_us_percent">Se incassiamo noi e giriamo (%)</label><input id="collected_by_us_percent" name="collected_by_us_percent" value="{{ $t['collected_by_us_percent'] }}" inputmode="numeric"></div>
            </div>
            <p class="muted small">Oltre al costo del circuito di pagamento. Da 0 a 30. Prima di far incassare noi, verifica con il commercialista l'incasso per conto terzi e l'IVA sulla commissione.</p>
        </fieldset>

        <fieldset class="card">
            <legend>Durante la prova gratuita</legend>
            <label class="inline"><input type="checkbox" name="trial_exports" value="1" @checked($t['trial_exports'])> Si possono esportare i dati (soci, contabilità, libro soci)</label>
            <label class="inline"><input type="checkbox" name="trial_accountant_access" value="1" @checked($t['trial_accountant_access'])> Si può collegare un commercialista</label>
            <p class="muted small">Con le caselle vuote la prova funziona in tutto, tranne esportazioni e accesso del commercialista. La durata della prova si imposta in ogni piano.</p>
        </fieldset>

        @if ($canWrite)
            <button class="primary" type="submit">Controlla e salva nella bozza</button>
        @else
            <p class="muted">Il tuo ruolo è di sola lettura.</p>
        @endif
    </form>
@endsection
