<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\CurrentOrg;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Org\OrgProvisioner;
use App\Modules\Core\Org\SetupWizard;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * «Entra come socio» (solo demo): apre la sessione di UN socio inventato, scelto dal server. Esiste solo con SAAS_DEMO acceso
 * E APP_ENV=demo (DemoScale::active()) e solo sull'associazione della demo; altrove la rotta risponde 404 e i pulsanti non ci sono.
 * Dati di prova inventati.
 */

const DEMO_MEMBER_HOST = 'https://demosaas.associazionesemplice.it';
const DEMO_MEMBER_COOKIE = '__Host-as_socio';

beforeEach(function (): void {
    config([
        'app.url' => DEMO_MEMBER_HOST, 'saas.members_domain' => 'associazionesemplice.it', 'saas.public_url' => '',
        'saas.demo.enabled' => true, 'saas.demo.org_slug' => 'demosaas', 'saas.demo.mail_domain' => 'demosaas.associazionesemplice.it',
        'session.driver' => 'database', 'session.cookie' => '__Host-as_demo', 'session.secure' => true, 'session.domain' => null, 'session.path' => '/',
    ]);
    $entitlements = Mockery::mock(Entitlements::class);
    $entitlements->shouldReceive('for')->andReturn(new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null));
    app()->instance(Entitlements::class, $entitlements);
    $this->org = app(OrgProvisioner::class)->create('Associazione Demo', 'demosaas');
    [$this->fixed, $this->other] = $this->orgContext()->run($this->org->id, function (): array {
        app(OrgSettings::class)->set(SetupWizard::STATUS, SetupWizard::DONE);
        $fixed = Person::create(['first_name' => 'Giulia', 'last_name' => 'Rossi', 'email' => DemoScale::memberEmail(), 'card_number' => 'DEMO-0001']);
        $other = Person::create(['first_name' => 'Marco', 'last_name' => 'Bianchi', 'email' => 'socio002@demosaas.associazionesemplice.it', 'card_number' => 'DEMO-0002']);

        return [$fixed, $other];
    });
});

/** Ambiente della demo vera; di norma senza il controllo CSRF (come demoOn), a meno che la prova non lo voglia. */
function demoMemberOn(bool $csrf = false, string $env = 'demo'): void
{
    app()->detectEnvironment(static fn (): string => $env);
    if (! $csrf) {
        test()->withoutMiddleware([ValidateCsrfToken::class, PreventRequestForgery::class]);
    }
}

/** Le sessioni dei soci dell'associazione di prova, lette con il suo contesto (come fa l'app). */
function memberSessions(): Collection
{
    return test()->orgContext()->run(test()->org->id, static fn (CurrentOrg $org): Collection => DB::connection($org->connection)->table('app.member_sessions')->get());
}

it('nella demo il pulsante c\'è nell\'accesso dei soci e nella pagina di accesso del gestionale', function (): void {
    demoMemberOn();

    $this->get(DEMO_MEMBER_HOST.'/area/accesso')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Accesso')->where('demoMember', true));
    $this->get(DEMO_MEMBER_HOST.'/accesso')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('demoMember', true));
});

it('nella demo si entra come il socio fisso, senza posta, con una sessione di un\'ora', function (): void {
    demoMemberOn();
    Mail::fake();

    $response = $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->assertRedirect('/area');
    $cookie = $response->getCookie(DEMO_MEMBER_COOKIE, false);
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time() + 59 * 60)->toBeLessThan(time() + 61 * 60)
        ->and($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->getPath())->toBe('/');
    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    $session = memberSessions()->sole();
    expect($session->person_id)->toBe($this->fixed->id)->and($session->email)->toBeNull()
        ->and(strtotime((string) $session->expires_at))->toBeGreaterThan(time() + 59 * 60)->toBeLessThan(time() + 61 * 60);

    $this->withUnencryptedCookie(DEMO_MEMBER_COOKIE, (string) $cookie->getValue())->get(DEMO_MEMBER_HOST.'/area')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Tessera')->where('card.first_name', 'Giulia')->where('card.card_number', 'DEMO-0001'));
});

it('nessun parametro della richiesta cambia il socio né la durata', function (): void {
    demoMemberOn();

    $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo', [
        'person_id' => $this->other->id, 'person' => $this->other->id, 'id' => $this->other->id, 'email' => 'socio002@demosaas.associazionesemplice.it',
        'minutes' => 100000, 'days' => 90, 'remember' => true,
    ])->assertRedirect('/area');

    $session = memberSessions()->sole();
    expect($session->person_id)->toBe($this->fixed->id)
        ->and(strtotime((string) $session->expires_at))->toBeLessThan(time() + 61 * 60);
});

