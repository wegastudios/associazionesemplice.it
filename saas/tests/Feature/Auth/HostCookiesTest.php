<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Mail\LoginLink;
use App\Modules\AreaSoci\Services\MemberAccess;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Auth\HostSessionGuard;
use App\Modules\Core\Contracts\HostCookies;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/*
 * Cookie con il prefisso __Host- (docs/COOKIE_HOST.md): in produzione i sottodomini delle associazioni sono fratelli del
 * dominio dell'app, e un cookie senza prefisso può essere sostituito da uno scritto da un sottodominio. Qui si prova su
 * HTTPS che il «ricordami», il token XSRF e l'accesso del socio hanno nome __Host-, Secure, percorso /, nessun Domain, e che
 * chi ha ancora i vecchi nomi passa ai nuovi senza rifare l'accesso (finché la finestra di passaggio è aperta).
 */

const HC_APP = 'https://localhost';

beforeEach(function (): void {
    // Come in produzione (il modello di sviluppo ha SESSION_SECURE_COOKIE=false): cookie Secure, percorso /, nessun Domain.
    config(['session.secure' => true, 'session.path' => '/', 'session.domain' => null]);
});

/** Il cookie col nome dato di una risposta, o null. */
function hcCookie(TestResponse $response, string $name): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}

/** Il cookie col nome dato è nella risposta ed è scaduto (cancellato); in caso contrario il messaggio elenca i cookie della risposta. */
function hcExpired(TestResponse $response, string $name): void
{
    $list = implode('; ', array_map(static fn (Cookie $c): string => $c->getName().' path='.$c->getPath().' scade='.$c->getExpiresTime(), $response->headers->getCookies()));
    $cookie = hcCookie($response, $name);

    expect($cookie)->not->toBeNull('assente '.$name.' tra: '.$list);
    expect($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time())->toBeTrue('non scaduto '.$name.' tra: '.$list);
}

/** Regole del prefisso __Host-: Secure, percorso /, nessun Domain. */
function hcAssertHostRules(?Cookie $cookie, string $name): void
{
    expect($cookie)->not->toBeNull($name);
    expect($cookie->isSecure())->toBeTrue($name)
        ->and($cookie->getPath())->toBe('/', $name)
        ->and($cookie->getDomain())->toBeNull($name)
        ->and(str_starts_with($cookie->getName(), '__Host-'))->toBeTrue($name);
}

/** Accesso dell'operatore con «ricordami» sull'indirizzo dato: restituisce il cookie «ricordami» (nome, valore grezzo, valore in chiaro). */
function hcRememberLogin(object $test, string $url): array
{
    // Una guardia nuova a ogni accesso: nei test resta in memoria da una richiesta all'altra, con la richiesta di prima.
    app('auth')->forgetGuards();
    app('cookie')->flushQueuedCookies();
    $login = $test->post($url.'/accesso', ['email' => 'anna@example.com', 'password' => $test::PASSWORD, 'remember' => true]);
    $names = array_values(array_filter(array_map(static fn (Cookie $c): string => $c->getName(), $login->headers->getCookies()), static fn (string $n): bool => str_contains($n, 'remember_')));
    expect($names)->toHaveCount(1);

    return [$names[0], (string) $login->getCookie($names[0], false)?->getValue(), (string) $login->getCookie($names[0])?->getValue(), $login];
}

/** Un browser nuovo: niente sessione, restano solo i cookie che si mandano. */
function hcNewBrowser(object $test): void
{
    (function (): void {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        // Nei test il vasetto dei cookie è lo stesso fra una richiesta e l'altra: i cookie messi in coda prima non devono finire nella risposta dopo.
        $this->app['cookie']->flushQueuedCookies();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    })->call($test);
}

it('il token XSRF su HTTPS si chiama __Host-XSRF-TOKEN: Secure, percorso /, nessun Domain, leggibile da JavaScript', function (): void {
    $response = $this->get(HC_APP.'/accesso')->assertOk();

    $cookie = hcCookie($response, '__Host-XSRF-TOKEN');
    hcAssertHostRules($cookie, '__Host-XSRF-TOKEN');
    expect($cookie->isHttpOnly())->toBeFalse()
        ->and(hcCookie($response, 'XSRF-TOKEN'))->toBeNull();
    // Il nome arriva al JavaScript dalla pagina (meta), che non lo indovina.
    $response->assertSee('<meta name="xsrf-cookie" content="__Host-XSRF-TOKEN">', false);
});

