Nuovo ticket n. {{ $ticket->hub_id }} — urgenza: {{ $urgency }}

Oggetto: {{ $ticket->subject }}
Cliente: {{ $ticket->customer_name }} <{{ $ticket->customer_email }}>
@if ($ticket->plan)
Piano: {{ $ticket->plan->name }}
@endif
@if ($ticket->topic)
Argomento: {{ $ticket->topic->name }}
@endif

Messaggio:
{{ $firstMessage }}

Apri il ticket:
{{ $link }}

— Supporto {{ config('app.name') }}
