{{ filled($ticket->customer_name) ? 'Ciao '.$ticket->customer_name.',' : 'Buongiorno,' }}

abbiamo ricevuto la tua richiesta "{{ $ticket->subject }}".

Numero della richiesta: {{ $ticket->hub_id }}

Puoi seguire la conversazione e rispondere da questo link personale (non condividerlo):
{{ $link }}

Grazie,
— Il supporto di {{ config('app.name') }}
