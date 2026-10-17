@extends('site.layout')
@section('title', 'Apri una richiesta')
@section('content')
    <h1>Apri una richiesta</h1>
    @if (! empty($customer))<p class="hint">Stai scrivendo come {{ $customer["email"] }}: i dati del tuo account non si modificano qui. <a href="{{ route("ticket.mine") }}">I miei ticket</a></p>@endif
    <form method="post" action="{{ route('ticket.store') }}" class="card" style="max-width:720px">
        @csrf
        {{-- Controllo anti-bot: marca temporale cifrata + campo trappola fuori schermo (le persone non lo vedono) --}}
        <input type="hidden" name="form_token" value="{{ $formToken }}">
        <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true"><label for="website">Lascia vuoto questo campo</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
        @if ($related)<input type="hidden" name="related_hub_id" value="{{ (int) $related }}"><p class="hint">Questa richiesta è collegata al ticket {{ (int) $related }}.</p>@endif
        <div class="row">
            <div><label for="customer_name">Il tuo nome</label><input id="customer_name" name="customer_name" value="{{ ($customer["name"] ?? null) ?: old("customer_name") }}" maxlength="150" required @readonly(! empty($customer["name"]))></div>
            <div><label for="customer_email">La tua email</label><input id="customer_email" name="customer_email" type="email" value="{{ $customer["email"] ?? old("customer_email") }}" maxlength="190" required @readonly(! empty($customer["email"]))></div>
        </div>
        <div class="row">
            @if ($topics->isNotEmpty())
                <div><label for="topic_id">Argomento</label>
                    <select id="topic_id" name="topic_id"><option value="">—</option>@foreach ($topics as $t)<option value="{{ $t->id }}" @selected(old('topic_id') == $t->id)>{{ $t->name }}</option>@endforeach</select></div>
            @endif
            @if ($plans->isNotEmpty())
                @if (! empty($customer["plan_id"]))<div><label for="plan_name">Il tuo piano</label><input id="plan_name" value="{{ $plans->firstWhere("id", $customer["plan_id"])?->name }}" readonly><input type="hidden" name="plan_id" value="{{ $customer["plan_id"] }}"></div>@else<div><label for="plan_id">Il tuo piano</label>
                    <select id="plan_id" name="plan_id"><option value="">—</option>@foreach ($plans as $p)<option value="{{ $p->id }}" @selected(old('plan_id') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>@endif
            @endif
        </div>
        <label for="subject">Oggetto</label>
        <input id="subject" name="subject" value="{{ old('subject') }}" maxlength="200" required>
        <label for="body">Descrivi il problema</label>
        <textarea id="body" name="body" rows="8" minlength="10" maxlength="10000" required>{{ old('body') }}</textarea>
        <label style="margin-top:18px"><input type="checkbox" name="privacy_accepted" value="1" required @checked(old('privacy_accepted'))>
            Ho letto l'<a href="{{ $privacyUrl }}" target="_blank" rel="noopener">informativa sulla privacy</a> e acconsento al trattamento dei miei dati per gestire questa richiesta di assistenza.</label>
        <p class="hint">Registriamo la data della tua accettazione e la versione dell'informativa.</p>
        <p class="actions" style="margin-top:18px"><button type="submit">Invia la richiesta</button></p>
    </form>
@endsection
