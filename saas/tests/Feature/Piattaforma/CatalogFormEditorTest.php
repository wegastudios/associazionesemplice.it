<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Catalogo\CatalogForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/StaffHelpers.php';
require_once dirname(__DIR__).'/Portale/PortalHelpers.php';

/*
 * Editor a moduli di piani, pacchetti e promozioni (stessa bozza e stesso validatore dell'editor JSON).
 */

beforeEach(function (): void {
    fakePortal();
    Http::fake(['*' => Http::response([], 200)]);
    $this->staff = makeStaff();
    loginStaff($this, $this->staff);
});

/**
 * Il modulo del piano «gestionale» come lo invierebbe il browser, a partire dal catalogo iniziale.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function planFormInput(array $overrides = []): array
{
    /** @var array<string, mixed> $catalog */
    $catalog = config('catalogo');
    $plan = $catalog['plans'][0];
    $features = [];
    foreach ($plan['features'] as $id => $state) {
        $features[$id] = ['state' => $state['state'], 'text' => $state['text'] ?? ''];
    }

    return $overrides + [
        'name' => $plan['name'],
        'audience' => $plan['audience'],
        'order' => (string) $plan['order'],
        'visible' => $plan['visible'] ? '1' : '',
        'support_level' => $plan['support_level'],
        'trial_days' => (string) $plan['trial_days'],
        'limits' => ['members' => '', 'operators' => '1', 'storage_mb_per_member' => '5', 'storage_mb_min' => '250'],
        'features' => $features,
        'prices' => CatalogForm::priceRows($plan['prices']),
    ];
}

it('capisce gli importi in euro e rifiuta quelli illeggibili', function (): void {
    expect(CatalogForm::cents('15'))->toBe(1500)
        ->and(CatalogForm::cents('15,5'))->toBe(1550)
        ->and(CatalogForm::cents(' 19.90 € '))->toBe(1990)
        ->and(CatalogForm::cents(''))->toBeNull()
        ->and(CatalogForm::cents('abc'))->toBe('abc')
        ->and(CatalogForm::cents('1,234'))->toBe('1,234')
        ->and(CatalogForm::euros(1550))->toBe('15,50')
        ->and(CatalogForm::int('12'))->toBe(12)
        ->and(CatalogForm::int(''))->toBeNull()
        ->and(CatalogForm::int('1x'))->toBe('1x');
});

it('porta gli stessi prezzi dal catalogo al modulo e ritorno', function (): void {
    /** @var array<string, mixed> $catalog */
    $catalog = config('catalogo');
    $prices = $catalog['plans'][0]['prices'];

    expect(CatalogForm::prices(CatalogForm::priceRows($prices)))->toEqual($prices);
});

it('mostra piani, pacchetti e promozioni con i prezzi calcolati', function (): void {
    $this->get('/saas/admin/catalogo/piani')->assertOk()->assertSee('Gestionale')->assertSee('in vigore');
    $this->get('/saas/admin/catalogo/piani/gestionale')->assertOk()
        ->assertSee('Quanto costa')
        ->assertSee('15,00')   // 50 soci: la base
        ->assertSee('Questa versione è già in vigore');
    $this->get('/saas/admin/catalogo/pacchetti')->assertOk()->assertSee('Area soci e pagamenti')->assertSee('Database dedicato');
    $this->get('/saas/admin/catalogo/pacchetti/site')->assertOk();
    $this->get('/saas/admin/catalogo/promozioni')->assertOk()->assertSee('Nuova promozione');
    $this->get('/saas/admin/catalogo/piani/inesistente')->assertNotFound();
});

it('salvare un piano già in vigore crea la versione successiva nella bozza e il confronto la mostra', function (): void {
    $this->post('/saas/admin/catalogo/piani/gestionale', planFormInput(['name' => 'Gestionale Plus']))
        ->assertRedirect('/saas/admin/catalogo/piani/gestionale');

    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    expect(array_column($draft['plans'], 'plan_version'))->toBe([1, 1, 1, 1, 2])
        ->and($draft['plans'][0]['name'])->toBe('Gestionale')
        ->and($draft['plans'][4]['name'])->toBe('Gestionale Plus')
        ->and($draft['plans'][4]['prices'])->toEqual($draft['plans'][0]['prices']);

    $this->get('/saas/admin/catalogo')->assertOk()->assertSee('nuova versione 2')->assertSee('cambiano nome');

    // Una seconda modifica ritocca la versione 2 (ancora in bozza) senza crearne una terza.
    $this->post('/saas/admin/catalogo/piani/gestionale', planFormInput(['name' => 'Gestionale Pro']))->assertRedirect();
    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    expect(array_column($draft['plans'], 'plan_version'))->toBe([1, 1, 1, 1, 2])->and($draft['plans'][4]['name'])->toBe('Gestionale Pro');

    $this->post('/saas/admin/catalogo/pubblica')->assertRedirect('/saas/admin/catalogo');
    $published = json_decode((string) $this->get('/api/catalogo/v1')->getContent(), true);
    expect($published['rev'])->toBe(3)->and(array_column($published['plans'], 'plan_version'))->toBe([1, 1, 1, 1, 2]);
});

