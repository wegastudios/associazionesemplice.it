<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Services;

use App\Modules\Attivita\Models\Activity;
use App\Modules\Core\Contracts\OrgFiscal;

/**
 * IVA di un'attività: l'aliquota con cui si registrano gli incassi e, se i prezzi sono «senza IVA» (Aspetto fiscale),
 * l'importo che la persona paga davvero (prezzo + IVA). Senza partita IVA, o con un'aliquota non più in elenco,
 * l'attività si comporta come senza IVA.
 */
final readonly class ActivityVat
{
    public function __construct(private OrgFiscal $fiscal) {}

    /** L'aliquota da applicare agli incassi: null = senza IVA; 0 = «IVA esente». */
    public function rate(Activity $activity): ?int
    {
        $rate = $activity->vat_rate;
        if ($rate === null || ! $this->fiscal->hasVat() || ! $this->fiscal->allows($rate)) {
            return null;
        }

        return $rate;
    }

    /** Quanto paga la persona: il prezzo impostato, più l'IVA se i prezzi sono «senza IVA». */
    public function gross(Activity $activity, int $cents): int
    {
        $rate = $this->rate($activity);
        if ($rate === null || $rate === 0 || $this->fiscal->includesVat()) {
            return $cents;
        }

        return $cents + (int) round($cents * $rate / 100);
    }

    /** I prezzi sono «senza IVA» e l'attività ha un'aliquota: l'importo mostrato è maggiorato. */
    public function addsVat(Activity $activity): bool
    {
        $rate = $this->rate($activity);

        return $rate !== null && $rate > 0 && ! $this->fiscal->includesVat();
    }
}
