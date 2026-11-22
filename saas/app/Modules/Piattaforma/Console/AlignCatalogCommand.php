<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use App\Modules\Piattaforma\Catalogo\DbCatalog;
use Illuminate\Console\Command;

/**
 * Allinea il catalogo in vigore alla configurazione (giorni di conservazione): se i numeri sono cambiati scrive la
 * revisione nuova e avvisa il portale. Gira a ogni rilascio, dopo le migrazioni; la lettura del catalogo non scrive mai.
 */
final class AlignCatalogCommand extends Command
{
    protected $signature = 'saas:catalog:align';

    protected $description = 'Allinea il catalogo in vigore alla configurazione (giorni di conservazione), con una revisione nuova se serve';

    public function handle(DbCatalog $catalog): int
    {
        $before = $catalog->published()->rev;
        $after = $catalog->align()->rev;
        $this->line($after === $before ? "Catalogo già allineato (revisione {$after})." : "Catalogo allineato: revisione {$before} -> {$after}.");

        return self::SUCCESS;
    }
}
