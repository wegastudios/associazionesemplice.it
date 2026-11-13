<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** Quanti utenti con il ruolo Dipendente ha l'associazione corrente (suggerisce il numero di lavoratori del RUNTS). */
interface StaffCount
{
    public function employees(): int;
}
