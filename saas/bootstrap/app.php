<?php

declare(strict_types=1);

use App\Modules\Core\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Modules\Core\Http\Middleware\EnsureUserActive;
use App\Modules\Core\Http\Middleware\HandleInertiaRequests;
use App\Modules\Core\Http\Middleware\IdentifyOperatorOrg;
use App\Modules\Core\Http\Middleware\ResetOrgContext;
use App\Modules\Core\Http\Middleware\SecurityHeaders;
use App\Modules\Piattaforma\Http\Middleware\UsePlatformSession;
use App\Modules\Piattaforma\Http\Middleware\UsePublicStaffUrl;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ogni richiesta parte senza associazione e la azzera alla fine.
        $middleware->prepend(ResetOrgContext::class);
        // Le pagine di /saas/admin e /saas/staff scrivono gli indirizzi del portale (SAAS_PUBLIC_URL), non quello dell'app.
        $middleware->prepend(UsePublicStaffUrl::class);
        $middleware->append(SecurityHeaders::class);

        // Sotto il percorso dell'amministrazione la sessione ha cookie, percorso e guardia propri: va prima di tutto.
        $middleware->web(prepend: [UsePlatformSession::class], append: [EnsureUserActive::class, HandleInertiaRequests::class]);
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: UsePlatformSession::class);
        $middleware->encryptCookies();
        $middleware->redirectGuestsTo('/accesso');
        $middleware->redirectUsersTo('/gestionale');

        $middleware->alias([
            'two-factor' => EnsureTwoFactorConfirmed::class,
            'operator' => IdentifyOperatorOrg::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
