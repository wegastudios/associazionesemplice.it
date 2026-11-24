<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Demo\DemoEnvironment;
use App\Modules\Soci\Demo\DemoMembers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('non parte se la demo è spenta', function (): void {
    config(['saas.demo.enabled' => false]);

    expect(Artisan::call('saas:demo:reset'))->toBe(1);
    expect(fn () => app(DemoEnvironment::class)->seed())->toThrow(RuntimeException::class);
});

it('non parte mai in produzione, nemmeno con il comando acceso', function (): void {
    demoOn();
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(DemoEnvironment::class)->enabled())->toBeFalse()
        ->and(Artisan::call('saas:demo:reset'))->toBe(1);
});

it('crea l\'associazione di fantasia, gli utenti pubblici per ruolo e i soci finti', function (): void {
    demoOn(['saas.demo.people' => DemoMembers::COUNT]);

    $org = app(DemoEnvironment::class)->seed();

    expect($org->slug)->toBe('polisportiva-demo');

    $roles = DB::table('core.org_users as o')->join('core.users as u', 'u.id', '=', 'o.user_id')
        ->where('o.org_id', $org->id)->pluck('o.role', 'u.email')->all();
    expect($roles)->toEqual([
        DemoEnvironment::email('titolare') => 'owner',
        DemoEnvironment::email('amministratore') => 'admin',
        DemoEnvironment::email('segreteria') => 'operator',
        DemoEnvironment::email('dipendente') => 'employee',
        DemoEnvironment::email('commercialista') => 'accountant',
    ]);

    // Le tabelle dell'associazione si leggono solo con l'associazione impostata (sicurezza per riga).
    [$people, $foreign, $memberships] = app(OrgContext::class)->run($org->id, fn (): array => [
        DB::table('app.people')->count(),
        DB::table('app.people')->where('email', 'not like', '%@'.config('saas.demo.mail_domain'))->count(),
        DB::table('app.memberships')->count(),
    ]);
    expect($people)->toBe(DemoMembers::COUNT + DemoMembers::ENTITIES)
        ->and($foreign)->toBe(0)
        ->and($memberships)->toBeGreaterThan(10);

    // I dati di contorno: partita IVA d'esempio, attività e risorse con IVA, anni chiusi e archiviato, un anno in disavanzo, cassa mai negativa.
    app(OrgContext::class)->run($org->id, function (): void {
        $year = App\Modules\Core\Contracts\DemoScale::currentYear();
        $status = DB::table('app.fiscal_years')->pluck('status', 'year')->all();
        $result = static fn (int $y): int => (int) DB::table('app.transactions')->where('fiscal_year', $y)->whereNull('voided_at')->where('type', 'income')->sum('amount_cents')
            - (int) DB::table('app.transactions')->where('fiscal_year', $y)->whereNull('voided_at')->where('type', 'expense')->sum('amount_cents');

        expect(app(App\Modules\Core\Contracts\OrgFiscal::class)->hasVat())->toBeTrue()
            ->and($status[$year - 3])->toBe('closed')->and($status[$year - 2])->toBe('closed')->and($status[$year - 1])->toBe('open')
            ->and(DB::table('app.year_archives')->count())->toBe(1)
            ->and($result($year - 3))->toBeGreaterThan(0)->and($result($year - 2))->toBeGreaterThan(0)
            ->and($result($year - 1))->toBeLessThan(-250000)->and($result($year - 1))->toBeGreaterThan(-350000)
            ->and(min(app(App\Modules\Contabilita\Contracts\Ledger::class)->balances()))->toBeGreaterThanOrEqual(0)
            ->and(DB::table('app.activities')->whereNotNull('vat_rate')->count())->toBeGreaterThan(3)
            ->and(DB::table('app.resources')->whereNotNull('vat_rate')->count())->toBe(7)
            ->and(DB::table('app.reservations')->where('kind', 'booking')->count())->toBeGreaterThan(20)
            ->and(DB::table('app.receipts')->count())->toBeGreaterThan(50);
    });
});

it('mostra il banner della demo solo dove è accesa', function (): void {
    demoOn();
    $this->get('/accesso')->assertSee('Dati finti: la demo si azzera ogni notte alle 03:30.', false)->assertSee('role="status"', false);

    config(['saas.demo.enabled' => false]);
    $this->get('/accesso')->assertDontSee('Dati finti', false);

    // SAAS_DEMO acceso ma ambiente diverso da «demo»: nessun banner.
    config(['saas.demo.enabled' => true]);
    app()->detectEnvironment(fn (): string => 'staging');
    $this->get('/accesso')->assertDontSee('Dati finti', false);
});

