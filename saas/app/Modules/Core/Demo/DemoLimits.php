<?php

declare(strict_types=1);

namespace App\Modules\Core\Demo;

use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Core\Contracts\CurrentOrg;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Limiti della demo pubblica (docs/DEMO.md): gli accessi sono di tutti, quindi nessuno deve poterla riempire.
 * Frequenza PER INDIRIZZO IP (non per utente: i cinque utenti di prova sono condivisi) e tetti sul totale dei dati.
 * I tetti sono «quanto c'era a fine azzeramento + margine» (saas.demo.limits.*_extra): i dati di partenza non li superano mai.
 * Tutto vale solo con la demo attiva (DemoScale::active()); chi lo chiama lo controlla. Superato un limite: errore 422
 * con la frase DemoScale::LIMIT_OFF, nella stessa forma delle altre restrizioni della demo.
 */
final class DemoLimits
{
    /** Prefisso delle impostazioni dove l'azzeramento scrive i conteggi di partenza. */
    public const BASELINE = 'demo.baseline.';

    /** Percorsi su cui non si conta niente: accesso, uscita, cambio associazione. */
    private const UNCOUNTED = ['accesso', 'accesso/*', 'esci', 'conferma-password', 'associazioni/*', 'area/accesso', 'area/accesso/*', 'area/entra/*', 'area/esci', 'area/esci-ovunque', 'area/scheda/*'];

    /** Percorsi POST che non aggiungono dati: non contano come creazioni (contano come scritture). */
    private const NOT_CREATIONS = ['gestionale/configurazione/rimanda'];

    /**
     * Cosa si conta per ogni tetto: le tabelle e i percorsi POST che aggiungono righe. Il tetto è
     * «conteggio di partenza» + saas.demo.limits.<chiave>_extra.
     *
     * @var array<string, array{tables: list<string>, paths: list<string>}>
     */
    private const CAPS = [
        'people' => ['tables' => ['app.people'], 'paths' => ['gestionale/soci', 'gestionale/domande/*/accogli']],
        'applications' => ['tables' => ['app.applications'], 'paths' => ['adesione/*']],
        'movements' => ['tables' => ['app.transactions'], 'paths' => [
            'gestionale/prima-nota/incassi', 'gestionale/prima-nota/spese', 'gestionale/prima-nota/giroconti',
            'gestionale/attivita/*/incassi', 'gestionale/attivita/prenotazioni/*/incassa', 'gestionale/attivita/*/iscrizioni',
            'gestionale/attivita/*/spese', 'gestionale/prenotazioni/*/incassa', 'gestionale/soci/*/tessera',
        ]],
        'receipts' => ['tables' => ['app.receipts'], 'paths' => [
            'gestionale/prima-nota/incassi', 'gestionale/attivita/*/incassi', 'gestionale/attivita/prenotazioni/*/incassa', 'gestionale/attivita/*/iscrizioni',
            'gestionale/attivita/*/spese', 'gestionale/prenotazioni/*/incassa', 'gestionale/soci/*/tessera',
        ]],
        'bookings' => ['tables' => ['app.reservations', 'app.session_bookings'], 'paths' => [
            'gestionale/risorse/*/prenotazioni', 'gestionale/risorse/*/blocchi', 'gestionale/attivita/date/*/prenota',
            'area/eventi/*/prenota', 'area/risorse/*/prenota',
        ]],
        'activities' => ['tables' => ['app.activities'], 'paths' => ['gestionale/attivita']],
        'resources' => ['tables' => ['app.resources'], 'paths' => ['gestionale/risorse']],
    ];

    /**
     * Tutti i percorsi POST che hanno un tetto (per la prova che elenca le rotte di creazione).
     *
     * @return list<string>
     */
    public static function capPaths(): array
    {
        return array_values(array_unique(array_merge(...array_column(self::CAPS, 'paths'))));
    }

    /**
     * Quante righe ha ora ogni voce con un tetto, nell'associazione collegata a $connection (con l'associazione già impostata).
     *
     * @return array<string, int>
     */
    public static function counts(string $connection): array
    {
        $db = DB::connection($connection);
        $out = [];
        foreach (self::CAPS as $key => $cap) {
            $n = 0;
            foreach ($cap['tables'] as $table) {
                $n += $db->table($table)->count();
            }
            $out[$key] = $n;
        }

        return $out;
    }

    /** Applica i limiti di frequenza e i tetti a una richiesta della demo. @throws ValidationException */
    public static function enforce(Request $request): void
    {
        $ip = $request->ip() ?? 'ignoto';
        self::routeThrottles($request, $ip);
        if ($request->isMethodSafe() || $request->is(self::UNCOUNTED)) {
            return;
        }

        self::hit('writes', $ip, DemoScale::limit('writes_per_hour'), 3600);
        if ($request->isMethod('POST') && ! $request->is(self::NOT_CREATIONS)) {
            self::hit('creations', $ip, DemoScale::limit('creations_per_hour'), 3600);
            self::assertRoom($request);
        }
    }

    /**
     * I limiti «throttle:N,M» delle rotte sono per utente e i cinque utenti sono condivisi: nella demo valgono anche per indirizzo IP.
     * I limiti con nome (throttle:donations …) hanno già la loro chiave.
     */
    private static function routeThrottles(Request $request, string $ip): void
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return;
        }
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^throttle:(\d+),(\d+)/', $middleware, $m) === 1) {
                self::hit('route:'.$route->uri().':'.implode(',', $route->methods()), $ip, (int) $m[1], max(1, (int) $m[2]) * 60);
            }
        }
    }

    /** Un colpo per questo indirizzo e questo gruppo; oltre $max nella finestra, rifiuto. */
    private static function hit(string $bucket, string $ip, int $max, int $decaySeconds): void
    {
        $key = 'demo-limit|'.$bucket.'|'.$ip;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['ledger' => DemoScale::LIMIT_OFF]);
        }
        RateLimiter::hit($key, $decaySeconds);
    }

    /** I tetti sul totale e lo spazio dei file: solo per le richieste che aggiungono dati. */
    private static function assertRoom(Request $request): void
    {
        $slug = config('saas.demo.org_slug');
        $orgId = DB::table('core.orgs')->where('slug', is_string($slug) ? $slug : 'polisportiva-demo')->value('id');
        if (! is_string($orgId)) {
            return;
        }
        $keys = [];
        foreach (self::CAPS as $key => $cap) {
            if ($request->is($cap['paths'])) {
                $keys[] = $key;
            }
        }

        app(OrgContext::class)->run($orgId, static function (CurrentOrg $org) use ($keys): void {
            $settings = app(OrgSettings::class);
            $counts = $keys === [] ? [] : self::counts($org->connection);
            foreach ($keys as $key) {
                if (($counts[$key] ?? 0) >= $settings->int(self::BASELINE.$key, 0) + DemoScale::limit($key.'_extra')) {
                    throw ValidationException::withMessages(['ledger' => DemoScale::LIMIT_OFF]);
                }
            }
            if (app(EncryptedFiles::class)->usedBytes($org->id) >= DemoScale::storageBytes()) {
                throw ValidationException::withMessages(['ledger' => DemoScale::LIMIT_OFF]);
            }
        });
    }
}
