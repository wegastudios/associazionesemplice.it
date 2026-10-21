@extends('admin.layout')
@section('title', 'Ticket '.($ticket->hub_id ?? 'in attesa'))
@section('content')
    <h1 style="text-transform:none">Ticket {{ $ticket->hub_id ?? '(in attesa di registrazione)' }} · {{ $ticket->subject }}</h1>

    @unless ($ticket->isRegistered())
        <div class="errors" role="status">
            Il ticket non ha ancora il numero assegnato dall'hub.@if ($ticket->registration_error) Ultimo errore: {{ $ticket->registration_error }}@endif
            <form method="post" action="{{ route('admin.tickets.register', $ticket) }}" style="margin-top:8px">@csrf <button type="submit">Riprova ora</button></form>
        </div>
    @endunless

    <div class="row">
        <div class="card">
            <dl>
                <dt>Cliente</dt><dd>{{ $ticket->customer_name }} &lt;{{ $ticket->customer_email }}&gt;</dd>
                <dt>Argomento</dt><dd>{{ $ticket->topic?->name ?? '—' }}</dd>
                <dt>Piano</dt><dd>{{ $ticket->plan?->name ?? '—' }}</dd>
                <dt>Aperto</dt><dd>{{ $ticket->created_at->format('d/m/Y H:i') }}</dd>
                <dt>Privacy</dt><dd>@if ($ticket->privacy_accepted_at)accettata il {{ $ticket->privacy_accepted_at->format('d/m/Y H:i') }} (informativa v{{ $ticket->privacy_version }})@else<span class="muted">non registrata</span>@endif</dd>
                @if ($ticket->related_hub_id)<dt>Collegato a</dt><dd>{{ $ticket->related_hub_id }}</dd>@endif
            </dl>
        </div>
        <div class="card">
            <form method="post" action="{{ route('admin.tickets.status', $ticket) }}" class="actions">
                @csrf
                <div style="flex:1"><label for="status" style="margin-top:0">Stato</label>
                    <select id="status" name="status">@foreach (config('support.statuses') as $st)<option value="{{ $st }}" @selected($ticket->status === $st)>{{ $st }}</option>@endforeach</select></div>
                <button type="submit">Aggiorna</button>
            </form>
            <form method="post" action="{{ route('admin.tickets.priority', $ticket) }}" class="actions">
                @csrf
                <div style="flex:1"><label for="priority">Priorità centrale</label>
                    <select id="priority" name="priority">@foreach ($labels as $level => $label)@if ($allowed === null || in_array($level, $allowed, true) || $level === $ticket->priority)<option value="{{ $level }}" @selected($ticket->priority === $level)>{{ $level }} · {{ $label }}</option>@endif @endforeach</select></div>
                <button type="submit">Aggiorna</button>
            </form>
            @if ($allowed === null)<p class="hint">Livelli consentiti non verificabili ora (hub non raggiungibile): l'hub controllerà la richiesta.</p>@endif
            <form method="post" action="{{ route('admin.tickets.manage', $ticket) }}" class="actions">
                @csrf
                <div style="flex:1"><label for="assigned_to">Assegnato a</label>
                    <select id="assigned_to" name="assigned_to"><option value="">—</option>@foreach ($staff as $u)<option value="{{ $u->id }}" @selected($ticket->assigned_to === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
                <div style="flex:1"><label for="internal_priority">Priorità interna</label>
                    <input id="internal_priority" name="internal_priority" value="{{ $ticket->internal_priority }}" maxlength="40"></div>
                <button class="secondary" type="submit">Salva</button>
            </form>
        </div>
    </div>

    <h2>Conversazione</h2>
    @foreach ($ticket->messages as $m)
        <div class="card" @if ($m->author_type === 'note') style="background:var(--yellow-soft)" @elseif ($m->author_type === 'staff') style="border-left:6px solid var(--teal)" @endif>
            <p class="muted" style="margin-top:0"><strong>{{ $m->author_name }}</strong> · {{ ['customer' => 'cliente', 'staff' => 'staff', 'note' => 'nota interna', 'system' => 'sistema'][$m->author_type] ?? $m->author_type }} · {{ $m->created_at->format('d/m/Y H:i') }}</p>
            <div style="white-space:pre-line">{{ $m->body }}</div>
        </div>
    @endforeach

    <form method="post" action="{{ route('admin.tickets.reply', $ticket) }}" class="card" style="max-width:820px">
        @csrf
        <label for="body" style="margin-top:0">Rispondi</label>
        <textarea id="body" name="body" rows="5" maxlength="10000" required></textarea>
        <label><input type="checkbox" name="note" value="1"> Solo nota interna (il cliente non la vede)</label>
        <p class="actions" style="margin-top:14px"><button type="submit">Invia</button></p>
    </form>
@endsection
