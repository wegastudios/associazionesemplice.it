<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

/** Le attività dell'associazione corrente, per chi le usa come centri di costo (la prima nota). */
interface Activities
{
    /** @return list<array{id: string, name: string}> attività non archiviate, dalla più recente */
    public function options(): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, string> id => nome
     */
    public function names(array $ids): array;
}
