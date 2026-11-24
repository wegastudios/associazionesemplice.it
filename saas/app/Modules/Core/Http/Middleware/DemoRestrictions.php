<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Demo\DemoLimits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nella demo gli accessi sono pubblici: chi li usa non deve poter cambiare ciò che resta uguale per tutti gli altri
 * (password e due passaggi dei titolari, email, nome degli utenti, operatori e ruoli, chiavi dei pagamenti, dati dell'ente,
 * chiusura dei conti) né riempirla di dati: limiti per indirizzo IP e tetti sul totale (DemoLimits), niente link né HTML
 * nei testi (finiscono nelle pagine pubbliche). Elenco delle azioni PERMESSE: ogni richiesta che scrive passa solo se il
 * percorso è qui sotto; tutto il resto, anche una rotta nuova di domani, risponde con la stessa frase. La lettura delle pagine
 * resta libera. I file in arrivo li ferma DemoFileBarrier. Fuori dalla demo non fa nulla.
 */
final class DemoRestrictions
{
    /** Percorsi (con * ) dove nella demo si può scrivere. */
    public const ALLOWED = [
        // Accesso e uscita (le azioni sulla password sono spente: si entra, non si cambia).
        'accesso', 'accesso/*', 'esci', 'associazioni/*',
        // Area soci: le azioni del socio dimostrativo e il modulo pubblico di adesione.
        'area/*', 'adesione/*',
        // Gestionale: il lavoro di ogni giorno.
        'gestionale/soci', 'gestionale/soci/*',
        'gestionale/tessere/*', 'gestionale/certificati/*',
        'gestionale/attivita', 'gestionale/attivita/*',
        'gestionale/risorse', 'gestionale/risorse/*',
        'gestionale/prenotazioni', 'gestionale/prenotazioni/*',
        'gestionale/eventi', 'gestionale/eventi/*',
        'gestionale/domande', 'gestionale/domande/*',
        'gestionale/conti', 'gestionale/conti/*',
        'gestionale/prima-nota', 'gestionale/prima-nota/*',
        'gestionale/ricevute/*',
        // Profilo: niente (nome compreso: gli utenti di prova sono gli stessi per tutti); la configurazione guidata si può rimandare, non salvare.
        'gestionale/configurazione/rimanda',
    ];

    /** Scritture spente anche se sotto un percorso permesso: i conti si creano e si muovono, non si chiudono né si riaprono. */
    public const CLOSED = ['gestionale/conti/*/chiudi', 'gestionale/conti/*/riapri', 'gestionale/conti/*/commissione'];

    /** Nomi dei campi che non si controllano (password e codici: non sono testi pubblici). */
    private const SECRET_FIELDS = ['password', 'current_password', 'password_confirmation', '_token', 'code', 'codice', 'recovery_code'];

    /** Tag HTML, indirizzi con schema («http://», «ftp://»…), «www.», «https:», «javascript:». */
    private const LINK_PATTERN = '/<\/?[a-z!?]|\b[a-z][a-z0-9+.\-]*:\/\/|\bwww\.|\bhttps?:|\bjavascript:/i';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoScale::restricted()) {
            return $next($request);
        }
        // L'amministrazione della piattaforma e lo staff non esistono nella demo pubblica.
        if ($request->is($this->platformPaths())) {
            abort(404);
        }
        // Lo scarico della copia di sicurezza pesa sul server condiviso: chiuso, in lettura e in scrittura.
        if ($request->is('gestionale/esportazione.zip', 'gestionale/esportazione/*')) {
            throw ValidationException::withMessages(['ledger' => DemoScale::BACKUP_OFF]);
        }
        if (! $request->isMethodSafe() && (! $request->is(self::ALLOWED) || $request->is(self::CLOSED))) {
            throw ValidationException::withMessages(['ledger' => DemoScale::CHANGE_OFF]);
        }
        // Frequenza per indirizzo IP e tetti sul totale.
        DemoLimits::enforce($request);
        if (! $request->isMethodSafe()) {
            $this->assertNoLinks($request);
        }

        return $next($request);
    }

    /** Nei testi che i visitatori scrivono (e che altri vedono, anche nelle pagine pubbliche) niente link né HTML. */
    private function assertNoLinks(Request $request): void
    {
        $found = false;
        $fields = $request->except(self::SECRET_FIELDS);
        array_walk_recursive($fields, static function (mixed $value) use (&$found): void {
            if (is_string($value) && preg_match(self::LINK_PATTERN, $value) === 1) {
                $found = true;
            }
        });
        if ($found) {
            throw ValidationException::withMessages(['ledger' => DemoScale::NO_LINKS]);
        }
    }

    /** @return list<string> */
    private function platformPaths(): array
    {
        $path = config('saas.admin_path', 'saas/admin');
        $path = trim(is_string($path) ? $path : 'saas/admin', '/');

        return [$path, $path.'/*', 'saas/staff/*'];
    }
}