it('cambia un prezzo dal modulo e il confronto lo dice', function (): void {
    $input = planFormInput();
    $input['prices'][0]['base'] = '16,00';

    $this->post('/saas/admin/catalogo/piani/gestionale', $input)->assertRedirect();

    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    expect($draft['plans'][4]['prices'][0]['base_cents'])->toBe(1600);
    $this->get('/saas/admin/catalogo')->assertSee('cambiano prezzi');
});

it('non salva un modulo non valido e mostra l\'errore con quello che era stato scritto', function (): void {
    $input = planFormInput(['name' => 'Gestionale Plus']);
    $input['prices'][0]['base'] = 'tanto';

    $this->post('/saas/admin/catalogo/piani/gestionale', $input)->assertOk()
        ->assertSee('base_cents')
        ->assertSee('Gestionale Plus')
        ->assertSee('tanto');

    expect(DB::table('core.catalog_draft')->exists())->toBeFalse();
});

it('crea un piano nuovo e un pacchetto nuovo', function (): void {
    $plan = planFormInput(['name' => 'Gratuito', 'support_level' => 'free', 'visible' => '1']);
    $plan['prices'] = [['kind' => 'flat', 'period' => 'month', 'vat_percent' => '22', 'amount' => '0']];
    $this->post('/saas/admin/catalogo/piani/nuovo', $plan + ['code' => 'gratuito'])->assertRedirect('/saas/admin/catalogo/piani/gratuito');

    $this->post('/saas/admin/catalogo/pacchetti/nuovo', [
        'code' => 'backup_extra',
        'kind' => 'package',
        'name' => 'Backup extra',
        'visible' => '1',
        'grant_package' => 'backup_extra',
        'grant_limits' => '{"daily": 1}',
        'prices' => [['kind' => 'flat', 'period' => 'month', 'vat_percent' => '22', 'amount' => '4,90']],
    ])->assertRedirect('/saas/admin/catalogo/pacchetti');

    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    $plan = collect($draft['plans'])->firstWhere('code', 'gratuito');
    $addon = collect($draft['addons'])->firstWhere('code', 'backup_extra');
    expect($plan['plan_version'])->toBe(1)->and($plan['support_level'])->toBe('free')
        ->and($addon['prices'][0]['amount_cents'])->toBe(490)
        ->and($addon['grants'])->toEqual(['package' => 'backup_extra', 'limits' => ['daily' => 1]]);

    // Lo stesso codice due volte e un codice scritto male sono rifiutati.
    $this->post('/saas/admin/catalogo/pacchetti/nuovo', ['code' => 'backup_extra', 'kind' => 'package', 'name' => 'Doppio', 'grant_package' => 'x',
        'prices' => [['kind' => 'flat', 'period' => 'month', 'vat_percent' => '22', 'amount' => '1']]])->assertOk()->assertSee('esiste già');
    $this->post('/saas/admin/catalogo/piani/nuovo', planFormInput() + ['code' => 'Piano Nuovo!'])->assertOk()->assertSee('non valido');
});

it('modifica un pacchetto esistente senza poterne cambiare il tipo', function (): void {
    $this->post('/saas/admin/catalogo/pacchetti/site', [
        'kind' => 'operators', // ignorato: il tipo di un pacchetto già creato non cambia
        'name' => 'Sito web',
        'visible' => '1',
        'grant_package' => 'site',
        'prices' => [['kind' => 'flat', 'period' => 'month', 'vat_percent' => '22', 'amount' => '21']],
    ])->assertRedirect('/saas/admin/catalogo/pacchetti');

    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    $addon = collect($draft['addons'])->firstWhere('code', 'site');
    expect($addon['kind'])->toBe('package')->and($addon['name'])->toBe('Sito web')->and($addon['prices'][0]['amount_cents'])->toBe(2100);
    $this->get('/saas/admin/catalogo')->assertSee('Sito web');
});

