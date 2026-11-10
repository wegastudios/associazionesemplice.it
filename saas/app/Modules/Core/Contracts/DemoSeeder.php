<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Dati finti di un modulo per la demo (`saas:demo:reset`, docs/PIATTAFORMA.md §6). Ogni modulo riempie le proprie tabelle
 * dentro l'associazione di fantasia; il comando li chiama dopo averla creata, con l'associazione già impostata.
 *
 * Solo dati inventati e riconoscibilmente falsi: mai dati veri.
 */
interface DemoSeeder
{
    public const TAG = 'saas.demo-seeders';

    /** Ordine di esecuzione (chi dipende dai dati di altri viene dopo). */
    public function order(): int;

    public function seed(string $orgId): void;
}