it('con SAAS_DEMO acceso ma ambiente non «demo» non nasce nessun account né password pubblica', function (string $env): void {
    config(['saas.demo.enabled' => true, 'saas.demo.password' => 'password-di-prova-demo']);
    app()->detectEnvironment(fn (): string => $env);

    expect(DemoEnvironment::credentials())->toBe([])
        ->and(App\Modules\Core\Contracts\DemoScale::active())->toBeFalse()
        ->and(app(DemoEnvironment::class)->enabled())->toBeFalse()
        ->and(Artisan::call('saas:demo:reset'))->toBe(1);
    $users = DB::table('core.users')->count();
    expect(fn () => app(DemoEnvironment::class)->seed())->toThrow(RuntimeException::class)
        ->and(DB::table('core.users')->count())->toBe($users);
    $this->get('/accesso')->assertInertia(fn (Assert $page) => $page->has('demoAccounts', 0));
})->with(['staging', 'local', 'testing', 'production']);

it('la password pubblica vuota vale quella predefinita, mai una password vuota', function (): void {
    expect(config('saas.demo.password'))->toBeString()->not->toBe('')
        ->and(strlen((string) config('saas.demo.password')))->toBeGreaterThanOrEqual(App\Modules\Core\Demo\DemoEnvCheck::MIN_PASSWORD);

    // Configurazione sbagliata a mano: l'azzeramento non crea utenti con password vuota.
    demoOn(['saas.demo.password' => '']);
    $users = DB::table('core.users')->count();
    expect(fn () => app(DemoEnvironment::class)->seed())->toThrow(RuntimeException::class)
        ->and(DB::table('core.users')->count())->toBe($users);
});

it('il piano della demo ha i soci e lo spazio coerenti con i tetti, e i dati di partenza non li superano', function (): void {
    demoOn(['saas.demo.people' => DemoMembers::COUNT]);
    $org = app(DemoEnvironment::class)->seed();

    $claims = json_decode((string) DB::table('core.org_entitlements')->where('org_id', $org->id)->value('claims'), true);
    app(OrgContext::class)->run($org->id, function (App\Modules\Core\Contracts\CurrentOrg $current) use ($claims): void {
        $counts = App\Modules\Core\Demo\DemoLimits::counts($current->connection);
        $settings = app(App\Modules\Core\Contracts\OrgSettings::class);
        foreach ($counts as $key => $n) {
            // Il conteggio di partenza è scritto: il tetto (conteggio + margine) sta sopra ai dati di partenza.
            expect($settings->int(App\Modules\Core\Demo\DemoLimits::BASELINE.$key, -1))->toBe($n)
                ->and($settings->int(App\Modules\Core\Demo\DemoLimits::BASELINE.$key, -1) + App\Modules\Core\Contracts\DemoScale::limit($key.'_extra'))->toBeGreaterThan($n);
        }
        expect($claims['members'])->toBe($counts['people'] + App\Modules\Core\Contracts\DemoScale::limit('people_extra'))
            ->and($claims['storage_gb'])->toBe(1);
    });
});

it('lo spazio file della demo è il suo tetto, non quello del piano', function (): void {
    demoOn();
    $files = Mockery::mock(App\Modules\Cifratura\Contracts\EncryptedFiles::class);
    $entitlements = Mockery::mock(App\Modules\Portale\Contracts\Entitlements::class);
    $entitlements->shouldNotReceive('for');
    $quota = new App\Modules\Cifratura\Files\StorageQuotaService($entitlements, $files);
    $cap = App\Modules\Core\Contracts\DemoScale::storageBytes();

    expect($cap)->toBe(300 * 1024 * 1024);
    $files->shouldReceive('usedBytes')->andReturn($cap - 100);
    $quota->assertRoom('org', 100);
    expect(fn () => $quota->assertRoom('org', 101))->toThrow(App\Modules\Cifratura\Contracts\StorageFull::class);
});

it('mostra gli accessi di prova con la password solo sulla demo', function (): void {
    demoOn(['saas.demo.password' => 'password-di-prova-demo']);
    $this->get('/accesso')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')
        ->has('demoAccounts', 5)
        ->where('demoAccounts.0.email', DemoEnvironment::email('titolare'))
        ->where('demoAccounts.0.password', 'password-di-prova-demo'));

    config(['saas.demo.enabled' => false]);
    $this->get('/accesso')->assertInertia(fn (Assert $page) => $page->has('demoAccounts', 0));

    demoOn();
    app()->detectEnvironment(fn (): string => 'production');
    $this->get('/accesso')->assertInertia(fn (Assert $page) => $page->has('demoAccounts', 0));
});

it('legge l\'interruttore della demo come un valore vero o falso, non come testo', function (): void {
    foreach (['false', '0', 'off', 'no', '', false] as $off) {
        config(['saas.demo.enabled' => $off]);
        expect(app(DemoEnvironment::class)->enabled())->toBeFalse();
    }
    demoOn(['saas.demo.enabled' => 'true']);
    expect(app(DemoEnvironment::class)->enabled())->toBeTrue();
});
