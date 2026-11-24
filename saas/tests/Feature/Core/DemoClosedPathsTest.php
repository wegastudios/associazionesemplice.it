<?php

declare(strict_types=1);

use App\Modules\Core\Http\Middleware\CloseDemoPaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Nella demo pubblica amministrazione della piattaforma, API dello staff e del portale, ingresso unico, Stripe e
 * abbonamento rispondono 404 prima di qualunque controllo di firma o di sessione. Ambiente «testing»: restricted() lo ammette.
 */

/** Passa dal middleware vero con un percorso e dice se è stato chiuso (404) o lasciato passare. */
function closedByDemo(string $method, string $path): bool
{
    try {
        (new CloseDemoPaths)->handle(Request::create($path, $method), static fn () => response('ok'));
    } catch (NotFoundHttpException) {
        return true;
    }

    return false;
}

it('con la demo accesa i percorsi di piattaforma, staff, API, ingresso unico e Stripe sono chiusi', function (string $method, string $path): void {
    demoOn();

    expect(closedByDemo($method, $path))->toBeTrue("$method $path");
})->with([
    ['GET', '/saas/admin'], ['GET', '/saas/admin/organizzazioni'], ['POST', '/saas/admin/utenti'],
    ['GET', '/saas/staff/inviti'], ['POST', '/saas/staff/inviti/abc'],
    ['GET', '/api/catalogo/v1'], ['PUT', '/api/portale/v2/orgs/1/subscription'], ['POST', '/api/portale/v2/runtime'], ['POST', '/api/portale/v1/orgs'],
    ['GET', '/accesso-unico'], ['GET', '/accesso-unico/token'],
    ['POST', '/area/webhook/stripe'], ['GET', '/gestionale/abbonamento'],
]);

it('con la demo accesa restano aperti accesso, gestionale, area soci, moduli pubblici e l\'azzeramento locale della cache', function (string $method, string $path): void {
    demoOn();

    expect(closedByDemo($method, $path))->toBeFalse("$method $path");
})->with([
    ['GET', '/accesso'], ['GET', '/gestionale'], ['GET', '/gestionale/soci'], ['GET', '/area/accesso'], ['GET', '/dona'], ['GET', '/adesione/modulo'],
    ['GET', '/up'], ['GET', '/robots.txt'], ['POST', '/api/portale/v2/runtime/reset'],
]);

it('fuori dalla demo il guardiano non chiude niente', function (): void {
    config(['saas.demo.enabled' => false]);

    expect(closedByDemo('GET', '/saas/admin'))->toBeFalse()->and(closedByDemo('POST', '/area/webhook/stripe'))->toBeFalse();
});

it('in produzione non chiude niente nemmeno con SAAS_DEMO acceso per errore', function (): void {
    demoOn();
    app()->detectEnvironment(fn (): string => 'production');

    expect(closedByDemo('GET', '/saas/admin'))->toBeFalse();
});

it('ogni rotta registrata che parla con il portale, lo staff o Stripe è chiusa nella demo, salvo l\'azzeramento locale', function (): void {
    demoOn();
    $open = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');
        $sensitive = preg_match('#^/(saas|api|accesso-unico)(/|$)#', $uri) === 1 || str_starts_with($uri, '/area/webhook') || $uri === '/gestionale/abbonamento';
        // Gli indirizzi con segnaposto si provano con un valore qualunque.
        $probe = (string) preg_replace('/\{[^}]+\}/', 'x', $uri);
        if ($sensitive && ! closedByDemo('GET', $probe)) {
            $open[] = $uri;
        }
    }

    expect($open)->toBe(['/api/portale/v2/runtime/reset']);
});
