Ciao {{ $ticket->customer_name }},

abbiamo ricevuto la tua richiesta "{{ $ticket->subject }}".

Numero del ticket: {{ $ticket->hub_id }}

Puoi seguire la conversazione e rispondere da questo link personale (non condividerlo):
{{ $link }}

Grazie,
{{ config('app.name') }}
