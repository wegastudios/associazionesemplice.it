Ciao {{ $ticket->customer_name }},

{{ $reply->author_name }} ha risposto al tuo ticket {{ $ticket->hub_id }} ("{{ $ticket->subject }}").

Leggi la risposta e continua la conversazione da questo link personale (non condividerlo):
{{ $link }}

{{ config('app.name') }}
