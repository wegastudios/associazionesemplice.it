<?php

declare(strict_types=1);

use App\Modules\Core\Platform\RoleObjectAudit;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, list<string>>  $override
 * @return array{unexpectedSchemas: list<string>, owned: list<string>, groupGrants: list<string>, createDatabase: list<string>, createSchema: list<string>, ownerMembers: list<string>, setRole: list<string>}
 */
function healthRoleFacts(array $override = []): array
{
    return [
        'unexpectedSchemas' => $override['unexpectedSchemas'] ?? [],
        'owned' => $override['owned'] ?? [],
        'groupGrants' => $override['groupGrants'] ?? [],
        'createDatabase' => $override['createDatabase'] ?? [],
        'createSchema' => $override['createSchema'] ?? [],
        'ownerMembers' => $override['ownerMembers'] ?? [],
        'setRole' => $override['setRole'] ?? [],
    ];
}

it('senza anomalie la valutazione dei ruoli è vuota', function (): void {
    expect(RoleObjectAudit::evaluate(healthRoleFacts()))->toBe(['problems' => [], 'notes' => []]);
});

it('ogni anomalia dei ruoli è un problema con il rimedio', function (string $key, string $expected): void {
    $result = RoleObjectAudit::evaluate(healthRoleFacts([$key => ['x']]));

    expect($result['problems'])->toHaveCount(1)
        ->and($result['problems'][0])->toContain($expected)
        ->and($result['notes'])->toBe([]);
})->with([
    ['unexpectedSchemas', 'DROP SCHEMA'],
    ['owned', 'OWNER TO'],
    ['groupGrants', 'REVOKE'],
    ['createDatabase', 'REVOKE CREATE ON DATABASE'],
    ['createSchema', 'REVOKE CREATE ON SCHEMA'],
    ['ownerMembers', 'membro del ruolo proprietario'],
]);

it('il SET ROLE verso il ruolo di gruppo è un limite noto solo se il resto è in ordine', function (): void {
    $ok = RoleObjectAudit::evaluate(healthRoleFacts(['setRole' => ['applicazione', 'backup']]));
    expect($ok['problems'])->toBe([])
        ->and($ok['notes'])->toHaveCount(1)
        ->and($ok['notes'][0])->toContain('limite noto')->toContain('applicazione e backup');

    $bad = RoleObjectAudit::evaluate(healthRoleFacts(['setRole' => ['applicazione'], 'owned' => ['tabella app.x']]));
    expect($bad['problems'])->toHaveCount(1)
        ->and($bad['notes'])->toBe([]);
});

it('sul database di prova il controllo dei ruoli di gruppo è verde', function (): void {
    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    oggetti e ruoli di gruppo')
        ->assertSuccessful();
});

it('segnala uno schema inatteso e lo accetta se elencato; senza strict è solo un avviso', function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('CREATE SCHEMA zz_intruso');
    try {
        config(['saas.health_strict' => true]);
        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain('ERRORE oggetti e ruoli di gruppo: shared: schemi inattesi (zz_intruso)')
            ->assertFailed();

        config(['saas.health_strict' => false]);
        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain('AVVISO oggetti e ruoli di gruppo')
            ->assertSuccessful();

        config(['saas.health_strict' => true, 'saas.health_extra_schemas' => ['zz_intruso']]);
        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain('ok    oggetti e ruoli di gruppo')
            ->assertSuccessful();
    } finally {
        $owner->statement('DROP SCHEMA IF EXISTS zz_intruso');
    }
});

it('segnala il CREATE su uno schema dato all\'applicazione o ai backup', function (string $role, string $label): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('CREATE SCHEMA zz_prova');
    try {
        config(['saas.health_strict' => true, 'saas.health_extra_schemas' => ['zz_prova']]);
        $owner->statement("GRANT CREATE ON SCHEMA zz_prova TO {$role}");

        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain("CREATE su schemi non previsto ({$label}: zz_prova)")
            ->assertFailed();
    } finally {
        $owner->statement('DROP SCHEMA IF EXISTS zz_prova');
    }
})->with([['as_app', 'applicazione'], ['as_backup', 'backup']]);

it('segnala il CREATE sul database dato all\'applicazione', function (): void {
    $owner = DB::connection('pgsql_owner');
    $database = (string) config('database.connections.pgsql.database');
    config(['saas.health_strict' => true]);
    $owner->statement("GRANT CREATE ON DATABASE {$database} TO as_app");
    try {
        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain('CREATE sul database (applicazione)')
            ->assertFailed();
    } finally {
        $owner->statement("REVOKE CREATE ON DATABASE {$database} FROM as_app");
    }
});
