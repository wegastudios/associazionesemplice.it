<?php

declare(strict_types=1);

use App\Modules\Backup\Backups;
use App\Modules\Backup\Crypto\BackupCipher;
use App\Modules\Soci\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
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
        ->and($stored)->toStartWith("ASBK\x02")
        ->and($stored)->not->toContain('Rossi')
        ->and($stored)->not->toContain('PGDMP');

    // Se il ripristino fallisce, il messaggio del comando è nel risultato del test.
    expect(Artisan::call('saas:backup:restore-test'))->toBe(0, Artisan::output());

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

/**
 * Mette nello spazio dei backup un file cifrato con l'intestazione data (non un vero dump: servono solo i controlli
 * che precedono il ripristino) e la sua descrizione.
 *
 * @param  array<string, string>  $meta
 * @param  array<string, mixed>  $declared
 */
function plantBackup(string $path, array $meta, array $declared = []): void
{
    $in = fopen('php://memory', 'w+b');
    fwrite($in, 'non e un dump');
    rewind($in);
    $out = fopen('php://memory', 'w+b');
    BackupCipher::encrypt($in, $out, (string) base64_decode((string) config('saas.backup.public_key'), true), $meta);
    rewind($out);
    $bytes = (string) stream_get_contents($out);

    $disk = Storage::disk('backups');
    $disk->put($path, $bytes);
    $disk->put($path.'.json', (string) json_encode($declared + ['database' => 'shared', 'sha256' => hash('sha256', $bytes), 'tables' => []]));
}

it('rifiuta un dump vecchio spacciato per uno recente', function (): void {
    $this->createOrg('Polisportiva Alfa');
    $backups = app(Backups::class);
    $old = $backups->dump('shared', CarbonImmutable::now()->subHours(5));
    $new = $backups->dump('shared', CarbonImmutable::now());

    // Chi scrive sullo spazio dei backup mette il file vecchio sotto il nome del nuovo e aggiorna l'impronta.
    $disk = Storage::disk('backups');
    $disk->put($new['path'], (string) $disk->get($old['path']));
    $manifest = json_decode((string) $disk->get($new['path'].'.json'), true);
    $manifest['sha256'] = $old['sha256'];
    $disk->put($new['path'].'.json', (string) json_encode($manifest));

    expect(fn () => $backups->restoreTest('shared'))->toThrow(RuntimeException::class, 'ora del backup');
});

it('rifiuta un dump di un altro database, di un altro ambiente o con l\'ora sbagliata', function (array $meta, array $declared, string $message): void {
    $at = CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC');
    $path = 'testing/shared/2026/11/shared-'.$at->format('Ymd\THis\Z').'.dump.enc';
    plantBackup($path, $meta + ['created_at' => $at->format('Y-m-d\TH:i:s\Z'), 'database' => 'shared', 'env' => 'testing'], $declared);

    expect(fn () => app(Backups::class)->restoreTest('shared', $path))->toThrow(RuntimeException::class, $message);
})->with([
    'altro database' => [['database' => 'dedicato'], [], 'altro database'],
    'altro ambiente' => [['env' => 'production'], [], 'altro ambiente'],
    'ora diversa dal nome' => [['created_at' => '2026-11-19T10:00:00Z'], [], 'ora del backup'],
    'ora diversa dalla descrizione' => [[], ['created_at' => '2026-11-20T09:00:00Z'], 'descrizione'],
    'descrizione di un altro database' => [[], ['database' => 'dedicato'], 'altro database'],
]);

it('rifiuta un dump con l\'ora nel futuro', function (): void {
    $at = CarbonImmutable::now('UTC')->addDay();
    $path = 'testing/shared/2026/11/shared-'.$at->format('Ymd\THis\Z').'.dump.enc';
    plantBackup($path, ['created_at' => $at->format('Y-m-d\TH:i:s\Z'), 'database' => 'shared', 'env' => 'testing']);

    expect(fn () => app(Backups::class)->restoreTest('shared', $path))->toThrow(RuntimeException::class, 'futuro');
});

it('legge ancora i backup del formato vecchio, senza controllare database e ora', function (): void {
    $pair = (string) base64_decode((string) config('saas.backup.secret_key'), true);
    $public = sodium_crypto_box_publickey($pair);
    $fileKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
    $bytes = "ASBK\x01".sodium_crypto_box_seal($fileKey, $public).$header
        .sodium_crypto_secretstream_xchacha20poly1305_push($state, 'non e un dump', "ASBK\x01", SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);

    $path = 'testing/shared/2026/11/shared-20261120T100000Z.dump.enc';
    $disk = Storage::disk('backups');
    $disk->put($path, $bytes);
    $disk->put($path.'.json', (string) json_encode(['database' => 'shared', 'sha256' => hash('sha256', $bytes), 'tables' => []]));

    // Supera i controlli sul contenuto e si ferma solo perché non è un vero dump per pg_restore.
    expect(fn () => app(Backups::class)->restoreTest('shared', $path))->toThrow(RuntimeException::class, 'pg_restore');
});

it('rifiuta la prova di ripristino se il database di prova è quello principale', function (): void {
    foreach (['host', 'port', 'database'] as $key) {
        config(["database.connections.pgsql_restore.{$key}" => config("database.connections.pgsql.{$key}")]);
    }
    DB::purge('pgsql_restore');

    $this->createOrg('Polisportiva Alfa');
    $backups = app(Backups::class);
    $backups->dump('shared', CarbonImmutable::now());

    expect(fn () => $backups->restoreTest('shared'))->toThrow(RuntimeException::class, 'coincide');
    expect(DB::table('core.orgs')->count())->toBe(1);
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
