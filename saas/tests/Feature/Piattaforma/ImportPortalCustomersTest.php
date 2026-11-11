<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Identity\StaffDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Importazione dei clienti del portale nell'elenco unico (docs/LOGIN_UNICO_E_DATABASE.md, passo 4). Il «portale» è un
 * file SQLite di prova con clienti inventati; nulla di reale.
 */

/** @param  list<array{id: string, email: string, name: ?string, password: ?string}>  $customers */
function portalCopy(array $customers): string
{
    $file = tempnam(sys_get_temp_dir(), 'portale-test-');
    $pdo = new PDO('sqlite:'.$file);
    $pdo->exec('CREATE TABLE customers (id TEXT PRIMARY KEY, email TEXT UNIQUE NOT NULL, created_at TEXT, password_hash TEXT, name TEXT)');
    $insert = $pdo->prepare('INSERT INTO customers (id, email, created_at, password_hash, name) VALUES (?, ?, ?, ?, ?)');
    foreach ($customers as $c) {
        // Come il portale: bcrypt su base64(sha256 binario).
        $hash = $c['password'] === null ? null : password_hash(base64_encode(hash('sha256', $c['password'], true)), PASSWORD_DEFAULT);
        $insert->execute([$c['id'], $c['email'], '2026-01-01 00:00:00', $hash, $c['name']]);
    }

    return (string) $file;
}

function portalFixture(): string
{
    return portalCopy([
        ['id' => str_repeat('a', 32), 'email' => 'Cliente.Uno@Example.test', 'name' => 'Cliente Uno', 'password' => 'la-password-del-portale-1'],
        ['id' => str_repeat('b', 32), 'email' => 'cliente.due@example.test', 'name' => null, 'password' => null],
        ['id' => str_repeat('c', 32), 'email' => 'staff@example.test', 'name' => 'Anche Staff', 'password' => 'la-password-del-portale-3'],
        ['id' => str_repeat('d', 32), 'email' => 'non-una-email', 'name' => 'Rotto', 'password' => null],
    ]);
}

it('la prova a vuoto non scrive nulla e dà i conteggi', function (): void {
    $this->artisan('saas:import:portal-customers', ['source' => portalFixture(), '--dry-run' => true])->expectsOutputToContain('Prova a vuoto')->assertSuccessful();

    expect(DB::table('core.staff_people')->count())->toBe(0);
});

it('importa i clienti nell\'elenco, collega chi c\'è già senza toccarne la password, ed è ripetibile', function (): void {
    $staffId = (string) Str::uuid7();
    DB::table('core.staff_people')->insert(['id' => $staffId, 'email' => 'staff@example.test', 'name' => 'Staff', 'password' => Hash::make('password-dello-staff-1'), 'active' => true]);
    $source = portalFixture();

    $this->artisan('saas:import:portal-customers', ['source' => $source])->expectsOutputToContain('Importazione finita')->assertSuccessful();

    $people = DB::table('core.staff_people')->orderBy('email')->get();
    // 2 nuovi (uno è rotto e si salta) + lo staff collegato.
    expect($people)->toHaveCount(3)
        ->and(DB::table('core.staff_people')->where('email', 'cliente.uno@example.test')->value('name'))->toBe('Cliente Uno')
        ->and(DB::table('core.staff_people')->where('email', 'cliente.due@example.test')->value('name'))->toBe('cliente.due')
        ->and(DB::table('core.staff_people')->where('id', $staffId)->value('portal_customer_id'))->toBe(str_repeat('c', 32))
        ->and(Hash::check('password-dello-staff-1', (string) DB::table('core.staff_people')->where('id', $staffId)->value('password')))->toBeTrue()
        ->and(DB::table('core.staff_people')->where('id', $staffId)->value('legacy_hash'))->toBeNull()
        ->and(DB::table('core.staff_roles')->count())->toBe(0);

    // Ripetendo: nessuna novità.
    $this->artisan('saas:import:portal-customers', ['source' => $source])->assertSuccessful();
    expect(DB::table('core.staff_people')->count())->toBe(3);
});

