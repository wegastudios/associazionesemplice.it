<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Ogni modulo dice alla Home cosa c'è da fare e cosa succede oggi, per quello che l'operatore può vedere. I moduli si
 * registrano con il tag HomeWidget::TAG; la Home raccoglie le voci di tutti.
 */
interface HomeWidget
{
    public const TAG = 'saas.home-widgets';

    /** @return list<HomeItem> */
    public function items(Operator $operator): array;
}
