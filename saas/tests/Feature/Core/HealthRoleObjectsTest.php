<?php

declare(strict_types=1);

use App\Modules\Core\Platform\RoleObjectAudit;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, list<string>>  $override
 * @return array{unexpectedSchemas: list<string>, owned: list<string>, groupGrants: list<string>, createDatabase: list<string>, createSchema: list<string>, ownerMembers: list<string>, setRole: list<string>, backupWrites?: list<string>}
 */
function healthRoleFacts(array $override = []): array
{
    return [
        'unexpectedSchemas' => $override['unexpectedSchemas'] ?? [],
        'owned' => $override['owned'] ?? [],
        'groupGrants' => $override['groupGrants'] ?? [],
        'backupWrites' => $override['backupWrites'] ?? [],
        'createDatabase' => $override['createDatabase'] ?? [],
        'createSchema' => $override['createSchema'] ?? [],
        'ownerMembers' => $override['ownerMembers'] ?? [],
        'setRole' => $override['setRole'] ?? [],
    ];
}

it('senza anomalie la valutazione dei ruoli è vuota', function (): void {
    expect(RoleObjectAudit::evaluate(healthRoleFacts()))->toBe(['problems' => [], 'critical' => [], 'notes' => []]);
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
    ['backupWrites', 'sola lettura'],
    ['createDatabase', 'REVOKE CREATE ON DATABASE'],
    ['createSchema', 'REVOKE CREATE ON SCHEMA'],
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

it('app o backup membri del ruolo proprietario sono sempre un errore, a parte dagli altri problemi, e senza note', function (): void {
    $result = RoleObjectAudit::evaluate(healthRoleFacts(['ownerMembers' => ['applicazione'], 'setRole' => ['backup']]));

    expect($result['problems'])->toBe([])
        ->and($result['critical'])->toHaveCount(1)
        ->and($result['critical'][0])->toContain('membro del ruolo proprietario')->toContain('(applicazione)')
        ->and($result['notes'])->toBe([]);
});

it('la voce sulla separazione dal ruolo proprietario non è tra i controlli morbidi, quella sugli oggetti sì', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Modules/Core/Console/HealthCommand.php');
    $start = (int) strpos($source, '$soft = [');
    $soft = substr($source, $start, (int) strpos($source, '];', $start) - $start);

    expect($soft)->toContain("'oggetti e ruoli di gruppo'")
        ->and($soft)->not->toContain('separazione dal ruolo proprietario')
        ->and($source)->toContain("'separazione dal ruolo proprietario' => fn () => \$this->roleObjects(\$db, \$databases, true)");
});

it('sul database di prova la separazione dal ruolo proprietario è verde', function (): void {
    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    separazione dal ruolo proprietario')
        ->assertSuccessful();
});

it('il dettaglio di saas:health non esce nel log del rilascio né da endpoint HTTP', function (): void {
    $base = dirname(__DIR__, 3);

    // Rilascio: l'uscita va su un file riservato, nel log solo l'esito e il conteggio.
    $remote = (string) file_get_contents($base.'/deploy/remote.sh');
    expect($remote)->toContain('> "$file" 2>&1')
        ->and($remote)->toContain('echo "controllo di salute: ok (')
        ->and($remote)->toContain('umask 077')
        ->and(substr_count($remote, 'artisan saas:health'))->toBe(1);

    // Manutenzione sul server: l'uscita filtrata va nel file, a schermo solo i conteggi.
    $ops = (string) file_get_contents($base.'/deploy/ops.sh');
    expect($ops)->toContain('artisan saas:health --skip-migrations 2>&1 | grep -E')
        ->and($ops)->toContain('>> "$F" || true');

    // Nessun codice dell'applicazione (rotte, controller, API verso il portale) lancia saas:health.
    $hits = [];
    foreach (['app', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$base}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            if (str_contains($text, "'saas:health'") && ! str_ends_with($file->getPathname(), 'HealthCommand.php')) {
                $hits[] = $file->getPathname();
            }
        }
    }
    expect($hits)->toBe([]);
});

it('il ruolo dei backup con un permesso di scrittura, anche su una sola colonna, è un problema', function (string $statement, string $revoke, string $expected): void {
    $owner = DB::connection('pgsql_owner');
    config(['saas.health_strict' => true]);
    $owner->statement($statement);
    try {
        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain($expected)
            ->assertFailed();
    } finally {
        $owner->statement($revoke);
    }

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    oggetti e ruoli di gruppo')
        ->assertSuccessful();
})->with([
    'tabella intera' => ['GRANT DELETE ON core.org_notices TO as_backup', 'REVOKE DELETE ON core.org_notices FROM as_backup', 'il ruolo dei backup può scrivere, direttamente o per eredità (core.org_notices (DELETE))'],
    'una colonna' => ['GRANT UPDATE (kind) ON core.org_notices TO as_backup', 'REVOKE UPDATE (kind) ON core.org_notices FROM as_backup', 'core.org_notices (UPDATE)'],
    'TRUNCATE' => ['GRANT TRUNCATE ON core.org_notices TO as_backup', 'REVOKE TRUNCATE ON core.org_notices FROM as_backup', 'core.org_notices (TRUNCATE)'],
]);

it('il ruolo dei backup con UPDATE su una sequenza è un problema', function (): void {
    $owner = DB::connection('pgsql_owner');
    config(['saas.health_strict' => true]);
    $owner->statement('GRANT UPDATE ON SEQUENCE core.jobs_id_seq TO as_backup');
    try {
        $this->artisan('saas:health', ['--skip-migrations' => true])
            ->expectsOutputToContain('sequenza core.jobs_id_seq (UPDATE)')
            ->assertFailed();
    } finally {
        $owner->statement('REVOKE UPDATE ON SEQUENCE core.jobs_id_seq FROM as_backup');
    }
});

it('i controlli sul catalogo vedono anche funzioni, colonne e privilegi predefiniti dei gruppi (la query gira)', function (): void {
    $facts = RoleObjectAudit::inspect(DB::connection('pgsql_backup'), 'as_app', 'as_backup', 'as_owner');

    expect($facts['groupGrants'])->toBe([])
        ->and($facts['backupWrites'] ?? null)->toBe([]);

    // Un gruppo con permessi su funzioni, colonne o privilegi predefiniti è un problema con il rimedio.
    $result = RoleObjectAudit::evaluate(healthRoleFacts(['groupGrants' => ['funzione core.x (EXECUTE)', 'colonna app.y.z (UPDATE)', 'privilegi predefiniti di ogni schema (SELECT)']]));
    expect($result['problems'])->toHaveCount(1)
        ->and($result['problems'][0])->toContain('funzione core.x')->toContain('colonna app.y.z')->toContain('privilegi predefiniti')->toContain('ALTER DEFAULT PRIVILEGES');
});
