<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** Operatore della richiesta corrente; lo imposta la middleware del gestionale dopo l'accesso. */
interface CurrentOperator
{
    public function get(): ?Operator;

    public function set(?Operator $operator): void;
}
