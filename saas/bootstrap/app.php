<?php

declare(strict_types=1);

use App\Modules\Core\Http\DatabaseBusy;
use App\Modules\Core\Http\Middleware\CloseDemoPaths;
use App\Modules\Core\Http\Middleware\DemoFileBarrier;
use App\Modules\Core\Http\Middleware\DemoRestrictions;
use App\Modules\Core\Http\Middleware\EnforceSessionLimits;
use App\Modules\Core\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Modules\Core\Http\Middleware\EnsureUserActive;
use App\Modules\Core\Http\Middleware\HandleInertiaRequests;
use App\Modules\Core\Http\Middleware\IdentifyOperatorOrg;
use App\Modules\Core\Http\Middleware\LongDatabaseTimeouts;
use App\Modules\Core\Http\Middleware\MigrateHostCookies;
use App\Modules\Core\Http\Middleware\ResetOrgContext;
use App\Modules\Core\Http\Middleware\ResolveProxyClient;
use App\Modules\Core\Http\Middleware\RestrictToAppHost;
use App\Modules\Core\Http\Middleware\SecurityHeaders;
use App\Modules\Core\Http\Middleware\ThrottleAccountSecurity;
use App\Modules\Core\Http\Middleware\UseCookieSession;
use AppModulesCoreHttpTooManyAttempts;
use App\Modules\Piattaforma\Http\Middleware\UsePlatformSession;
use App\Modules\Piattaforma\Http\Middleware\UsePublicStaffUrl;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use SymfonyComponentHttpKernelxceptionTooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ogni richiesta parte senza associazione e la azzera alla fine.
        $middleware->prepend(ResetOrgContext::class);
        // Accesso, gestionale, amministrazione e API solo sugli indirizzi dell'app: sugli altri host solo area soci e moduli pubblici.
        $middleware->prepend(RestrictToAppHost::class);
        // Demo: portale, staff, amministrazione, API e Stripe chiusi (404).
        $middleware->prepend(CloseDemoPaths::class);
        // Demo: nessun file in arrivo da nessuna rotta (422), senza elenchi di percorsi.
        $middleware->prepend(DemoFileBarrier::class);
        // Indirizzo del visitatore dietro il proxy del portale: solo con il segreto condiviso (va prima dei limiti di frequenza).
        $middleware->prepend(ResolveProxyClient::class);
        // Le pagine di /saas/admin e /saas/staff scrivono gli indirizzi del portale (SAAS_PUBLIC_URL), non quello dell'app.
        $middleware->prepend(UsePublicStaffUrl::class);
        $middleware->append(SecurityHeaders::class);

        // Sotto il percorso dell'amministrazione la sessione ha cookie, percorso e guardia propri: va prima di tutto.
        // Area soci e moduli pubblici (rotte con il marchio «cookie-session»): sessione nel cookie, non nel database (H19).
        // Cookie con prefisso __Host- (docs/COOKIE_HOST.md): MigrateHostCookies, dopo la cifratura dei
        // cookie e prima di chi legge l'utente, porta i vecchi «ricordami» e as_socio ai nomi nuovi durante la finestra di passaggio.
        $middleware->web(
            prepend: [UsePlatformSession::class, UseCookieSession::class],
            append: [MigrateHostCookies::class, EnforceSessionLimits::class, ThrottleAccountSecurity::class, EnsureUserActive::class, DemoRestrictions::class, HandleInertiaRequests::class],
        );
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: UsePlatformSession::class);
        // Il passaggio dei vecchi cookie va subito dopo la decifratura e prima di tutto ciò che legge l'utente (anche della
        // rotta «auth», che Laravel porta prima di SubstituteBindings): senza questa riga starebbe in fondo al gruppo.
        $middleware->appendToPriorityList(after: EncryptCookies::class, append: MigrateHostCookies::class);
        $middleware->encryptCookies();
        $middleware->redirectGuestsTo('/accesso');
        $middleware->redirectUsersTo('/gestionale');

        $middleware->alias([
            'two-factor' => EnsureTwoFactorConfirmed::class,
            'operator' => IdentifyOperatorOrg::class,
            'db.long' => LongDatabaseTimeouts::class,
            UseCookieSession::MARKER => UseCookieSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(DatabaseBusy::wantsJson(...));
        // Blocco o istruzione scaduti (saas.database_timeouts): «riprova tra poco», non l'errore generico.
        $exceptions->report(static fn (QueryException $e): bool => DatabaseBusy::report($e));
        $exceptions->render(static fn (QueryException $e, Request $request) => DatabaseBusy::render($e, $request));
        // Limite di frequenza: avviso nella pagina al posto del riquadro d'errore.
        $exceptions->render(static fn (TooManyRequestsHttpException $e, Request $request) => TooManyAttempts::render($e, $request));
    })->create();
