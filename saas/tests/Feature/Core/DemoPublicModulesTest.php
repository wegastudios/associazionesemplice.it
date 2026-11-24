<?php

declare(strict_types=1);

use App\Modules\Core\Http\Middleware\DemoRestrictions;
use App\Modules\Core\Http\Middleware\UseCookieSession;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/*
 * Moduli aperti a chiunque (adesione, donazione, area soci): limiti di frequenza su ogni invio, nessun file in arrivo,
 * nessuna scrittura che sopravviva all'azzeramento notturno (il database si ricrea, la cartella dei file si svuota).
 */

it('ogni invio dei moduli pubblici ha un limite di frequenza o uno proprio nel codice dell\'accesso', function (): void {
    // L'accesso dell'area soci (codice, link) conta i tentativi nel controller e nel servizio (RateLimiter).
    $ownLimiter = ['member.login.request', 'member.login.code', 'member.login.link.enter', 'member.switch', 'member.logout', 'member.logout.all'];
    $unlimited = [];
    $seen = 0;
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array(UseCookieSession::MARKER, $route->gatherMiddleware(), true) || array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }
        $seen++;
        $name = (string) $route->getName();
        $limited = collect($route->gatherMiddleware())->contains(static fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'));
        $name = (string) preg_replace('/^custom\./', '', $name);
        if (! $limited && ! in_array($name, $ownLimiter, true)) {
            $unlimited[] = $route->uri();
        }
    }

    expect($seen)->toBeGreaterThan(8)->and($unlimited)->toBe([]);
});

it('nessuna rotta dei moduli pubblici ha un controllo che riceve file', function (): void {
    $sources = '';
    foreach (['AreaSoci', 'Soci'] as $module) {
        foreach (glob(base_path("app/Modules/$module/Http/Controllers/Public*.php")) ?: [] as $file) {
            $sources .= (string) file_get_contents($file);
        }
    }
    foreach (glob(base_path('app/Modules/AreaSoci/Http/Controllers/*.php')) ?: [] as $file) {
        $sources .= (string) file_get_contents($file);
    }

    expect($sources)->not->toContain('request->file(')->not->toContain('hasFile(')->not->toContain('allFiles')->not->toContain('UploadedFile');
});

it('nella demo un file in arrivo su una rotta pubblica è rifiutato, anche se il controller lo ignorerebbe', function (): void {
    config(['saas.demo.enabled' => true]);
    $route = Route::getRoutes()->getByName('application.submit');
    expect($route)->toBeInstanceOf(RouteInstance::class);

    $request = Request::create('/adesione/demo', 'POST', [], [], ['allegato' => UploadedFile::fake()->create('carta.pdf', 10)]);
    $request->setRouteResolver(static fn () => $route);
    $refused = false;
    try {
        (new DemoRestrictions)->handle($request, static fn () => response('ok'));
    } catch (ValidationException) {
        $refused = true;
    }

    expect($refused)->toBeTrue();
});

it('fuori dalla demo lo stesso invio non è fermato dal guardiano', function (): void {
    config(['saas.demo.enabled' => false]);
    $route = Route::getRoutes()->getByName('application.submit');
    $request = Request::create('/adesione/demo', 'POST', [], [], ['allegato' => UploadedFile::fake()->create('carta.pdf', 10)]);
    $request->setRouteResolver(static fn () => $route);

    expect((new DemoRestrictions)->handle($request, static fn () => response('ok'))->getContent())->toBe('ok');
});
