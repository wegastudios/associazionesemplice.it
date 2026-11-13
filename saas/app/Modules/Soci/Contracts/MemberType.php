<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/**
 * Le figure gestite dall'associazione (dal MemberType del plugin).
 *
 * - fondatore: tessera sempre rinnovata, senza quota ogni anno;
 * - ordinario: tessera per anno sociale, rinnovata con la quota;
 * - volontario: come l'ordinario, e può tenere le attività;
 * - ospite di un socio: non è socio, partecipa tramite il socio che lo ospita;
 * - genitore o tutore: non è socio, segue i figli minori.
 */
enum MemberType: string
{
    case Founder = 'founder';
    case Ordinary = 'ordinary';
    case Volunteer = 'volunteer';
    case Guest = 'guest';
    case Guardian = 'guardian';

    public function label(): string
    {
        return match ($this) {
            self::Founder => 'Socio fondatore',
            self::Ordinary => 'Socio ordinario',
            self::Volunteer => 'Socio e volontario',
            self::Guest => 'Ospite di un socio',
            self::Guardian => 'Genitore o tutore (non socio)',
        };
    }

    public function isMember(): bool
    {
        return in_array($this, [self::Founder, self::Ordinary, self::Volunteer], true);
    }

    /**
     * Diritto di voto in assemblea (conteggi RUNTS): i soci sì, ospiti e genitori no. I minorenni
     * si escludono a parte, alla data del conteggio.
     */
    public function hasVotingRights(): bool
    {
        return $this->isMember();
    }

    public function canLogin(): bool
    {
        return $this->isMember() || $this === self::Guardian;
    }

    public function canTeach(): bool
    {
        return $this === self::Volunteer;
    }

    public function isAutoRenewed(): bool
    {
        return $this === self::Founder;
    }

    public function requiresHost(): bool
    {
        return $this === self::Guest;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
