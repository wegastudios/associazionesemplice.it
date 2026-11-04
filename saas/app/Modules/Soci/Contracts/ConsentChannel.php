<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/**
 * Consensi per persona e per canale (dal ConsentRules del plugin, più privacy, regolamento e immagini che nel plugin
 * stavano nella scheda). Ognuno si dà e si revoca; resta la storia di ogni passaggio.
 */
enum ConsentChannel: string
{
    case Privacy = 'privacy';
    case Rules = 'rules';
    case Images = 'images';
    case Sms = 'sms';
    case Newsletter = 'newsletter';

    public function label(): string
    {
        return match ($this) {
            self::Privacy => 'Informativa sulla privacy',
            self::Rules => 'Regolamento',
            self::Images => 'Uso delle immagini',
            self::Sms => 'Messaggi SMS',
            self::Newsletter => 'Newsletter',
        };
    }

    /** Frase per l'interessato, accanto alla casella. */
    public function notice(string $association): string
    {
        $who = trim($association) !== '' ? $association : "l'associazione";

        return match ($this) {
            self::Privacy => "Ho letto l'informativa sul trattamento dei dati personali di {$who}.",
            self::Rules => "Accetto il regolamento di {$who}.",
            self::Images => "Acconsento all'uso di foto e video in cui compaio, per raccontare le attività di {$who}.",
            self::Sms => "Acconsento a ricevere SMS da {$who} (avvisi, scadenze, comunicazioni ai soci). Posso revocare il consenso in qualsiasi momento.",
            self::Newsletter => "Acconsento a ricevere la newsletter di {$who} all'indirizzo email indicato. Posso revocare il consenso in qualsiasi momento.",
        };
    }
}
