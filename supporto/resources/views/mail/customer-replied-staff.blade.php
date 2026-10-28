Il cliente ha risposto al ticket n. {{ $ticket->hub_id }} — urgenza: {{ $urgency }}

Oggetto: {{ $ticket->subject }}
Cliente: {{ $ticket->customer_name }} <{{ $ticket->customer_email }}>
Stato: {{ $ticket->status }}

Messaggio:
{{ $reply->body }}

Apri il ticket:
{{ $link }}

— Supporto {{ config('app.name') }}