it('su http (sviluppo) il token XSRF resta XSRF-TOKEN, perché il prefisso lì sarebbe rifiutato', function (): void {
    $response = $this->get('http://localhost/accesso')->assertOk();

    expect(hcCookie($response, 'XSRF-TOKEN'))->not->toBeNull()
        ->and(hcCookie($response, '__Host-XSRF-TOKEN'))->toBeNull();
    $response->assertSee('<meta name="xsrf-cookie" content="XSRF-TOKEN">', false);
});

it('il «ricordami» su HTTPS si chiama __Host-remember_web, con le regole __Host- e HttpOnly, e riporta dentro senza rifare la password', function (): void {
    $user = $this->createUser('anna@example.com');
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    [$name, $raw, $plain, $login] = hcRememberLogin($this, HC_APP);

    expect($name)->toBe('__Host-remember_web');
    $cookie = hcCookie($login, $name);
    hcAssertHostRules($cookie, $name);
    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time() + 13 * 86400)->toBeLessThan(time() + 15 * 86400);

    hcNewBrowser($this);
    $this->withUnencryptedCookie($name, $raw);
    $this->get(HC_APP.'/gestionale')->assertRedirectContains('/account/verifica-in-due-passaggi');
    $this->assertAuthenticatedAs($user);
});

it('l\'uscita cancella il cookie «ricordami» con il nome nuovo', function (): void {
    $user = $this->createUser('anna@example.com');
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    [$name, $raw] = hcRememberLogin($this, HC_APP);

    // Chi esce ha ancora il cookie «ricordami» nel browser: il server lo cancella con il nome nuovo.
    hcNewBrowser($this);
    $out = $this->actingAs($user)->withUnencryptedCookie($name, $raw)->post(HC_APP.'/esci');

    hcExpired($out, $name);
});

it('la guardia del gestionale è la nostra, quella dell\'amministrazione no, e l\'amministrazione non cambia nome', function (): void {
    expect(Auth::guard('web'))->toBeInstanceOf(HostSessionGuard::class)
        ->and(Auth::guard('platform'))->not->toBeInstanceOf(HostSessionGuard::class);

    // Sotto /saas/admin il percorso della sessione non è «/»: il prefisso sarebbe rifiutato, il nome non cambia.
    config(['session.path' => '/saas/admin']);
    expect(HostCookies::eligible(Request::create(HC_APP.'/saas/admin')))->toBeFalse()
        ->and(HostCookies::name('XSRF-TOKEN', Request::create(HC_APP.'/saas/admin')))->toBe('XSRF-TOKEN');
});

it('il prefisso c\'è solo dove il browser lo accetterebbe: HTTPS, Secure acceso, percorso /, nessun Domain', function (): void {
    $https = Request::create(HC_APP.'/');
    $http = Request::create('http://localhost/');

    expect(HostCookies::eligible($https))->toBeTrue()->and(HostCookies::eligible($http))->toBeFalse();
    foreach ([['session.secure', false], ['session.path', '/area'], ['session.domain', 'associazionesemplice.it']] as [$key, $value]) {
        config([$key => $value]);
        expect(HostCookies::eligible($https))->toBeFalse($key);
        config(['session.secure' => true, 'session.path' => '/', 'session.domain' => null]);
    }
    expect(HostCookies::name('as_socio', $https))->toBe('__Host-as_socio')
        ->and(HostCookies::name('as_socio', $http))->toBe('as_socio')
        ->and(HostCookies::recaller('web', $http))->toBe(HostCookies::legacyRecaller('web'));
});

