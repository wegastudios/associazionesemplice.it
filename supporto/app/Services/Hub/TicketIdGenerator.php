<?php

namespace App\Services\Hub;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Id dei ticket: anonimo (nessun prefisso, nessun riferimento al portale) e crescente tra tutti i portali.
 * Sono i secondi trascorsi dall'epoca (27 dicembre 2023, UTC) ma mai meno di "ultimo id + 1":
 * due richieste nello stesso secondo ricevono numeri consecutivi e l'id non si ripete mai.
 */
class TicketIdGenerator
{
    /** Va chiamato dentro la transazione che crea il ticket: la riga del contatore resta bloccata fino al commit. */
    public function next(?CarbonImmutable $now = null): int
    {
        return DB::transaction(function () use ($now) {
            $last = (int) DB::table('ticket_counters')->where('id', 1)->lockForUpdate()->value('last_id');

            $next = max($this->secondsSinceEpoch($now), $last + 1);

            DB::table('ticket_counters')->where('id', 1)->update(['last_id' => $next]);

            return $next;
        });
    }

    public function secondsSinceEpoch(?CarbonImmutable $now = null): int
    {
        $epoch = CarbonImmutable::parse((string) config('support.ticket_epoch'), 'UTC')->getTimestamp();
        $now ??= CarbonImmutable::now('UTC');

        return max(1, $now->getTimestamp() - $epoch);
    }
}