it('la verifica confronta il portale con l\'elenco e fallisce se manca qualcuno', function (): void {
    $source = portalFixture();
    $this->artisan('saas:import:portal-customers', ['source' => $source])->assertSuccessful();

    $this->artisan('saas:import:portal-customers', ['source' => $source, '--verify' => true])->expectsOutputToContain('Verifica riuscita')->assertSuccessful();

    DB::table('core.staff_people')->where('email', 'cliente.uno@example.test')->update(['portal_customer_id' => null]);
    $this->artisan('saas:import:portal-customers', ['source' => $source, '--verify' => true])->assertFailed();
});

it('il cliente entra con la password del portale; l\'impronta si riscrive nel formato del SaaS e non serve più', function (): void {
    $this->artisan('saas:import:portal-customers', ['source' => portalFixture()])->assertSuccessful();
    $directory = app(StaffDirectory::class);

    expect($directory->authenticate('cliente.uno@example.test', 'sbagliata-sbagliata'))->toBeNull()
        ->and(DB::table('core.staff_people')->where('email', 'cliente.uno@example.test')->value('legacy_hash'))->not->toBeNull();

    $person = $directory->authenticate('Cliente.Uno@example.test', 'la-password-del-portale-1');
    expect($person?->email)->toBe('cliente.uno@example.test')
        ->and(DB::table('core.staff_people')->where('email', 'cliente.uno@example.test')->value('legacy_hash'))->toBeNull()
        ->and(Hash::check('la-password-del-portale-1', (string) DB::table('core.staff_people')->where('email', 'cliente.uno@example.test')->value('password')))->toBeTrue();
    // Dopo la riscrittura vale il formato del SaaS.
    expect($directory->authenticate('cliente.uno@example.test', 'la-password-del-portale-1'))->not->toBeNull();
});

it('un cliente senza password non entra finché non la sceglie dal recupero; un cliente non ha aree', function (): void {
    $this->artisan('saas:import:portal-customers', ['source' => portalFixture()])->assertSuccessful();
    $directory = app(StaffDirectory::class);

    expect($directory->authenticate('cliente.due@example.test', ''))->toBeNull()
        ->and($directory->authenticate('cliente.due@example.test', 'qualunque-password-lunga'))->toBeNull();

    $one = $directory->authenticate('cliente.uno@example.test', 'la-password-del-portale-1');
    expect($one)->not->toBeNull()->and($directory->areas($one))->toBe([]);
});

it('annulla l\'importazione: toglie i clienti creati e scollega chi c\'era già', function (): void {
    // L'annullamento usa il ruolo proprietario, che non vede la transazione della prova: si chiude prima e si pulisce dopo.
    DB::commit();
    try {
        $staffId = (string) Str::uuid7();
        DB::table('core.staff_people')->insert(['id' => $staffId, 'email' => 'staff@example.test', 'name' => 'Staff', 'password' => Hash::make('password-dello-staff-1'), 'active' => true]);
        $this->artisan('saas:import:portal-customers', ['source' => portalFixture()])->assertSuccessful();

        $this->artisan('saas:import:portal-customers', ['source' => 'x', '--rollback' => true])->assertSuccessful();

        expect(DB::table('core.staff_people')->pluck('email')->all())->toBe(['staff@example.test'])
            ->and(DB::table('core.staff_people')->where('id', $staffId)->value('portal_customer_id'))->toBeNull();
    } finally {
        DB::connection('pgsql_owner')->statement('TRUNCATE core.staff_people CASCADE');
        DB::beginTransaction();
    }
});

it('rifiuta un file che non esiste', function (): void {
    $this->artisan('saas:import:portal-customers', ['source' => '/non/esiste.sqlite'])->assertFailed();
});
