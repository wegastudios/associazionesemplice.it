<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Org\OrgProvisioner;
use App\Modules\Core\Org\SetupWizard;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Services\ApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Demo del gestionale su UN SOLO indirizzo (docs/DEMO.md): demosaas.associazionesemplice.it è insieme l'indirizzo dell'app
 * (APP_URL) e lo slug «demosaas» sotto il dominio dei soci (SAAS_MEMBERS_DOMAIN), quindi anche l'area soci (/area).
 * Qui si prova che sullo stesso host accesso operatore, area soci, moduli pubblici e cookie convivono senza ambiguità.
 */

const DEMO_HOST = 'https://demosaas.associazionesemplice.it';

beforeEach(function (): void {
    config([
        'app.url' => DEMO_HOST, 'saas.members_domain' => 'associazionesemplice.it', 'saas.public_url' => '',
        'saas.demo.enabled' => true, 'saas.demo.org_slug' => 'demosaas',
        'session.driver' => 'database', 'session.cookie' => '__Host-as_demo', 'session.secure' => true, 'session.domain' => null, 'session.path' => '/',
    ]);
    app()->detectEnvironment(fn (): string => 'demo');
    $entitlements = Mockery::mock(Entitlements::class);
    $entitlements->shouldReceive('for')->andReturn(new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null));
    app()->instance(Entitlements::class, $entitlements);
    $this->org = app(OrgProvisioner::class)->create('Associazione Demo', 'demosaas');
    $this->orgContext()->run($this->org->id, function (): void {
        app(OrgSettings::class)->set(SetupWizard::STATUS, SetupWizard::DONE);
        app(OrgSettings::class)->set(ApplicationService::ENABLED, true);
    });
});

/** Nome della rotta che risponde a quell'indirizzo (nessuna richiesta vera: solo la risoluzione). */
function routeNameFor(string $method, string $path): ?string
{
    $request = Request::create(DEMO_HOST.$path, $method);

    try {
        return app('router')->getRoutes()->match($request)->getName();
    } catch (Throwable) {
        return null;
    }
}

it('sullo stesso host ogni indirizzo va alla sua rotta: area soci, accesso, gestionale, moduli pubblici', function (): void {
    expect(routeNameFor('GET', '/area/accesso'))->toBe('member.login')
        ->and(routeNameFor('POST', '/area/accesso/codice'))->toBe('member.login.code')
        ->and(routeNameFor('GET', '/area'))->toBe('member.home')
        ->and(routeNameFor('POST', '/area/webhook/stripe'))->toBe('member.webhook.stripe')
        ->and(routeNameFor('GET', '/accesso'))->toBe('login')
        ->and(routeNameFor('GET', '/gestionale'))->toBe('dashboard')
        ->and(routeNameFor('GET', '/dona/demosaas'))->toBe('donation.form')
        ->and(routeNameFor('GET', '/adesione/demosaas'))->toBe('application.form')
        ->and(routeNameFor('GET', '/robots.txt'))->toBe('robots');
});

it('sullo stesso host rispondono app, accesso operatore, area soci e adesione, e il gestionale chiede l\'accesso', function (): void {
    $this->get(DEMO_HOST.'/accesso')->assertOk();
    $this->get(DEMO_HOST.'/area/accesso')->assertOk();
    $this->get(DEMO_HOST.'/area')->assertRedirect('/area/accesso');
    $this->get(DEMO_HOST.'/adesione/demosaas')->assertOk();
    $this->get(DEMO_HOST.'/gestionale')->assertRedirect('/accesso');
    $this->get(DEMO_HOST.'/up')->assertOk();
});

it('RestrictToAppHost non chiude niente sull\'host unico, e chiude l\'accesso su un altro sottodominio', function (): void {
    $this->get(DEMO_HOST.'/accesso')->assertOk();
    $this->get(DEMO_HOST.'/gestionale')->assertRedirect('/accesso');

    // Un sottodominio fratello (per esempio la demo WordPress) non ottiene accesso né gestionale da questa installazione.
    $this->get('https://wpdemo.associazionesemplice.it/accesso')->assertNotFound();
    $this->get('https://wpdemo.associazionesemplice.it/gestionale')->assertNotFound();
    // L'area soci di un'associazione che non esiste su quel nome: 404, come prima.
    $this->get('https://wpdemo.associazionesemplice.it/area/accesso')->assertNotFound();
});

it('il modulo di donazione è raggiungibile sull\'host unico (404 dell\'applicazione se i pagamenti sono spenti, non del filtro dell\'host)', function (): void {
    // Senza pagamenti attivi la pagina non esiste; ma la rotta è quella giusta e il filtro dell'host non la ferma.
    expect(routeNameFor('GET', '/dona/demosaas'))->toBe('donation.form');
    $this->get(DEMO_HOST.'/dona/demosaas')->assertNotFound();
    $this->get('https://wpdemo.associazionesemplice.it/dona/demosaas')->assertNotFound();
});

/** @return array<string, Symfony\Component\HttpFoundation\Cookie> */
function cookiesOf(TestResponse $response): array
{
    $out = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $out[$cookie->getName()] = $cookie;
    }

    return $out;
}

