@php($site ??= null)
<label for="name">Nome del portale</label>
<input id="name" name="name" value="{{ old('name', $site?->name) }}" maxlength="120" required>

<div class="row">
    <div>
        <label for="portal_email">Email del portale</label>
        <input id="portal_email" name="portal_email" type="email" value="{{ old('portal_email', $site?->portal_email) }}" required>
        <p class="hint">Riceve l'avviso per i ticket di priorità 3 e 4.</p>
    </div>
    <div>
        <label for="url">Indirizzo del sito di supporto</label>
        <input id="url" name="url" type="url" value="{{ old('url', $site?->url) }}" placeholder="https://supporto.associazionesemplice.it">
    </div>
</div>

<fieldset style="border:0;padding:0;margin:12px 0 0">
    <legend style="font-weight:500">Livelli di priorità che il portale può assegnare</legend>
    @php($allowed = old('allowed_priorities', $site?->priorities() ?? [0, 1, 2]))
    <div class="checks">
        @foreach ($labels as $level => $label)
            <label><input type="checkbox" name="allowed_priorities[]" value="{{ $level }}" @checked(in_array($level, array_map('intval', (array) $allowed), true))> {{ $level }} · {{ $label }}</label>
        @endforeach
    </div>
    <p class="hint">0 è la priorità minima, 4 la massima. Una richiesta con un livello non abilitato viene rifiutata.</p>
</fieldset>

<label><input type="checkbox" name="allow_reopen" value="1" @checked(old('allow_reopen', $site?->allow_reopen ?? true))> Il portale può riaprire i ticket chiusi</label>

<label for="notify_emails">Altri indirizzi avvisati per le priorità 3 e 4 (uno per riga)</label>
<textarea id="notify_emails" name="notify_emails" rows="3">{{ old('notify_emails', implode("\n", $site?->notify_emails ?? [])) }}</textarea>
<p class="hint">Si aggiungono a info@wegastudios.com, agli indirizzi globali e all'email del portale.</p>
