<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Models\PlatformStaff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once dirname(__DIR__).'/Portale/PortalHelpers.php';

/*
 * Accesso unico dello staff (docs/UTENTI_E_ACCESSI.md): /saas/admin si apre con il token `staff_sso` del portale.
 */

const SSO_LOGIN = 'https://portale.example.test/staff/accedi?area=saas';

beforeEach(function (): void {
    $this->portalSeed = fakePortal();
    config(['saas.staff_sso.enabled' => true]);
});

/** @param  array<string, mixed>  $claims */
function ssoToken(string $seed, array $claims = [], string $type = 'staff_sso'): string
{
    return portalToken($seed, $type, $claims + [
        'sub' => 'persona@example.test',
        'area' => 'saas',
        'level' => 'vendita',
        'mfa' => false,
        'sid' => bin2hex(random_bytes(16)),
    ], 60);
}

it('è spento finché SAAS_STAFF_SSO non è acceso', function (): void {
    config(['saas.staff_sso.enabled' => false]);

    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])->assertNotFound();
});

it('apre la sessione con il token del portale, senza password e senza secondo fattore', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])
        ->assertOk()->assertSee('url=/saas/admin', false);

    $this->get('/saas/admin')->assertOk()->assertSee('Riepilogo');

    $staff = PlatformStaff::query()->where('email', 'persona@example.test')->firstOrFail();
    expect($staff->role)->toBe('sales')->and($staff->canWrite())->toBeTrue()->and($staff->canManageUsers())->toBeFalse();
    expect(DB::table('core.platform_audit')->where('action', 'staff.sso_login')->count())->toBe(1);
});

it('traduce i tre livelli nei ruoli locali', function (string $level, string $role, bool $write, bool $users): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed, ['level' => $level, 'sub' => "{$level}@example.test"])])->assertOk();

    $staff = PlatformStaff::query()->where('email', "{$level}@example.test")->firstOrFail();
    expect($staff->role)->toBe($role)->and($staff->canWrite())->toBe($write)->and($staff->canManageUsers())->toBe($users);
})->with([
    'admin' => ['admin', 'admin', true, true],
    'vendita' => ['vendita', 'sales', true, false],
    'supporto' => ['supporto', 'readonly', false, false],
]);

it('accetta il secondo fattore fatto o no, ma non un mfa_at incoerente', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed, ['mfa' => true, 'mfa_at' => time() - 60])])->assertOk();
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed, ['mfa' => false, 'mfa_at' => time()])])->assertForbidden();
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed, ['mfa' => true])])->assertForbidden();
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed, ['mfa' => true, 'mfa_at' => time() - 9 * 3600])])->assertForbidden();
});

it('rifiuta un token usato due volte', function (): void {
    $token = ssoToken($this->portalSeed);

    $this->post('/saas/admin/sso', ['token' => $token])->assertOk();
    $this->post('/saas/admin/sso', ['token' => $token])->assertForbidden();
});

it('rifiuta token sbagliati: tipo, area, livello, email, durata, firma, spazzatura', function (string $how): void {
    $token = match ($how) {
        'type' => ssoToken($this->portalSeed, [], 'entitlements'),
        'area' => ssoToken($this->portalSeed, ['area' => 'supporto']),
        'level' => ssoToken($this->portalSeed, ['level' => 'superman']),
        'email' => ssoToken($this->portalSeed, ['sub' => 'non-una-email']),
        'long' => portalToken($this->portalSeed, 'staff_sso', ['sub' => 'a@example.test', 'area' => 'saas', 'level' => 'admin', 'mfa' => false, 'sid' => 'abc'], 600),
        'signature' => ssoToken(random_bytes(32)),
        'garbage' => 'non.un.token',
        default => '',
    };

    $this->post('/saas/admin/sso', ['token' => $token])->assertForbidden()->assertSee('Accesso non riuscito');
    expect(PlatformStaff::query()->count())->toBe(0);
})->with(['type', 'area', 'level', 'email', 'long', 'signature', 'garbage', 'empty']);

it('non fa entrare una persona disattivata', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])->assertOk();
    PlatformStaff::query()->where('email', 'persona@example.test')->firstOrFail()->forceFill(['disabled_at' => now()])->save();
    Auth::forgetGuards();

    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])->assertForbidden();
});

it('senza sessione manda all\'accesso del portale solo con l\'accesso unico acceso', function (): void {
    $this->get('/saas/admin')->assertRedirect(SSO_LOGIN);

    config(['saas.staff_sso.enabled' => false]);
    $this->get('/saas/admin')->assertRedirect('/saas/admin/accesso');
});

it('riconferma ogni 5 minuti e chiude se il portale dice che la sessione è revocata', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])->assertOk();
    // Le risposte del portale, una per controllo: la prima conferma, la seconda dice «revocata».
    Http::fake(['*/api/staff/v1/session-check' => Http::sequence()
        ->push(['valid' => true, 'level' => 'vendita'])
        ->push(['valid' => false, 'level' => null])]);

    $this->travel(2)->minutes();
    $this->get('/saas/admin')->assertOk();
    Http::assertNothingSent();

    $this->travel(5)->minutes();
    $this->get('/saas/admin')->assertOk();
    Http::assertSentCount(1);

    $this->travel(6)->minutes();
    $this->get('/saas/admin')->assertRedirect(SSO_LOGIN);
    $this->get('/saas/admin')->assertRedirect(SSO_LOGIN);
});

it('chiude la sessione se il livello cambia', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])->assertOk();
    Http::fake(['*/api/staff/v1/session-check' => Http::response(['valid' => true, 'level' => 'admin'])]);

    $this->travel(6)->minutes();
    $this->get('/saas/admin')->assertRedirect(SSO_LOGIN);
});

it('chiude la sessione dopo due controlli falliti di fila', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])->assertOk();
    Http::fake(['*/api/staff/v1/session-check' => Http::response('', 500)]);

    $this->travel(6)->minutes();
    $this->get('/saas/admin')->assertOk();
    $this->travel(6)->minutes();
    $this->get('/saas/admin')->assertRedirect(SSO_LOGIN);
});

it('chiude per inattività dopo 2 ore', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])->assertOk();

    $this->travel(3)->hours();
    $this->get('/saas/admin')->assertRedirect(SSO_LOGIN);
});

it('chiude comunque dopo 8 ore anche se la persona lavora', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed)])->assertOk();
    Http::fake(['*/api/staff/v1/session-check' => Http::response(['valid' => true, 'level' => 'vendita'])]);

    foreach (range(1, 7) as $unused) {
        $this->travel(1)->hours();
        $this->get('/saas/admin')->assertOk();
    }
    $this->travel(61)->minutes();
    $this->get('/saas/admin')->assertRedirect(SSO_LOGIN);
});

it('il livello «supporto» legge ma non modifica', function (): void {
    $this->post('/saas/admin/sso', ['token' => ssoToken($this->portalSeed, ['level' => 'supporto'])])->assertOk();

    $this->get('/saas/admin/catalogo')->assertOk();
    $this->post('/saas/admin/catalogo/scarta')->assertForbidden();
});