it('il vecchio «ricordami» passa al nome nuovo alla prima visita, senza rifare l\'accesso, e il vecchio si cancella', function (): void {
    $user = $this->createUser('anna@example.com');
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    // Come prima del rilascio: su http il nome è quello di Laravel.
    [$legacy, $raw, $plain] = hcRememberLogin($this, 'http://localhost');
    expect($legacy)->toBe(HostCookies::legacyRecaller('web'));

    hcNewBrowser($this);
    $until = now()->addDays(14);
    config(['saas.legacy_cookies.remember_until' => $until->toIso8601String()]);
    $response = $this->withUnencryptedCookie($legacy, $raw)->get(HC_APP.'/gestionale')->assertRedirectContains('/account/verifica-in-due-passaggi');

    $this->assertAuthenticatedAs($user);
    $new = hcCookie($response, '__Host-remember_web');
    hcAssertHostRules($new, '__Host-remember_web');
    expect($new->isHttpOnly())->toBeTrue()
        // Stesso valore (cifrato col nome nuovo) e nessuna proroga: scade alla fine della finestra.
        ->and($response->getCookie('__Host-remember_web')?->getValue())->toBe($plain)
        ->and($new->getExpiresTime())->toBe($until->getTimestamp());
    hcExpired($response, $legacy);

    // La visita dopo, con il solo cookie nuovo, entra ancora.
    $newRaw = (string) $response->getCookie('__Host-remember_web', false)?->getValue();
    hcNewBrowser($this);
    $this->withUnencryptedCookie('__Host-remember_web', $newRaw)->get(HC_APP.'/gestionale')->assertRedirectContains('/account/verifica-in-due-passaggi');
    $this->assertAuthenticatedAs($user);
});

it('chiusa la finestra il vecchio «ricordami» non vale più e si cancella: una sola riautenticazione', function (): void {
    $user = $this->createUser('anna@example.com');
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    [$legacy, $raw] = hcRememberLogin($this, 'http://localhost');

    foreach ([null, now()->subDay()->toIso8601String(), 'non-una-data'] as $until) {
        hcNewBrowser($this);
        config(['saas.legacy_cookies.remember_until' => $until]);
        $response = $this->withUnencryptedCookie($legacy, $raw)->get(HC_APP.'/gestionale')->assertRedirectContains('/accesso');

        $this->assertGuest();
        expect(hcCookie($response, '__Host-remember_web'))->toBeNull();
        hcExpired($response, $legacy);
    }
});

it('con tutti e due i cookie vale quello nuovo e il vecchio si cancella', function (): void {
    $user = $this->createUser('anna@example.com');
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    [$legacy, $legacyRaw] = hcRememberLogin($this, 'http://localhost');
    [$new, $newRaw] = hcRememberLogin($this, HC_APP);

    hcNewBrowser($this);
    config(['saas.legacy_cookies.remember_until' => now()->addDays(14)->toIso8601String()]);
    $response = $this->withUnencryptedCookie($legacy, $legacyRaw)->withUnencryptedCookie($new, $newRaw)->get(HC_APP.'/gestionale');

    // Non riscrive il nuovo (già c'è), cancella solo il vecchio.
    expect(hcCookie($response, $new))->toBeNull();
    hcExpired($response, $legacy);
});

/** Associazione con area soci attiva e un socio. Restituisce l'indirizzo dell'area, su HTTPS e su http. */
function hcMemberArea(object $test): array
{
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null);
        }
    });
    // Metodi protetti della classe di prova: si chiamano dal suo interno.
    $slug = (function (): string {
        $org = $this->createOrg('Polisportiva Beta');
        $this->orgContext()->run($org->id, function (): void {
            $mario = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'card_number' => '7', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']);
            app(MembershipService::class)->renewWithPayment($mario->id, Account::query()->sole()->id, 3000);
        });

        return $org->slug;
    })->call($test);
    $host = $slug.'.associazionesemplice.it';

    return ['https://'.$host.'/area', 'http://'.$host.'/area'];
}

/** Entra come socio con email e codice sull'indirizzo dato. */
function hcMemberLogin(object $test, string $base): TestResponse
{
    Mail::fake();
    $test->post($base.'/accesso', ['email' => 'mario@example.com'])->assertRedirectContains('/area/accesso');
    $code = '';
    Mail::assertSent(LoginLink::class, function (LoginLink $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });

    return $test->post($base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $code])->assertRedirectContains('/area');
}