it('salva e toglie promozioni per piano', function (): void {
    $promo = ['name' => 'Autunno', 'code' => 'autunno26', 'type' => 'percent', 'value' => '20', 'valid_from' => '2026-11-15', 'valid_to' => '2026-12-31', 'max_uses' => '100', 'plans' => ['gestionale']];

    $this->post('/saas/admin/catalogo/promozioni', ['promos' => [$promo, ['name' => '', 'code' => '', 'type' => 'percent', 'value' => '']]])
        ->assertRedirect('/saas/admin/catalogo/promozioni');
    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    expect($draft['promos'])->toHaveCount(1)
        ->and($draft['promos'][0]['code'])->toBe('AUTUNNO26')
        ->and($draft['promos'][0]['discount'])->toBe(['type' => 'percent', 'value' => 20])
        ->and($draft['promos'][0]['plans'])->toBe(['gestionale'])
        ->and($draft['promos'][0]['stackable'])->toBeFalse();
    $this->get('/saas/admin/catalogo/promozioni')->assertSee('Autunno')->assertSee('AUTUNNO26');

    // Sconto in euro e fine prima dell'inizio: rifiutato, la bozza resta com'era.
    $bad = ['name' => 'Errata', 'code' => '', 'type' => 'amount_cents', 'value' => '5', 'valid_from' => '2026-12-31', 'valid_to' => '2026-12-01'];
    $this->post('/saas/admin/catalogo/promozioni', ['promos' => [$promo, $bad]])->assertOk()->assertSee('la fine è prima dell');
    expect(json_decode((string) DB::table('core.catalog_draft')->value('document'), true)['promos'])->toHaveCount(1);

    $this->post('/saas/admin/catalogo/promozioni', ['promos' => [$promo + ['delete' => '1']]])->assertRedirect();
    expect(json_decode((string) DB::table('core.catalog_draft')->value('document'), true)['promos'])->toBe([]);
});

it('chi è in sola lettura vede tutto ma non salva', function (): void {
    $this->post('/saas/admin/esci');
    $reader = makeStaff('lettura@example.test', 'readonly');
    loginStaff($this, $reader);

    $this->get('/saas/admin/catalogo/piani')->assertOk();
    $this->get('/saas/admin/catalogo/piani/gestionale')->assertOk()->assertSee('sola lettura');
    $this->post('/saas/admin/catalogo/piani/gestionale', planFormInput())->assertForbidden();
    $this->post('/saas/admin/catalogo/promozioni', ['promos' => []])->assertForbidden();
    $this->get('/saas/admin/catalogo/piani/nuovo')->assertForbidden();
    expect(DB::table('core.catalog_draft')->exists())->toBeFalse();
});

it('imposta i pacchetti inclusi nel piano e rifiuta un testo che non è un oggetto', function (): void {
    $this->get('/saas/admin/catalogo/piani/gestionale')->assertOk()->assertSee('Pacchetti già inclusi');

    $this->post('/saas/admin/catalogo/piani/gestionale', planFormInput(['packages_json' => '{"site": {}, "einvoice": {"yearly": 50}}']))->assertRedirect();
    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    expect($draft['plans'][4]['limits']['packages'])->toEqual(['site' => [], 'einvoice' => ['yearly' => 50]]);
    $this->get('/saas/admin/catalogo/piani/gestionale')->assertSee('einvoice');

    // Senza il campo restano quelli della versione; con «{}» si tolgono.
    $this->post('/saas/admin/catalogo/piani/gestionale', planFormInput())->assertRedirect();
    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    expect($draft['plans'][4]['limits']['packages'])->toHaveKey('site');
    $this->post('/saas/admin/catalogo/piani/gestionale', planFormInput(['packages_json' => '{}']))->assertRedirect();
    $draft = json_decode((string) DB::table('core.catalog_draft')->value('document'), true);
    expect($draft['plans'][4]['limits']['packages'])->toBe([]);

    $this->post('/saas/admin/catalogo/piani/gestionale', planFormInput(['packages_json' => '[1,2]']))->assertOk()->assertSee('devono essere un oggetto JSON');
});
