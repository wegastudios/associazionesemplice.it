Ciao,

ti sono stati restituiti {{ $amount }} del pagamento che hai fatto il {{ $paidOn }} a {{ $orgName }}.

Motivo: {{ $reason }}

L'accredito arriva sullo stesso metodo di pagamento, di solito in pochi giorni lavorativi (fino a 10, secondo la banca).
@foreach ($stays as $line)

{{ $line }}
@endforeach

@if ($contactEmail && $contactPhone)
Se hai dubbi, scrivi alla segreteria: {{ $contactEmail }}, tel. {{ $contactPhone }}.
@else
Se hai dubbi, scrivi alla segreteria.
@endif

—
{{ $orgName }}
@if ($contactEmail && $contactPhone)
Segreteria: {{ $contactEmail }} · tel. {{ $contactPhone }}
@endif
