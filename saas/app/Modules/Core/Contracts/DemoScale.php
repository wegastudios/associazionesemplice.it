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

    /** La frase a schermo quando nella demo si prova a scaricare la copia di sicurezza. */
    public const BACKUP_OFF = 'Nella demo la copia di sicurezza non si scarica: nell\'associazione vera funziona.';

    /** La frase a schermo e nell'errore quando nella demo si supera un limite di frequenza o di quantità. */
    public const LIMIT_OFF = 'Nella demo hai raggiunto il limite: si azzera ogni notte alle 03:30.';

    /** La frase a schermo quando nella demo un testo contiene un link o dell'HTML. */
    public const NO_LINKS = 'Nella demo i testi non possono contenere link né HTML.';

    /** Ambiente in cui la demo può esistere. */
    public const ENVIRONMENT = 'demo';

    /**
     * Valori di partenza dei limiti (`saas.demo.limits.*` li sostituisce). «*_extra» è quanto i visitatori possono aggiungere
     * a ciò che l'azzeramento notturno ha scritto: i dati di partenza non superano mai un tetto.
     */
    public const LIMITS = [
        'creations_per_hour' => 40,   // creazioni (ogni POST che aggiunge dati) per indirizzo IP, in un'ora
        'writes_per_hour' => 200,     // tutte le scritture per indirizzo IP, in un'ora
        'people_extra' => 100,        // soci e altre persone
        'applications_extra' => 50,   // domande di adesione
        'movements_extra' => 200,     // movimenti di prima nota (incassi, spese, giroconti)
        'receipts_extra' => 200,      // ricevute (ognuna è un PDF cifrato)
        'bookings_extra' => 150,      // prenotazioni e iscrizioni a date
        'activities_extra' => 30,     // attività
        'resources_extra' => 20,      // risorse prenotabili
        'storage_mb' => 300,          // spazio totale dei file dell'associazione
    ];

    /** Un limite della demo: da `saas.demo.limits.<chiave>`, altrimenti il valore di partenza. */
    public static function limit(string $key): int
    {
        $value = config('saas.demo.limits.'.$key);

        return is_numeric($value) ? max(0, (int) $value) : (self::LIMITS[$key] ?? 0);
    }

    /** Spazio massimo dei file nella demo, in byte. */
    public static function storageBytes(): int
    {
        return self::limit('storage_mb') * 1024 ** 2;
    }

    /**
     * La demo è attiva: SAAS_DEMO acceso E ambiente «demo». È l'unico punto che lo decide: con un altro ambiente (staging,
     * locale, produzione) nessun accesso pubblico, nessuna password di prova, nessuna restrizione, nessun azzeramento.
     */
    public static function active(): bool
    {
        return filter_var(config('saas.demo.enabled'), FILTER_VALIDATE_BOOLEAN) && app()->environment(self::ENVIRONMENT);
    }

    /** Nella demo gli accessi sono pubblici: le modifiche che restano per tutti sono spente. */
    public static function restricted(): bool
    {
        return self::active();
    }

    /** Nella demo i visitatori non caricano file: è pubblica e i file finirebbero sul disco del server. */
    public static function uploadsOff(): bool
    {
        return self::active();
    }
}
