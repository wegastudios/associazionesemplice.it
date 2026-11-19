Ciao,

per entrare nell'area soci di {{ $orgName }} apri questo link:

{{ $link }}

oppure scrivi il codice {{ $code }} nella pagina di accesso.

Link e codice valgono {{ $minutes }} minuti e si usano una volta sola. Se non l'hai chiesto tu, ignora questa email.

—
{{ $orgName }}
@if ($contactEmail && $contactPhone)
Segreteria: {{ $contactEmail }} · tel. {{ $contactPhone }}
@endif
