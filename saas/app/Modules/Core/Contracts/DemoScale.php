<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use DateTimeImmutable;

/**
 * Quanto è grande la demo e in che anni vive. Gli anni fiscali e sociali della demo vanno da settembre ad agosto (come la
 * demo del plugin): «anno 2025» è settembre 2025 – agosto 2026. Le date non finiscono mai nel futuro.
 */
final class DemoScale
{
    /** Mese di inizio dell'anno fiscale e sociale della demo. */
    public const START_MONTH = 9;

    /** Quante persone fisiche ha l'associazione dimostrativa (SAAS_DEMO_PEOPLE). */
    public static function people(): int
    {
        $n = config('saas.demo.people');

        return is_numeric($n) ? max(12, min(1000, (int) $n)) : 400;
    }

    /** Anno (di inizio) dell'anno fiscale in corso alla data indicata o a oggi. */
    public static function currentYear(?string $today = null): int
    {
        $d = new DateTimeImmutable($today ?? now()->toDateString());

        return (int) $d->format('Y') - ((int) $d->format('n') < self::START_MONTH ? 1 : 0);
    }

    /** Giorno «$days» giorni dopo l'inizio dell'anno fiscale $year, mai oltre oggi. */
    public static function day(int $year, int $days): string
    {
        $d = (new DateTimeImmutable(sprintf('%d-%02d-01', $year, self::START_MONTH)))->modify('+'.max(0, $days).' days');
        $today = new DateTimeImmutable(now()->toDateString());

        return ($d > $today ? $today : $d)->format('Y-m-d');
    }

    /** La frase a schermo e nell'errore, dove un visitatore si aspetta di poter caricare un file. */
    public const UPLOADS_OFF = 'Nella demo il caricamento dei file è spento.';

    /** La frase a schermo quando nella demo si prova una modifica che toccherebbe tutti i visitatori. */
    public const CHANGE_OFF = 'Nella demo questa modifica è spenta: nell\'associazione vera funziona.';

    /** Nella demo (mai in produzione) gli accessi sono pubblici: le modifiche che restano per tutti sono spente. */
    public static function restricted(): bool
    {
        return self::uploadsOff();
    }

    /** Nella demo (mai in produzione) i visitatori non caricano file: è pubblica e i file finirebbero sul disco del server. */
    public static function uploadsOff(): bool
    {
        return (bool) config('saas.demo.enabled') && ! app()->environment('production');
    }
}