it('i cookie dell\'operatore sull\'host unico sono __Host-: Secure, percorso /, nessun Domain', function (): void {
    $cookies = cookiesOf($this->get(DEMO_HOST.'/accesso')->assertOk());

    expect($cookies)->toHaveKey('__Host-as_demo')->toHaveKey('XSRF-TOKEN');
    foreach ($cookies as $name => $cookie) {
        expect($cookie->isSecure())->toBeTrue($name)->and($cookie->getPath())->toBe('/', $name)->and($cookie->getDomain())->toBeNull($name);
    }
    expect($cookies['__Host-as_demo']->isHttpOnly())->toBeTrue();
});

it('il cookie «ricordami» dell\'operatore non ha Domain ed è Secure e HttpOnly', function (): void {
    $this->createUser('anna@example.com');
    // Nell'ambiente «demo» le prove non saltano più il controllo CSRF: per questo accesso si torna a «testing» (i nomi dei cookie non dipendono dall'ambiente).
    app()->detectEnvironment(fn (): string => 'testing');

    $response = $this->post(DEMO_HOST.'/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD, 'remember' => true]);
    $cookies = cookiesOf($response);
    $recaller = array_values(array_filter(array_keys($cookies), static fn (string $n): bool => str_starts_with($n, 'remember_')));

    expect($recaller)->toHaveCount(1, 'stato '.$response->status().', destinazione '.(string) $response->headers->get('Location').', cookie '.implode(',', array_keys($cookies)).', errori '.json_encode(session('errors')?->getBag('default')->keys()))
        ->and($cookies)->toHaveKey('__Host-as_demo');
    foreach ([$recaller[0], '__Host-as_demo'] as $name) {
        expect($cookies[$name]->isSecure())->toBeTrue($name)->and($cookies[$name]->isHttpOnly())->toBeTrue($name)
            ->and($cookies[$name]->getPath())->toBe('/', $name)->and($cookies[$name]->getDomain())->toBeNull($name);
    }
});

it('i cookie delle pagine pubbliche sull\'host unico sono __Host-as_pubblico, accanto a quello dell\'operatore, senza Domain', function (): void {
    foreach (['/area/accesso', '/adesione/demosaas'] as $path) {
        $cookies = cookiesOf($this->get(DEMO_HOST.$path)->assertOk());

        expect($cookies)->toHaveKey('__Host-as_pubblico')->not->toHaveKey('as_pubblico')->not->toHaveKey('__Host-as_demo');
        foreach ($cookies as $name => $cookie) {
            expect($cookie->isSecure())->toBeTrue($name)->and($cookie->getPath())->toBe('/', $name)->and($cookie->getDomain())->toBeNull($name);
        }
    }
});

it('il nome __Host- del cookie pubblico vale in produzione, in demo e in staging, non in locale', function (): void {
    foreach (['production', 'demo', 'staging'] as $env) {
        app()->detectEnvironment(fn (): string => $env);
        expect(App\Modules\Core\Http\Middleware\UseCookieSession::cookieName())->toBe('__Host-as_pubblico');
    }
    app()->detectEnvironment(fn (): string => 'local');
    expect(App\Modules\Core\Http\Middleware\UseCookieSession::cookieName())->toBe('as_pubblico');

    // Senza Secure il prefisso sarebbe rifiutato dal browser: nome semplice.
    app()->detectEnvironment(fn (): string => 'demo');
    config(['session.secure' => false]);
    expect(App\Modules\Core\Http\Middleware\UseCookieSession::cookieName())->toBe('as_pubblico');
});

it('la sessione dell\'area soci e dei moduli pubblici non scrive righe, quella dell\'operatore sì', function (): void {
    $before = DB::table('core.sessions')->count();
    $this->get(DEMO_HOST.'/area/accesso')->assertOk();
    expect(DB::table('core.sessions')->count())->toBe($before);

    $this->get(DEMO_HOST.'/accesso')->assertOk();
    expect(DB::table('core.sessions')->count())->toBe($before + 1);
});

it('nella demo ogni risposta dice ai motori di ricerca di non indicizzare, e robots.txt nega tutto', function (): void {
    foreach (['/accesso', '/area/accesso', '/adesione/demosaas', '/up'] as $path) {
        expect($this->get(DEMO_HOST.$path)->headers->get('X-Robots-Tag'))->toContain('noindex');
    }
    $this->get(DEMO_HOST.'/gestionale')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    $robots = $this->get(DEMO_HOST.'/robots.txt')->assertOk();
    expect($robots->getContent())->toBe("User-agent: *\nDisallow: /\n")->and($robots->headers->get('Content-Type'))->toContain('text/plain')
        ->and($robots->headers->getCookies())->toBe([]);
});

it('fuori dalla demo nessuna intestazione noindex e robots.txt è quello del file pubblico', function (): void {
    config(['saas.demo.enabled' => false]);

    expect($this->get(DEMO_HOST.'/accesso')->headers->get('X-Robots-Tag'))->toBeNull();
    expect($this->get(DEMO_HOST.'/robots.txt')->assertOk()->getContent())->toBe((string) file_get_contents(public_path('robots.txt')));

    config(['saas.demo.enabled' => true]);
    app()->detectEnvironment(fn (): string => 'production');
    expect($this->get(DEMO_HOST.'/accesso')->headers->get('X-Robots-Tag'))->toBeNull();
});
