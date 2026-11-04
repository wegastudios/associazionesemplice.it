<?php

declare(strict_types=1);

use App\Modules\Backup\Backups;
use App\Modules\Soci\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Dump, cifratura, ripristino nel database di prova. pg_dump e pg_restore lavorano su connessioni proprie: i dati si
 * confermano davvero e si cancellano alla fine.
 */

beforeEach(function (): void {
    $pair = sodium_crypto_box_keypair();
    config([
        'saas.backup.public_key' => base64_encode(sodium_crypto_box_publickey($pair)),
        'saas.backup.secret_key' => base64_encode($pair),
    ]);
    $this->app->forgetInstance(Backups::class);
    Storage::disk('backups')->deleteDirectory('testing');
    DB::commit();
});

afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $owner->statement('TRUNCATE core.org_keys, core.org_users, core.org_entitlements, core.orgs CASCADE');
    Storage::disk('backups')->deleteDirectory('testing');
    DB::beginTransaction();
});

it('fa un dump cifrato e lo ripristina con le stesse righe', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $this->orgContext()->run($alfa->id, function (): void {
        Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U']);
        Person::create(['first_name' => 'Maria', 'last_name' => 'Rossi']);
    });
    $this->orgContext()->run($beta->id, fn () => Person::create(['first_name' => 'Bruno', 'last_name' => 'Bianchi']));

    $this->artisan('saas:backup:dump', ['--database' => ['shared']])->assertSuccessful();

    $backups = app(Backups::class);
    $path = (string) $backups->latest('shared');
    $stored = (string) Storage::disk('backups')->get($path);
    $manifest = json_decode((string) Storage::disk('backups')->get($path.'.json'), true);

    // Il dump ha visto tutte le righe di tutte le associazioni, ed è illeggibile senza la chiave privata.
    expect($manifest['tables']['app.people'])->toBe(3)
        ->and($manifest['tables']['app.orgs'])->toBe(2)
        ->and($manifest['tables']['audit.log_default'] + array_sum(array_filter($manifest['tables'], fn ($n, $t) => str_starts_with($t, 'audit.log_2'), ARRAY_FILTER_USE_BOTH)))->toBeGreaterThan(0)
        ->and($stored)->toStartWith("ASBK\x01")
        ->and($stored)->not->toContain('Rossi')
        ->and($stored)->not->toContain('PGDMP');

    $this->artisan('saas:backup:restore-test')->assertSuccessful();

    $restored = DB::connection('pgsql_restore')->table('app.people')->orderBy('first_name')->pluck('first_name')->all();
    expect($restored)->toBe(['Bruno', 'Maria', 'Mario']);
});

it('si accorge di un backup alterato e non ripristina', function (): void {
    $this->createOrg('Polisportiva Alfa');
    $backups = app(Backups::class);
    $path = $backups->dump('shared', CarbonImmutable::now())['path'];

    $disk = Storage::disk('backups');
    $bytes = (string) $disk->get($path);
    $bytes[200] = $bytes[200] === 'a' ? 'b' : 'a';
    $disk->put($path, $bytes);

    expect(fn () => $backups->restoreTest('shared'))->toThrow(RuntimeException::class, 'Impronta');
});

it('il ruolo dei backup legge tutto ma non scrive nulla', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi']));

    $backup = DB::connection('pgsql_backup');
    expect($backup->table('app.people')->count())->toBe(1)
        ->and(fn () => $backup->table('app.people')->update(['first_name' => 'X']))->toThrow(Illuminate\Database\QueryException::class, 'permission denied')
        ->and(fn () => $backup->table('core.users')->delete())->toThrow(Illuminate\Database\QueryException::class, 'permission denied');
});

it('rifiuta la prova di ripristino in produzione', function (): void {
    $this->app->instance('env', 'production');

    $this->artisan('saas:backup:restore-test')->assertFailed();
});

it('tiene 48 ore di dump orari e poi uno al giorno per 30 giorni', function (): void {
    $disk = Storage::disk('backups');
    $now = CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC');
    for ($h = 0; $h < 24 * 40; $h += 6) {
        $at = $now->subHours($h);
        $disk->put(sprintf('testing/shared/%s/shared-%s.dump.enc', $at->format('Y/m'), $at->format('Ymd\THis\Z')), 'x');
    }

    $deleted = app(Backups::class)->prune('shared', $now);
    $left = collect($disk->allFiles('testing/shared'))->filter(fn (string $f) => str_ends_with($f, '.enc'))->count();

    // 48 ore: 9 dump (ogni 6 ore, compreso adesso). Prima: il primo dump di ogni giorno, dal 4 ottobre (30 giorni fa,
    // dalle 12) al 2 novembre: 29 giorni.
    expect($left)->toBe(9 + 29)->and($deleted)->toBe(160 - $left);
});
