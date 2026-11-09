<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

/** Quando le attività occupano una risorsa, per chi la prenota o la collega. */
interface ActivityOccupancy
{
    /**
     * Le date di attività (non annullate, non archiviate, con orario) che occupano la risorsa nell'intervallo
     * [$from, $to) (testo «Y-m-d H:i:s»). Con «occupa solo se prenotata» contano solo le date con prenotazioni valide.
     *
     * @return list<array{id: string, from: string, to: string, label: string}>
     */
    public function busy(string $resourceId, string $from, string $to, ?string $exceptSessionId = null): array;
}
