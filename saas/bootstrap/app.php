<?php

declare(strict_types=1);

use App\Modules\Core\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Core\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Modules\Core\Http\Middleware\HandleInertiaRequests;
use App\Modules\Core\Http\Middleware\IdentifyOperatorOrg;
use App\Modules\Core\Http\Middleware\ResetOrgContext;
use App\Modules\Core\Http\Middleware\SecurityHeaders;
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
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->encryptCookies();
        $middleware->redirectGuestsTo('/accesso');
        $middleware->redirectUsersTo('/gestionale');

        $middleware->alias([
            'two-factor' => EnsureTwoFactorConfirmed::class,
            'operator' => IdentifyOperatorOrg::class,
            'platform-admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
