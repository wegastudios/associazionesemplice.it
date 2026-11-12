<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Contracts\PlanLimits;
use App\Modules\Piattaforma\Contracts\UnknownPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/StaffHelpers.php';
require_once dirname(__DIR__).'/Portale/PortalHelpers.php';

/** La bozza di partenza: il catalogo iniziale con, a scelta, una versione 2 del piano. */
function draftWithNewVersion(string $name = 'Gestionale 2'): array
{
    /** @var array<string, mixed> $catalog */
    $catalog = config('catalogo');
    $plan = $catalog['plans'][0];
    $plan['plan_version'] = 2;
    $plan['name'] = $name;
    $catalog['plans'] = [$plan];

    return $catalog;
}

beforeEach(function (): void {
    fakePortal();
    Http::fake(['*' => Http::response([], 200)]);
    $this->staff = makeStaff();
    loginStaff($this, $this->staff);
});

it('salva la bozza, la pubblica come revisione successiva e avvisa il portale', function (): void {
    $this->get('/saas/admin/catalogo')->assertOk()->assertSee('revisione 2');
    $this->get('/saas/admin/catalogo/bozza')->assertOk();

    $this->post('/saas/admin/catalogo/bozza', ['document' => json_encode(draftWithNewVersion())])->assertRedirect('/saas/admin/catalogo');
    // La bozza non è visibile al portale finché non si pubblica.
    expect(json_decode((string) $this->get('/api/catalogo/v1')->getContent(), true)['rev'])->toBe(2);

    $this->post('/saas/admin/catalogo/pubblica', ['note' => 'Nuova versione'])->assertRedirect('/saas/admin/catalogo');

    $doc = json_decode((string) $this->get('/api/catalogo/v1')->getContent(), true);
    expect($doc['rev'])->toBe(3)->and($doc['plans'][0]['plan_version'])->toBe(2)
        ->and(DB::table('core.catalog_revisions')->where('rev', 2)->exists())->toBeTrue()
        ->and(DB::table('core.catalog_draft')->exists())->toBeFalse()
        ->and(DB::table('core.platform_audit')->where('action', 'catalog.published')->exists())->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/saas/v1/catalog-changed') && is_string($request['token'] ?? null));
});

it('non salva una bozza non valida', function (): void {
    $bad = draftWithNewVersion();
    $bad['plans'][0]['prices'][0]['base_cents'] = 15.5;

    $this->post('/saas/admin/catalogo/bozza', ['document' => json_encode($bad)])->assertOk()->assertSee('base_cents');
    $this->post('/saas/admin/catalogo/bozza', ['document' => '{non json'])->assertOk()->assertSee('JSON non valido');

    expect(DB::table('core.catalog_draft')->exists())->toBeFalse();
});

it('non permette di cambiare una versione già pubblicata', function (): void {
    $changed = draftWithNewVersion('Gestionale ritoccato');
    $changed['plans'][0]['plan_version'] = 1; // stessa versione del catalogo in vigore, ma con altri contenuti
    $this->post('/saas/admin/catalogo/bozza', ['document' => json_encode($changed)])->assertRedirect('/saas/admin/catalogo');

    $this->post('/saas/admin/catalogo/pubblica')->assertRedirect('/saas/admin/catalogo')->assertSessionHasErrors('publish');

    expect(DB::table('core.catalog_revisions')->count())->toBe(0);
});

it('non pubblica senza bozza', function (): void {
    $this->post('/saas/admin/catalogo/pubblica')->assertSessionHasErrors('publish');
});

it('conosce ancora le versioni dei piani che non sono più in vendita', function (): void {
    $this->post('/saas/admin/catalogo/bozza', ['document' => json_encode(draftWithNewVersion())]);
    $this->post('/saas/admin/catalogo/pubblica')->assertRedirect();

    $limits = app(PlanLimits::class);
    expect($limits->resolve('gestionale', 2, [], 10)['operators'])->toBe(1)
        // La versione 1 non è più nel catalogo servito, ma chi l'ha comprata resta sulla sua versione.
        ->and($limits->resolve('gestionale', 1, [], 10)['operators'])->toBe(1)
        ->and(fn () => $limits->resolve('gestionale', 9, [], 10))->toThrow(UnknownPlan::class);
});

it('al solo lettura mostra il catalogo ma non lo fa modificare', function (): void {
    $this->post('/saas/admin/esci');
    $readonly = makeStaff('lettura@example.test', 'readonly');
    loginStaff($this, $readonly);

    $this->get('/saas/admin/catalogo')->assertOk();
    $this->get('/saas/admin/catalogo/bozza')->assertOk()->assertSee('sola lettura');
    $this->post('/saas/admin/catalogo/bozza', ['document' => json_encode(draftWithNewVersion())])->assertForbidden();
    $this->post('/saas/admin/catalogo/pubblica')->assertForbidden();
});
