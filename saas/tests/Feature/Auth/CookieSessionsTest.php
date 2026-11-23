<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Mail\LoginLink;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\GuardianService;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * H19: l'area soci e i moduli pubblici tengono la sessione nel cookie (nessuna riga in core.sessions); il gestionale
 * resta nel database. In produzione il driver è «database»: le prove lo impostano come là (di solito è «array»).
 * Il comportamento verso il socio (scheda scelta, messaggi, uscita) deve restare quello di prima.
 */

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null);
        }
    });
    $this->org = $this->createOrg('Polisportiva Beta');
    $this->base = 'http://'.$this->org->slug.'.associazionesemplice.it/area';
    [$this->mario, $this->luca] = $this->orgContext()->run($this->org->id, function (): array {
        $mario = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'card_number' => '7', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($mario->id, Account::query()->sole()->id, 3000);
        $luca = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'birth_date' => now()->subYears(10)->toDateString()]);
        app(GuardianService::class)->link($luca->id, $mario->id);

        return [$mario, $luca];
    });
});

/**
 * Il vasetto dei cookie dopo una risposta: nome => valore grezzo (cifrato), tolti quelli scaduti.
 *
 * @param  array<string, string>  $jar
 * @return array<string, string>
 */
function jarAfter(TestResponse $response, array $jar = []): array
{
    foreach ($response->headers->getCookies() as $cookie) {
        $expires = $cookie->getExpiresTime();
        if ($cookie->getValue() === null || $cookie->getValue() === '' || ($expires !== 0 && $expires < time())) {
            unset($jar[$cookie->getName()]);

            continue;
        }
        $jar[$cookie->getName()] = (string) $cookie->getValue();
    }

    return $jar;
}

function sessionRows(): int
{
    return DB::table('core.sessions')->count();
}

it('le rotte pubbliche e dell\'area soci hanno il marchio della sessione nel cookie, quelle con accesso mai', function (): void {
    $marked = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $isMarked = in_array('cookie-session', $middleware, true);
        $name = (string) $route->getName();
        if ($isMarked) {
            $marked[] = $name;
            // Chi ha un accesso da proteggere resta nel database.
            expect($middleware)->not->toContain('auth')->and($middleware)->not->toContain('two-factor')->and($middleware)->not->toContain('operator');
        }
        if (str_starts_with($name, 'member.') || str_starts_with($name, 'custom.member.')) {
            // Il webhook di Stripe non ha sessione affatto.
            if (! in_array('web', $middleware, true) || str_starts_with($name, 'member.staff.')) {
                continue;
            }
            expect($isMarked)->toBeTrue($name);
        }
    }

    expect($marked)->toContain('donation.form', 'donation.submit', 'application.form', 'application.submit', 'member.login', 'member.home', 'custom.member.login');
});

it('una visita all\'area soci non scrive nella tabella delle sessioni e usa il cookie proprio', function (): void {
    $operatorCookie = (string) config('session.cookie');
    $before = sessionRows();

    $response = $this->get($this->base.'/accesso')->assertOk();

    expect(sessionRows())->toBe($before)
        ->and($response->getCookie('as_pubblico', false))->not->toBeNull()
        ->and($response->getCookie($operatorCookie, false))->toBeNull();
});

it('il gestionale scrive ancora la sessione nel database, con il suo cookie, dopo una visita all\'area soci', function (): void {
    $operatorCookie = (string) config('session.cookie');
    $this->get($this->base.'/accesso')->assertOk();
    $before = sessionRows();

    $response = $this->get('/accesso')->assertOk();

    expect(sessionRows())->toBe($before + 1)
        ->and($response->getCookie($operatorCookie, false))->not->toBeNull()
        ->and($response->getCookie('as_pubblico', false))->toBeNull();
});

it('il messaggio dato prima di un cambio di pagina arriva al socio, come prima', function (): void {
    $first = $this->post($this->base.'/accesso', ['email' => 'nessuno@example.com'])->assertRedirect('/area/accesso');
    $jar = jarAfter($first);

    $this->withUnencryptedCookies($jar)->get($this->base.'/accesso')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('message', fn ($m) => is_string($m) && $m !== ''));
});

it('la scheda scelta resta tra una richiesta e l\'altra, e uscire la azzera e chiude l\'accesso', function (): void {
    $rows = sessionRows();
    Mail::fake();
    $jar = jarAfter($this->post($this->base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso'));
    $code = (string) Mail::sent(LoginLink::class)->last()?->code;
    $entered = $this->withUnencryptedCookies($jar)->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $code])->assertRedirect('/area');
    $jar = jarAfter($entered, $jar);
    expect($jar)->toHaveKey('as_socio');

    $jar = jarAfter($this->withUnencryptedCookies($jar)->post($this->base.'/scheda/'.$this->luca->id)->assertRedirect('/area'), $jar);
    $this->withUnencryptedCookies($jar)->get($this->base.'/profilo')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('card.id', $this->luca->id));

    // Uscita: la sessione nel cookie si svuota e l'accesso del socio, nel database dei soci, non vale più.
    $out = $this->withUnencryptedCookies($jar)->post($this->base.'/esci')->assertRedirect('/area/accesso');
    $old = $jar;
    $jar = jarAfter($out, $jar);
    expect($jar)->not->toHaveKey('as_socio');
    $this->withUnencryptedCookies($old)->get($this->base)->assertRedirect('/area/accesso');
    // Nessuna riga di sessione operatore è stata scritta lungo tutto il percorso.
    expect(sessionRows())->toBe($rows);
});

