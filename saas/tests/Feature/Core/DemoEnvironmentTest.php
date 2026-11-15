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
    config(['saas.demo.enabled' => true]);
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(DemoEnvironment::class)->enabled())->toBeFalse()
        ->and(Artisan::call('saas:demo:reset'))->toBe(1);
});

it('crea l\'associazione di fantasia, gli utenti pubblici per ruolo e i soci finti', function (): void {
    config(['saas.demo.enabled' => true, 'saas.demo.people' => DemoMembers::COUNT]);

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
    config(['saas.demo.enabled' => true]);
    $this->get('/accesso')->assertSee('demo con dati inventati', false);

    config(['saas.demo.enabled' => false]);
    $this->get('/accesso')->assertDontSee('demo con dati inventati', false);
});

it('mostra gli accessi di prova con la password solo sulla demo', function (): void {
    config(['saas.demo.enabled' => true, 'saas.demo.password' => 'password-di-prova-demo']);
    $this->get('/accesso')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')
        ->has('demoAccounts', 5)
        ->where('demoAccounts.0.email', DemoEnvironment::email('titolare'))
        ->where('demoAccounts.0.password', 'password-di-prova-demo'));

    config(['saas.demo.enabled' => false]);
    $this->get('/accesso')->assertInertia(fn (Assert $page) => $page->has('demoAccounts', 0));

    config(['saas.demo.enabled' => true]);
    app()->detectEnvironment(fn (): string => 'production');
    $this->get('/accesso')->assertInertia(fn (Assert $page) => $page->has('demoAccounts', 0));
});
