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
            <legend>IVA sui prezzi</legend>
            <label for="vat_mode">Come si presentano i prezzi</label>
            <select id="vat_mode" name="vat_mode">
                <option value="standard" @selected($t['vat_mode'] === 'standard')>Applichiamo l'IVA: al prezzo del listino si aggiunge l'IVA al momento del pagamento</option>
                <option value="exempt" @selected($t['vat_mode'] === 'exempt')>Non applichiamo l'IVA: il cliente paga il prezzo del listino, senza aggiunte</option>
            </select>
            <label for="vat_note">Frase da mostrare sotto i prezzi e in fattura</label>
            <input id="vat_note" name="vat_note" value="{{ $t['vat_note'] }}" maxlength="300">
            <p class="muted small">Scegli come si presentano i prezzi ai clienti. La scelta vale insieme per listino, riepilogo prima del pagamento e fatture: i prezzi dei piani non vanno ritoccati. La frase serve quando non applichiamo l'IVA: scrivi quella che ti ha indicato il commercialista.</p>
        </fieldset>

        <fieldset class="card">
            <legend>Commissione sui pagamenti dei soci</legend>
            <div class="grid">
                <div><label for="own_gateway_percent">Quando l'associazione incassa con il suo Stripe o PayPal (%)</label><input id="own_gateway_percent" name="own_gateway_percent" value="{{ $t['own_gateway_percent'] }}" inputmode="numeric"></div>
                <div><label for="collected_by_us_percent">Quando incassiamo noi e poi versiamo all'associazione (%)</label><input id="collected_by_us_percent" name="collected_by_us_percent" value="{{ $t['collected_by_us_percent'] }}" inputmode="numeric"></div>
            </div>
            <p class="muted small">È la percentuale che tratteniamo su ogni pagamento fatto online dai soci: quote, corsi, eventi. Si aggiunge al costo di Stripe o PayPal. Scrivi 0 per non trattenere nulla (da 0 a 30).</p>
        </fieldset>

        <fieldset class="card">
            <legend>Cosa si può fare durante la prova gratuita</legend>
            <label class="inline"><input type="checkbox" name="trial_exports" value="1" @checked($t['trial_exports'])> Scaricare i dati: elenco dei soci, contabilità, libro soci</label>
            <label class="inline"><input type="checkbox" name="trial_accountant_access" value="1" @checked($t['trial_accountant_access'])> Dare l'accesso al commercialista</label>
            <div class="grid">
                <div><label for="end_discount_percent">Sconto per chi sceglie un piano dopo la prova (%)</label><input id="end_discount_percent" name="end_discount_percent" value="{{ $t['end_discount_percent'] }}" inputmode="numeric"></div>
                <div><label for="purge_days">Giorni dopo la fine della prova prima di cancellare i dati</label><input id="purge_days" name="purge_days" value="{{ $t['purge_days'] }}" inputmode="numeric"></div>
            </div>
            <p class="muted small">Sconto: vale sul primo pagamento, dal giorno in cui la prova finisce fino al giorno in cui i dati vengono cancellati. Scrivi 0 per non proporre sconti (le email partono senza il paragrafo dell'offerta). Durante la prova si usa tutto il gestionale. Le voci senza spunta si sbloccano quando l'associazione sceglie un piano. La durata della prova si imposta in ogni piano.</p>
        </fieldset>

        <fieldset class="card">
            <legend>Limite di soci del piano</legend>
            <div class="grid">
                <div><label for="warn_percent">Primo avviso quando i soci arrivano al (% del limite)</label><input id="warn_percent" name="warn_percent" value="{{ $t['warn_percent'] }}" inputmode="numeric"></div>
                <div><label for="full_percent">Secondo avviso quando arrivano al (% del limite)</label><input id="full_percent" name="full_percent" value="{{ $t['full_percent'] }}" inputmode="numeric"></div>
                <div><label for="tolerance_percent">Si può superare il limite fino a (%)</label><input id="tolerance_percent" name="tolerance_percent" value="{{ $t['tolerance_percent'] }}" inputmode="numeric"></div>
                <div><label for="tolerance_days">per quanti giorni</label><input id="tolerance_days" name="tolerance_days" value="{{ $t['tolerance_days'] }}" inputmode="numeric"></div>
                <div><label for="guests_free_percent">Ospiti dell'anno che non contano (% del limite)</label><input id="guests_free_percent" name="guests_free_percent" value="{{ $t['guests_free_percent'] }}" inputmode="numeric"></div>
            </div>
            <p class="muted small">Passato il tempo di tolleranza si bloccano i nuovi soci, non l'accesso né i dati: l'associazione cambia piano o, sulla Grande, compra un pacchetto di soci. Contano i soci e gli ex soci; gli ospiti degli anni passati no; gli ospiti dell'anno in corso contano solo oltre la quota che non conta. Scrivi 0 nella tolleranza per bloccare subito al limite.</p>
        </fieldset>

        @if ($canWrite)
            <button class="primary" type="submit">Controlla e salva nella bozza</button>
        @else
            <p class="muted">Il tuo ruolo è di sola lettura.</p>
        @endif
    </form>
@endsection
