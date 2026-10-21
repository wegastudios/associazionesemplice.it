Ticket a priorità {{ $levelLabel }} (livello {{ $level }} su 4)

Ticket:    {{ $ticket->id }}
Portale:   {{ $ticket->site->name }}
Oggetto:   {{ $ticket->subject }}
Stato:     {{ $ticket->status }}
@if ($ticket->customer_email)
Cliente:   {{ $ticket->customer_name }} <{{ $ticket->customer_email }}>
@endif

Apri la casella: {{ route('admin.tickets.show', $ticket->id) }}

Associazione Semplice · Supporto
