<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Services;

use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Risorse\Models\Resource;

/**
 * IVA di una risorsa: l'aliquota con cui si registrano gli incassi delle prenotazioni e, se i prezzi sono «senza IVA»
 * (Aspetto fiscale), l'importo che la persona paga davvero (prezzo + IVA). Senza partita IVA, o con un'aliquota non più
 * in elenco, la risorsa si comporta come senza IVA.
 */
final readonly class ResourceVat
{
    public function __construct(private OrgFiscal $fiscal) {}

    /** L'aliquota da applicare: null = senza IVA; 0 = «IVA esente». */
    public function rate(Resource $resource): ?int
    {
        return $this->usable($resource->vat_rate);
    }

    /** Un'aliquota salvata si può usare solo se c'è la partita IVA e l'aliquota è ancora in elenco. */
    public function usable(?int $rate): ?int
    {
        if ($rate === null || ! $this->fiscal->hasVat() || ! $this->fiscal->allows($rate)) {
            return null;
        }

        return $rate;
    }

    /** Quanto paga la persona: il prezzo impostato, più l'IVA se i prezzi sono «senza IVA». */
    public function gross(Resource $resource, int $cents): int
    {
        $rate = $this->rate($resource);
        if ($rate === null || $rate === 0 || $this->fiscal->includesVat()) {
            return $cents;
        }

        return $cents + (int) round($cents * $rate / 100);
    }

    /** I prezzi sono «senza IVA» e la risorsa ha un'aliquota: l'importo mostrato è maggiorato. */
    public function addsVat(Resource $resource): bool
    {
        $rate = $this->rate($resource);

        return $rate !== null && $rate > 0 && ! $this->fiscal->includesVat();
    }
}
