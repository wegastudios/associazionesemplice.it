<?php

declare(strict_types=1);

namespace App\Modules\Core\Demo;

use App\Modules\Core\Contracts\DemoScale;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Nella demo girano solo i lavori che lavorano sul suo database e sui suoi file (docs/DEMO.md): niente backup, servizio
 * chiavi, promemoria, ciclo di vita, portale, pagamenti né altro che parli con l'esterno. Elenco dei permessi, non dei
 * divieti: un lavoro nuovo di domani resta spento nella demo finché non lo si mette qui (e la prova sullo schedule lo dice).
 */
final class DemoSchedule
{
    /** Pezzi di comando dei lavori che nella demo girano. */
    public const ALLOWED = [
        'queue:work',                      // lavoratori delle code: solo lavori interni (la posta è scartata, vedi DemoMailGuard)
        'queue:prune-failed',              // pulizia dei lavori falliti
        'saas:tmp:purge',                  // file di lavoro temporanei
        'saas:demo:reset',                 // azzeramento notturno alle 03:30
        'saas:audit:partitions',           // partizioni del registro del suo database
        'saas:audit:prune-security',       // pulizia del registro di sicurezza del suo database
        'saas:receipts:fix',               // ricevute del suo database
        'saas:applications:prune',         // domande di adesione scadute
        'saas:member-access:prune',        // link e sessioni scaduti dell'area soci
        'saas:files:usage',                // contatore dello spazio sul suo disco
        'saas:crypt:upgrade-format:prune', // copie d'appoggio interne
    ];

    /** Lavori che nella demo NON girano, con il motivo (per DEMO.md e per la prova). */
    public const OFF = [
        'saas:night' => 'controlli notturni e copia delle teste del registro: inutili su dati che si azzerano ogni notte',
        'saas:backup:dump' => 'copie di sicurezza: nessun backup, nessun disco esterno',
        'saas:backup:prune' => 'copie di sicurezza',
        'saas:backup:restore-test' => 'copie di sicurezza',
        'saas:keys:monitor' => 'servizio chiavi: la demo usa la chiave locale',
        'saas:reminders:send' => 'promemoria ai soci: nessuna posta',
        'saas:payments:requeue-webhooks' => 'pagamenti online: nessun Stripe',
        'saas:audit:prune' => 'potatura del registro',
        'saas:portal:usage' => 'portale: nessun collegamento',
        'saas:portal:suspend-expired' => 'ciclo di vita: nessun collegamento col portale',
        'saas:portal:purge-expired' => 'ciclo di vita',
        'saas:portal:lifecycle-notices' => 'ciclo di vita: avvisi',
        'saas:portal:verify-domains' => 'domini personalizzati: verifica verso l\'esterno',
    ];

    public static function allowed(?string $command): bool
    {
        if ($command === null) {
            return false;
        }
        foreach (self::ALLOWED as $part) {
            if (str_contains($command, $part)) {
                return true;
            }
        }

        return false;
    }

    /** Spegne ogni lavoro non ammesso (con la demo accesa e fuori dalla produzione); gli altri restano come sono. */
    public static function restrict(Schedule $schedule): void
    {
        if (! DemoScale::restricted()) {
            return;
        }
        foreach ($schedule->events() as $event) {
            if (! self::allowed($event->command)) {
                $event->skip(true);
            }
        }
    }
}
