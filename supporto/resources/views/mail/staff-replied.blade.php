{{ filled($ticket->customer_name) ? 'Ciao '.$ticket->customer_name.',' : 'Buongiorno,' }}

{{ $reply->author_name }} ha risposto alla tua richiesta n. {{ $ticket->hub_id }} ("{{ $ticket->subject }}").

Leggi la risposta e continua la conversazione da questo link personale (non condividerlo):
{{ $link }}

— Il supporto di {{ config('app.name') }}
