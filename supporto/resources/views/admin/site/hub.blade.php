@extends('admin.layout')
@section('title', 'Hub')
@section('content')
    <h1>Collegamento con l'hub</h1>
    <div class="card" style="max-width:720px">
        <dl>
            <dt>Indirizzo dell'hub</dt><dd>{{ $url ?: 'non configurato (SUPPORT_HUB_URL)' }}</dd>
            <dt>Credenziali</dt><dd>{{ $configured ? 'presenti (SUPPORT_HUB_TOKEN)' : 'mancanti' }}</dd>
            <dt>Ticket in attesa di numero</dt><dd>{{ $pending }}</dd>
        </dl>
        <form method="post" action="{{ route('admin.hub.check') }}">@csrf <button type="submit" @disabled(! $configured)>Verifica il collegamento</button></form>
        @error('hub')<p class="hint" style="color:var(--danger)">{{ $message }}</p>@enderror
    </div>
    @if ($rules)
        <h2>Regole ricevute dall'hub</h2>
        <div class="card" style="max-width:720px">
            <dl>
                <dt>Priorità consentite</dt><dd>{{ implode(', ', $rules['allowed_priorities']) }}</dd>
                <dt>Riapertura dei ticket chiusi</dt><dd>{{ $rules['allow_reopen'] ? 'consentita' : 'non consentita' }}</dd>
            </dl>
        </div>
    @endif
@endsection
