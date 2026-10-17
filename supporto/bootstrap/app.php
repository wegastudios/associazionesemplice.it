<?php

use App\Exceptions\SupportException;
use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SiteAuthenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [CorrelationId::class]);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'site.auth' => SiteAuthenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Rifiuti normali (401, 403, 404, 409, 422, 429) non sono guasti: niente errori nel log
        $exceptions->reportable(function (SupportException $e) {
            if ($e->status < 500) {
                return false;
            }
        });

        $exceptions->render(function (SupportException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return $e->toResponse();
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Troppe richieste: riprova tra poco.',
                    'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : null,
                ],
            ], 429, $e->getHeaders());
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'I dati inviati non sono validi.',
                    'fields' => $e->errors(),
                    'correlation_id' => app()->bound('correlation_id') ? app('correlation_id') : null,
                ],
            ], 422);
        });
    })->create();
