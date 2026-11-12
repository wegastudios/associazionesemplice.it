<?php

declare(strict_types=1);

use App\Modules\Core\Platform\PlatformConfig;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/StaffHelpers.php';

/*
 * Impostazioni in /saas/admin: oggi i nameserver dei domini personalizzati, che prendono il posto del file d'ambiente.
 */

beforeEach(function (): void {
    loginStaff($this, makeStaff());
});

it('mostra le impostazioni del dominio e le salva, con i valori che entrano nella configurazione', function (): void {
    $this->get('/saas/admin/impostazioni')->assertOk()->assertSee('Dominio personalizzato')->assertSee('Nameserver a cui il cliente delega');

    $this->post('/saas/admin/impostazioni', ['settings' => [
        'domains_nameservers' => 'ns1.associazionesemplice.it, ns2.associazionesemplice.it.',
        'domains_delegation_hours' => '48',
    ]])->assertRedirect('/saas/admin/impostazioni');

    $this->get('/saas/admin/impostazioni')->assertSee('ns1.associazionesemplice.it, ns2.associazionesemplice.it.')->assertSee('Impostazioni salvate');

    app(PlatformConfig::class)->apply();
    expect(config('saas.domains.nameservers'))->toBe(['ns1.associazionesemplice.it', 'ns2.associazionesemplice.it.'])
        ->and(config('saas.domains.delegation_hours'))->toBe(48);

    // Il registro dice quali chiavi sono cambiate, non i valori.
    $details = (string) DB::table('core.platform_audit')->where('action', 'settings.updated')->value('details');
    expect($details)->toContain('domains.nameservers')->not->toContain('associazionesemplice.it');
});

it('rifiuta nameserver e ore non validi e mostra l\'errore con quello che era stato scritto', function (): void {
    $this->post('/saas/admin/impostazioni', ['settings' => ['domains_nameservers' => 'non un server!', 'domains_delegation_hours' => '9999']])
        ->assertOk()->assertSee('non è un nome di server valido')->assertSee('da 1 a 720')->assertSee('non un server!');

    expect(DB::table('core.platform_settings')->count())->toBe(0);
});

it('solo l\'amministratore cambia le impostazioni; gli altri le vedono', function (): void {
    $this->post('/saas/admin/esci');
    loginStaff($this, makeStaff('vendita@example.test', 'sales'));

    $this->get('/saas/admin/impostazioni')->assertOk()->assertSee('sola lettura');
    $this->post('/saas/admin/impostazioni', ['settings' => ['domains_nameservers' => 'ns1.example.test']])->assertForbidden();
    expect(DB::table('core.platform_settings')->count())->toBe(0);
});

it('la barra dell\'amministrazione della vendita dice «Gestisci SaaS», non «Gestionale»', function (): void {
    $this->get('/saas/admin')->assertOk()->assertSee('Gestisci SaaS')->assertDontSee('<span class="place">Gestionale</span>', false);
});
