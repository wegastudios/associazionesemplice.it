{{-- Modulo dei prezzi: righe esistenti + una riga vuota per aggiungerne una. Riga senza tipo = riga tolta. --}}
@php
    $blank = ['period' => 'month', 'kind' => '', 'vat_percent' => '22', 'amount' => '', 'base_members' => '', 'base' => '', 'months_billed' => '', 'tiers' => [], 'steps' => []];
    $all = array_merge(array_values($rows), [$blank]);
    $kinds = ['' => '— nessuno (toglie la riga) —', 'flat' => 'Prezzo fisso', 'members_tiers' => 'Scaglioni sui soci', 'step_tiers' => 'Scatti per quantità'];
@endphp
@foreach ($all as $i => $r)
    @php
        $tiers = array_merge(array_values($r['tiers'] ?? []), [['up_to' => '', 'amount' => ''], ['up_to' => '', 'amount' => '']]);
        $steps = array_merge(array_values($r['steps'] ?? []), [['up_to' => '', 'amount' => ''], ['up_to' => '', 'amount' => '']]);
    @endphp
    <fieldset>
        <legend>{{ ($r['kind'] ?? '') === '' ? 'Aggiungi un prezzo' : 'Prezzo '.($i + 1) }}</legend>
        <div class="grid">
            <div>
                <label for="p{{ $i }}-kind">Tipo</label>
                <select id="p{{ $i }}-kind" name="prices[{{ $i }}][kind]">
                    @foreach ($kinds as $value => $label)<option value="{{ $value }}" @selected(($r['kind'] ?? '') === $value)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="p{{ $i }}-period">Periodo</label>
                <select id="p{{ $i }}-period" name="prices[{{ $i }}][period]">
                    <option value="month" @selected(($r['period'] ?? '') === 'month')>Mensile</option>
                    <option value="year" @selected(($r['period'] ?? '') === 'year')>Annuale</option>
                </select>
            </div>
            <div>
                <label for="p{{ $i }}-vat">IVA %</label>
                <input id="p{{ $i }}-vat" name="prices[{{ $i }}][vat_percent]" value="{{ $r['vat_percent'] ?? '22' }}" inputmode="numeric">
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="p{{ $i }}-amount">Importo fisso (€, IVA esclusa)</label>
                <input id="p{{ $i }}-amount" name="prices[{{ $i }}][amount]" value="{{ $r['amount'] ?? '' }}" inputmode="decimal">
            </div>
            <div>
                <label for="p{{ $i }}-bm">Soci compresi nella base</label>
                <input id="p{{ $i }}-bm" name="prices[{{ $i }}][base_members]" value="{{ $r['base_members'] ?? '' }}" inputmode="numeric">
            </div>
            <div>
                <label for="p{{ $i }}-base">Prezzo della base (€ al mese)</label>
                <input id="p{{ $i }}-base" name="prices[{{ $i }}][base]" value="{{ $r['base'] ?? '' }}" inputmode="decimal">
            </div>
            <div>
                <label for="p{{ $i }}-mb">Mesi fatturati (annuale: 10 = due mesi in regalo)</label>
                <input id="p{{ $i }}-mb" name="prices[{{ $i }}][months_billed]" value="{{ $r['months_billed'] ?? '' }}" inputmode="numeric">
            </div>
        </div>

        <p class="muted">Scaglioni sui soci: ogni socio oltre la base costa quanto la fascia in cui cade. «Fino a» è il numero <strong>totale</strong> di soci in cui la fascia finisce; l'ultima fascia lasciala senza «fino a» (= oltre).</p>
        @foreach ($tiers as $j => $t)
            <div class="grid">
                <div><label for="p{{ $i }}-t{{ $j }}u">Fascia {{ $j + 1 }}: fino a (soci)</label><input id="p{{ $i }}-t{{ $j }}u" name="prices[{{ $i }}][tiers][{{ $j }}][up_to]" value="{{ $t['up_to'] }}" inputmode="numeric"></div>
                <div><label for="p{{ $i }}-t{{ $j }}a">€ per socio al mese</label><input id="p{{ $i }}-t{{ $j }}a" name="prices[{{ $i }}][tiers][{{ $j }}][amount]" value="{{ $t['amount'] }}" inputmode="decimal"></div>
            </div>
        @endforeach

        <p class="muted">Scatti per quantità: l'importo della prima fascia che contiene la quantità. Dopo l'ultima fascia chiusa il prezzo è su preventivo.</p>
        @foreach ($steps as $j => $s)
            <div class="grid">
                <div><label for="p{{ $i }}-s{{ $j }}u">Scatto {{ $j + 1 }}: fino a (quantità)</label><input id="p{{ $i }}-s{{ $j }}u" name="prices[{{ $i }}][steps][{{ $j }}][up_to]" value="{{ $s['up_to'] }}" inputmode="numeric"></div>
                <div><label for="p{{ $i }}-s{{ $j }}a">€ al mese</label><input id="p{{ $i }}-s{{ $j }}a" name="prices[{{ $i }}][steps][{{ $j }}][amount]" value="{{ $s['amount'] }}" inputmode="decimal"></div>
            </div>
        @endforeach
    </fieldset>
@endforeach
