@extends('admin.layout')
@section('title', 'Ticket '.$ticket->id)
@section('content')
    <h1>Ticket {{ $ticket->id }}</h1>
    <div class="card" style="max-width:720px">
        <dl>
            <dt>Portale</dt><dd><a href="{{ route('admin.sites.show', $ticket->site) }}">{{ $ticket->site->name }}</a></dd>
            <dt>Oggetto</dt><dd>{{ $ticket->subject }}</dd>
            <dt>Priorità</dt><dd>{{ $ticket->priority }} · {{ $labels[$ticket->priority] ?? '' }}</dd>
            <dt>Stato</dt><dd>{{ $ticket->status }}</dd>
            <dt>Argomento</dt><dd>{{ $ticket->topic ?? '—' }}</dd>
            <dt>Piano</dt><dd>{{ $ticket->plan ?? '—' }}</dd>
            <dt>Cliente</dt><dd>{{ $ticket->customer_name ?? '—' }} @if ($ticket->customer_email)&lt;{{ $ticket->customer_email }}&gt;@endif</dd>
            <dt>Privacy</dt><dd>@if ($ticket->privacy_accepted_at)accettata il {{ $ticket->privacy_accepted_at->format('d/m/Y H:i') }} (informativa v{{ $ticket->privacy_version }})@else<span class="muted">non registrata</span>@endif</dd>
            @if ($ticket->related_ticket_id)<dt>Collegato a</dt><dd><a href="{{ route('admin.tickets.show', $ticket->related_ticket_id) }}">{{ $ticket->related_ticket_id }}</a></dd>@endif
            <dt>Aperto</dt><dd>{{ $ticket->created_at->format('d/m/Y H:i') }}</dd>
            <dt>Ultima attività</dt><dd>{{ $ticket->last_activity_at?->format('d/m/Y H:i') }}</dd>
        </dl>
    </div>
    <p class="muted">La conversazione resta sul sito del portale; la vista unificata dei messaggi arriva nella fase 2.</p>
    <p><a class="btn secondary" href="{{ route('admin.tickets.index') }}">Torna alla casella</a></p>
@endsection
