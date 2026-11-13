<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::commit();
});

afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $owner->statement('TRUNCATE core.org_keys, core.org_users, core.org_roles, core.org_entitlements, core.org_notices, core.orgs, core.users CASCADE');
    DB::beginTransaction();
});

it('la prova della cancellazione riesce e non lascia associazioni di prova', function (): void {
    $this->createOrg('Polisportiva Alfa');

    $this->artisan('saas:selftest:purge')->expectsOutputToContain('Prova riuscita')->assertSuccessful();

    expect(DB::connection('pgsql_owner')->table('core.orgs')->where('slug', 'like', 'prova-%')->count())->toBe(0);
});
