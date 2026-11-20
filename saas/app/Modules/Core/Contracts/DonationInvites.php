<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * L'invito a donare, nei quattro posti che l'associazione accende uno a uno (tutto spento di default): pagina di
 * eventi e corsi, conferma dopo l'iscrizione, area soci, piede delle email ai soci. Compare solo se c'è un modo per
 * donare (spunta al pagamento accesa e almeno un metodo di pagamento attivo).
 */
interface DonationInvites
{
    public const EVENT = 'event';

    public const CONFIRM = 'confirm';

    public const AREA = 'area';

    public const EMAIL = 'email';

    public const SPOTS = [self::EVENT, self::CONFIRM, self::AREA, self::EMAIL];

    /** L'invito va mostrato in questo posto? */
    public function enabled(string $spot): bool;
}
