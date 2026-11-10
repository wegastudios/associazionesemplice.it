<?php

declare(strict_types=1);

use App\Modules\Core\Demo\DemoEnvironment;
use App\Modules\Soci\Demo\DemoMembers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
    config(['saas.demo.enabled' => true]);

    $org = app(DemoEnvironment::class)->seed();

    expect($org->slug)->toBe('polisportiva-demo');

    $roles = DB::table('core.org_users as o')->join('core.users as u', 'u.id', '=', 'o.user_id')
        ->where('o.org_id', $org->id)->pluck('o.role', 'u.email')->all();
    expect($roles)->toBe([
        DemoEnvironment::email('titolare') => 'owner',
        DemoEnvironment::email('segreteria') => 'operator',
        DemoEnvironment::email('commercialista') => 'accountant',
    ]);

    $people = DB::table('app.people')->where('org_id', $org->id);
    expect($people->count())->toBe(DemoMembers::COUNT)
        ->and(DB::table('app.people')->where('org_id', $org->id)->where('email', 'not like', '%@'.config('saas.demo.mail_domain'))->count())->toBe(0)
        ->and(DB::table('app.memberships')->where('org_id', $org->id)->count())->toBeGreaterThan(10);
});

it('mostra il banner della demo solo dove è accesa', function (): void {
    config(['saas.demo.enabled' => true]);
    $this->get('/accesso')->assertSee('i dati sono inventati', false);

    config(['saas.demo.enabled' => false]);
    $this->get('/accesso')->assertDontSee('i dati sono inventati', false);
});
