Ciao,

abbiamo restituito {{ $amount }} del pagamento che hai fatto il {{ $paidOn }} a {{ $orgName }}.

Motivo: {{ $reason }}

L'accredito compare sul tuo metodo di pagamento in genere entro pochi giorni lavorativi, fino a 10 a seconda della banca o del metodo di pagamento.

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
