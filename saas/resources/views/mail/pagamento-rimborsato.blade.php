Ciao,

ti abbiamo restituito {{ $amount }} del pagamento del {{ $paidOn }} a {{ $orgName }}.

Motivo: {{ $reason }}

L'accredito arriva di solito in pochi giorni lavorativi (fino a 10, secondo la banca o il metodo di pagamento).
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