it('l\'accesso del socio su HTTPS è __Host-as_socio: Secure, HttpOnly, Lax, percorso /, nessun Domain; il vecchio non si scrive più', function (): void {
    [$https] = hcMemberArea($this);

    $response = hcMemberLogin($this, $https);

    $cookie = hcCookie($response, '__Host-as_socio');
    hcAssertHostRules($cookie, '__Host-as_socio');
    expect($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time() + 89 * 86400)->toBeLessThan(time() + 91 * 86400)
        ->and(hcCookie($response, 'as_socio'))->toBeNull();

    // Con il cookie nuovo si entra nell'area, e il tutto vale su ogni percorso dell'indirizzo.
    $raw = (string) $response->getCookie('__Host-as_socio', false)?->getValue();
    hcNewBrowser($this);
    $this->withUnencryptedCookie('__Host-as_socio', $raw)->get($https)->assertOk();
    hcNewBrowser($this);
    $this->get($https)->assertRedirectContains('/area/accesso');
});

it('l\'uscita del socio cancella il cookie con il nome nuovo e con quello vecchio (percorso /area)', function (): void {
    [$https] = hcMemberArea($this);
    $raw = (string) hcMemberLogin($this, $https)->getCookie('__Host-as_socio', false)?->getValue();

    $out = $this->withUnencryptedCookie('__Host-as_socio', $raw)->post($https.'/esci')->assertRedirectContains('/area/accesso');

    $out->assertCookieExpired('__Host-as_socio')->assertCookieExpired('as_socio');
    expect(hcCookie($out, 'as_socio')?->getPath())->toBe('/area')
        ->and(hcCookie($out, '__Host-as_socio')?->getPath())->toBe('/');
});

it('su http (sviluppo) l\'accesso del socio resta as_socio con percorso /area', function (): void {
    [, $http] = hcMemberArea($this);

    $cookie = hcCookie(hcMemberLogin($this, $http), 'as_socio');

    expect($cookie?->getPath())->toBe('/area');
});

it('il vecchio as_socio passa al nome nuovo alla prima visita, senza rifare l\'accesso, e il vecchio (percorso /area) si cancella', function (): void {
    [$https, $http] = hcMemberArea($this);
    // Come prima del rilascio: nome as_socio, valore cifrato col nome vecchio.
    $before = hcMemberLogin($this, $http);
    $raw = (string) $before->getCookie('as_socio', false)?->getValue();
    $token = (string) $before->getCookie('as_socio')?->getValue();
    expect($token)->not->toBe('');

    hcNewBrowser($this);
    config(['saas.legacy_cookies.member_until' => now()->addDays(30)->toIso8601String()]);
    $response = $this->withUnencryptedCookie('as_socio', $raw)->get($https)->assertOk();

    $new = hcCookie($response, '__Host-as_socio');
    hcAssertHostRules($new, '__Host-as_socio');
    expect($new->isHttpOnly())->toBeTrue()
        ->and($response->getCookie('__Host-as_socio')?->getValue())->toBe($token)
        ->and($new->getExpiresTime())->toBeGreaterThan(time() + 89 * 86400);
    hcExpired($response, 'as_socio');
    expect(hcCookie($response, 'as_socio')?->getPath())->toBe('/area');

    // La visita dopo, con il solo cookie nuovo, entra ancora.
    $newRaw = (string) $response->getCookie('__Host-as_socio', false)?->getValue();
    hcNewBrowser($this);
    $this->withUnencryptedCookie('__Host-as_socio', $newRaw)->get($https)->assertOk();
});

it('chiusa la finestra il vecchio as_socio non vale più: si riparte dall\'accesso del socio', function (): void {
    [$https, $http] = hcMemberArea($this);
    $raw = (string) hcMemberLogin($this, $http)->getCookie('as_socio', false)?->getValue();

    foreach ([null, now()->subDay()->toIso8601String()] as $until) {
        hcNewBrowser($this);
        config(['saas.legacy_cookies.member_until' => $until]);
        $response = $this->withUnencryptedCookie('as_socio', $raw)->get($https)->assertRedirectContains('/area/accesso');

        expect(hcCookie($response, '__Host-as_socio'))->toBeNull();
        hcExpired($response, 'as_socio');
    }
});

it('i giorni dell\'accesso del socio nel cookie sono quelli del database', function (): void {
    expect(HostCookies::MEMBER_DAYS)->toBe(MemberAccess::MAX_DAYS);
});