it('un cookie di sessione alterato non fa errori né dà accesso: si riparte da una sessione vuota', function (): void {
    $jar = jarAfter($this->get($this->base.'/accesso')->assertOk());
    foreach (array_keys($jar) as $name) {
        $jar[$name] = 'alterato-'.$name;
    }

    $this->withUnencryptedCookies($jar)->get($this->base.'/accesso')->assertOk();
    $this->withUnencryptedCookies($jar)->get($this->base)->assertRedirect('/area/accesso');
});

it('i moduli pubblici di donazione e adesione non scrivono nella tabella delle sessioni', function (): void {
    $before = sessionRows();

    $this->get('/dona/'.$this->org->slug);
    $this->get('/adesione/'.$this->org->slug);

    expect(sessionRows())->toBe($before);
});

it('l\'indice su last_activity non c\'è più e la tabella delle sessioni ha spazio per gli aggiornamenti sul posto', function (): void {
    $index = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_indexes WHERE schemaname = 'core' AND tablename = 'sessions' AND indexdef ILIKE '%last_activity%'");
    $options = DB::connection('pgsql_owner')->selectOne("SELECT coalesce(array_to_string(reloptions, ','), '') AS o FROM pg_class WHERE oid = 'core.sessions'::regclass");

    expect((int) (is_object($index) ? $index->n : -1))->toBe(0)
        ->and(is_object($options) ? $options->o : '')->toContain('fillfactor=60');
});

/** @return array<string, Symfony\Component\HttpFoundation\Cookie> i cookie di sessione pubblica di una risposta: quello dell'id e quello dei dati */
function publicSessionCookies(TestResponse $response): array
{
    $out = [];
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() !== 'as_socio') {
            $out[$cookie->getName()] = $cookie;
        }
    }

    return $out;
}

it('i cookie della sessione pubblica sono Secure, HttpOnly, Lax, con percorso / e senza Domain', function (): void {
    config(['session.secure' => true, 'session.domain' => 'esempio.test', 'session.expire_on_close' => true]);

    $cookies = publicSessionCookies($this->post($this->base.'/accesso', ['email' => 'nessuno@example.com']));

    expect($cookies)->toHaveCount(2)->and($cookies)->toHaveKey('as_pubblico');
    foreach ($cookies as $name => $cookie) {
        expect($cookie->isSecure())->toBeTrue($name)
            ->and($cookie->isHttpOnly())->toBeTrue($name)
            ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax', $name)
            ->and($cookie->getPath())->toBe('/', $name)
            ->and($cookie->getDomain())->toBeNull($name)
            // Chiusa con il browser: nessuna scadenza fissa.
            ->and($cookie->getExpiresTime())->toBe(0, $name);
    }
});

it('con la sessione che non finisce alla chiusura del browser la scadenza è quella della sessione', function (): void {
    config(['session.secure' => true, 'session.expire_on_close' => false, 'session.lifetime' => 120]);

    foreach (publicSessionCookies($this->post($this->base.'/accesso', ['email' => 'nessuno@example.com'])) as $name => $cookie) {
        expect($cookie->getExpiresTime())->toBeGreaterThan(time() + 119 * 60, $name)->and($cookie->getExpiresTime())->toBeLessThanOrEqual(time() + 121 * 60, $name);
    }
});

it('i dati della sessione pubblica sono cifrati e dentro c\'è solo la scheda scelta', function (): void {
    Mail::fake();
    $jar = jarAfter($this->post($this->base.'/accesso', ['email' => 'mario@example.com'])->assertRedirect('/area/accesso'));
    $code = (string) Mail::sent(LoginLink::class)->last()?->code;
    $jar = jarAfter($this->withUnencryptedCookies($jar)->post($this->base.'/accesso/codice', ['email' => 'mario@example.com', 'code' => $code])->assertRedirect('/area'), $jar);
    $chosen = $this->withUnencryptedCookies($jar)->post($this->base.'/scheda/'.$this->luca->id)->assertRedirect('/area');

    $data = array_filter(publicSessionCookies($chosen), fn ($c, $n) => $n !== 'as_pubblico', ARRAY_FILTER_USE_BOTH);
    expect($data)->toHaveCount(1);
    $raw = (string) array_values($data)[0]->getValue();

    // Non leggibile: né l'id della scheda né la parola della sessione compaiono in chiaro.
    expect($raw)->not->toContain($this->luca->id)->and($raw)->not->toContain('member_active_profile')->and($raw)->not->toContain('mario@example.com');

    // Decifrato (solo qui, con la chiave dell'applicazione): la scheda scelta c'è, nessun'altra persona.
    $plain = Illuminate\Cookie\CookieValuePrefix::remove(app('encrypter')->decrypt($raw, false));
    expect($plain)->toContain($this->luca->id)->and($plain)->not->toContain($this->mario->id)->and($plain)->not->toContain('mario@example.com');
});
