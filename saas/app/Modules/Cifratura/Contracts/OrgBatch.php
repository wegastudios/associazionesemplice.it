<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use Generator;
use Illuminate\Support\Sleep;

/**
 * Giro di un comando su tutte le associazioni. Durante il giro le richieste al servizio chiavi vanno sulla corsia dei
 * comandi, che ha un tetto suo e non consuma quello delle associazioni, e tra un'associazione e l'altra c'è una pausa
 * (SAAS_KEY_BATCH_PAUSE_MS): con centinaia di associazioni il giro resta sotto il tetto invece di superarlo.
 *
 *     foreach ($batch->paced($ids) as $orgId) { ... }
 */
final class OrgBatch
{
    private int $depth = 0;

    public function __construct(private readonly int $pauseMs) {}

    /** Vero mentre un giro è in corso in questo processo. */
    public function active(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Gli identificativi (quelli non testuali si saltano), con la pausa prima di ognuno tranne il primo.
     *
     * @param  iterable<mixed>  $ids
     * @return Generator<int, string>
     */
    public function paced(iterable $ids): Generator
    {
        $this->depth++;
        try {
            $first = true;
            foreach ($ids as $id) {
                if (! is_string($id)) {
                    continue;
                }
                if (! $first && $this->pauseMs > 0) {
                    Sleep::for($this->pauseMs)->milliseconds();
                }
                $first = false;

                yield $id;
            }
        } finally {
            $this->depth--;
        }
    }
}
