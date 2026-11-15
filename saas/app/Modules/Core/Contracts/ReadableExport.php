<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Closure;

/**
 * Documenti leggibili della copia di sicurezza (PDF e tabelle che si aprono senza programma), forniti dal modulo a cui
 * appartengono. Ogni file si produce solo quando serve (una chiusura), così la copia non tiene tutto in memoria; una
 * chiusura che ritorna null salta quel file. I percorsi sono dentro «Documenti leggibili».
 */
interface ReadableExport
{
    public const TAG = 'saas.readable-export';

    /**
     * @return array<string, Closure(): (string|null)> percorso nella cartella dei documenti leggibili => contenuto
     */
    public function files(Operator $operator, bool $withCertificates, ?int $only = null): array;

    /** Quanti file «pesanti» (ricevute, certificati) metterebbe nella copia: serve a non cominciare una copia che non finirebbe. */
    public function weight(Operator $operator, bool $withCertificates, ?int $only = null): int;
}