it('con la configurazione di produzione la rotta risponde 404 e nessun pulsante compare', function (string $env, bool $flag): void {
    config(['saas.demo.enabled' => $flag]);
    demoMemberOn(false, $env);

    $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->assertNotFound();
    expect(memberSessions()->count())->toBe(0);
    $this->get(DEMO_MEMBER_HOST.'/area/accesso')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Accesso')->where('demoMember', false));
    $this->get(DEMO_MEMBER_HOST.'/accesso')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('demoMember', false)->where('demoAccounts', []));
})->with([
    'produzione con SAAS_DEMO acceso per errore' => ['production', true],
    'staging con SAAS_DEMO acceso' => ['staging', true],
    'ambiente demo ma SAAS_DEMO spento' => ['demo', false],
    'produzione e SAAS_DEMO spento' => ['production', false],
]);

it('fuori dalla demo ?demo=entra non apre nessuna sessione: la pagina riceve demoMember falso e la rotta resta chiusa', function (string $env, bool $flag): void {
    config(['saas.demo.enabled' => $flag]);
    demoMemberOn(false, $env);

    $this->get(DEMO_MEMBER_HOST.'/area/accesso?demo=entra')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Accesso')->where('demoMember', false));
    $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo?demo=entra')->assertNotFound();
    expect(memberSessions()->count())->toBe(0);
})->with([
    'produzione' => ['production', false],
    'produzione con SAAS_DEMO acceso per errore' => ['production', true],
]);

it('l\'invio automatico della pagina parte solo con demoMember vero', function (): void {
    // Nessuna prova del browser in CI: si controlla che la sola chiamata automatica dipenda da demoMember, che arriva dal server.
    $page = (string) file_get_contents(resource_path('js/pages/AreaSoci/Accesso.vue'));
    preg_match_all('/demo\.post\(/', $page, $calls);
    expect($calls[0])->toHaveCount(2)
        ->and($page)->toContain("if (props.demoMember && new URLSearchParams(window.location.search).get('demo') === 'entra') demo.post('/area/accesso/demo');");
});

it('non esiste su un\'altra associazione, anche nell\'ambiente della demo', function (): void {
    demoMemberOn();
    config(['saas.demo.org_slug' => 'polisportiva-demo']);

    $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->assertNotFound();
    $this->get(DEMO_MEMBER_HOST.'/area/accesso')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('demoMember', false));
});

it('senza il token CSRF la richiesta è rifiutata', function (): void {
    demoMemberOn(true);

    $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->assertStatus(419);
    expect(memberSessions()->count())->toBe(0);
});

it('gli ingressi sono limitati per indirizzo IP', function (): void {
    demoMemberOn();
    RateLimiter::clear('demo-member-entry:127.0.0.1');

    for ($i = 0; $i < 20; $i++) {
        $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->assertRedirect('/area');
    }
    $this->postJson(DEMO_MEMBER_HOST.'/area/accesso/demo')->assertUnprocessable()->assertJsonValidationErrors(['demo' => DemoScale::LIMIT_OFF]);
    expect(memberSessions()->count())->toBe(20);
    // Un altro indirizzo non è toccato.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->assertRedirect('/area');
});

it('i dati personali del socio non si cambiano e non si chiudono le sessioni altrui', function (): void {
    demoMemberOn();
    $cookie = (string) $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->getCookie(DEMO_MEMBER_COOKIE, false)?->getValue();
    $me = $this->withUnencryptedCookie(DEMO_MEMBER_COOKIE, $cookie);

    $me->putJson(DEMO_MEMBER_HOST.'/area/profilo', ['phone' => '333 1234567', 'address' => 'Via Falsa 1'])->assertUnprocessable()->assertJsonValidationErrors(['ledger' => DemoScale::CHANGE_OFF]);
    $me->postJson(DEMO_MEMBER_HOST.'/area/tessera/rigenera')->assertUnprocessable()->assertJsonValidationErrors(['ledger' => DemoScale::CHANGE_OFF]);
    $me->postJson(DEMO_MEMBER_HOST.'/area/esci-ovunque')->assertUnprocessable()->assertJsonValidationErrors(['ledger' => DemoScale::CHANGE_OFF]);

    $this->orgContext()->run($this->org->id, function (): void {
        $person = Person::query()->findOrFail($this->fixed->id);
        expect($person->card_number)->toBe('DEMO-0001');
    });
    expect(memberSessions()->whereNotNull('revoked_at')->count())->toBe(0);
});

it('«Esci» chiude la sessione e riporta alla pagina di accesso', function (): void {
    demoMemberOn();
    $cookie = (string) $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->getCookie(DEMO_MEMBER_COOKIE, false)?->getValue();
    $me = $this->withUnencryptedCookie(DEMO_MEMBER_COOKIE, $cookie);

    $me->post(DEMO_MEMBER_HOST.'/area/esci')->assertRedirect('/area/accesso');
    $me->get(DEMO_MEMBER_HOST.'/area')->assertRedirect('/area/accesso');
});

it('se il socio d\'esempio non c\'è risponde con un messaggio e nessuna sessione', function (): void {
    demoMemberOn();
    $this->orgContext()->run($this->org->id, fn () => Person::query()->where('email', DemoScale::memberEmail())->delete());

    $this->post(DEMO_MEMBER_HOST.'/area/accesso/demo')->assertRedirect('/area/accesso')->assertCookieMissing(DEMO_MEMBER_COOKIE);
    expect(memberSessions()->count())->toBe(0);
});
