<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\FieldCipher;
use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Contracts\OrgKeys;
use App\Modules\Cifratura\Contracts\Sealer;
use App\Modules\Cifratura\Keys\KeyCache;
use App\Modules\Cifratura\Keys\KeyRing;
use App\Modules\Cifratura\Keys\LocalKeyVault;
use App\Modules\Cifratura\Keys\RecoveryFile;
use App\Modules\Cifratura\Keys\VaultFactory;
use App\Modules\Soci\Models\Person;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use KeyService\KeyWrap;
use Tests\Fixtures\FakeKeyService;

/*
 * Passaggio delle chiavi dati dalla chiave principale locale al servizio chiavi e ritorno. Il comando scrive con il
 * ruolo proprietario, che non vede la transazione del test: qui i dati si confermano davvero e si cancellano alla fine.
 */

beforeEach(function (): void {
    DB::commit();
    $this->service = (new FakeKeyService)->install();
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    });
});

afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $owner->statement('TRUNCATE core.org_keys, core.org_users, core.orgs CASCADE');
    $owner->statement('TRUNCATE core.platform_audit');
    DB::beginTransaction();
});

/** @return array<string, string> chiave avvolta per «org:versione» */
function wrappedKeys(): array
{
    $rows = [];
    foreach (DB::connection('pgsql_owner')->table('core.org_keys')->orderBy('org_id')->orderBy('version')->get() as $row) {
        $rows[$row->org_id.':'.$row->version] = Bytea::decode($row->wrapped_key);
    }

    return $rows;
}

/** Chiavi dati in chiaro, aperte con il custode dato (null = non si aprono). @return array<string, string|null> */
function dataKeys(KeyVault $vault): array
{
    $keys = [];
    foreach (wrappedKeys() as $id => $wrapped) {
        [$org, $version] = explode(':', $id);
        try {
            $keys[$id] = $vault->unwrap($org, (int) $version, $wrapped);
        } catch (Throwable) {
            $keys[$id] = null;
        }
    }

    return $keys;
}

function testLocalVault(): LocalKeyVault
{
    return new LocalKeyVault(base64_decode(LocalKeyVault::TEST_MASTER_KEY));
}

it('passa al servizio chiavi e ritorna: le chiavi dati restano le stesse, il comando si può ripetere', function (): void {
    $this->createOrg('Polisportiva Alfa');
    $this->createOrg('Circolo Beta');
    $original = dataKeys(testLocalVault());
    $count = count($original);
    expect($count)->toBeGreaterThanOrEqual(2)->and(array_filter($original))->toHaveCount($count);
    $remote = app(VaultFactory::class)->remote();

    // Prova: tutto controllato, nulla scritto.
    $before = wrappedKeys();
    $this->artisan('saas:keys:rewrap', ['verso' => 'remote', '--dry-run' => true])
        ->expectsOutputToContain("chiavi da riavvolgere: {$count}")
        ->assertSuccessful();
    expect(wrappedKeys())->toBe($before)
        ->and(DB::table('core.platform_audit')->where('action', 'keys.rewrap')->count())->toBe(0);

    $this->artisan('saas:keys:rewrap', ['verso' => 'remote', '--force' => true])
        ->expectsOutputToContain("chiavi riavvolte: {$count}")
        ->doesntExpectOutputToContain(LocalKeyVault::TEST_MASTER_KEY)
        ->assertSuccessful();
    expect(dataKeys($remote))->toBe($original)
        ->and(array_filter(dataKeys(testLocalVault())))->toBe([]);

    // Di nuovo: niente da fare.
    $this->travel(1)->minutes();
    $this->artisan('saas:keys:rewrap', ['verso' => 'remote', '--force' => true])
        ->expectsOutputToContain('chiavi riavvolte: 0')
        ->expectsOutputToContain("già a posto: {$count}")
        ->assertSuccessful();

    // Ritorno alla chiave locale.
    $this->travel(1)->minutes();
    $this->artisan('saas:keys:rewrap', ['verso' => 'local', '--force' => true])
        ->expectsOutputToContain("chiavi riavvolte: {$count}")
        ->assertSuccessful();
    expect(dataKeys(testLocalVault()))->toBe($original)
        ->and(array_filter(dataKeys($remote)))->toBe([]);

    $rows = DB::table('core.platform_audit')->where('action', 'keys.rewrap')->orderBy('created_at')->pluck('details')->map(fn ($d) => json_decode((string) $d, true))->all();
    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray(['to' => 'remote', 'rewrapped' => $count, 'failed' => 0])
        ->and($rows[1])->toMatchArray(['to' => 'remote', 'rewrapped' => 0, 'already' => $count])
        ->and($rows[2])->toMatchArray(['to' => 'local', 'rewrapped' => $count]);

    $secrets = [LocalKeyVault::TEST_MASTER_KEY, base64_encode($this->service->masterKey), base64_encode($this->service->signingKey)];
    foreach ($secrets as $secret) {
        expect(implode("\n", $this->logged))->not->toContain($secret);
    }
});

it('dopo il passaggio i dati cifrati si leggono con il servizio chiavi', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $person = $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U']));

    $this->artisan('saas:keys:rewrap', ['verso' => 'remote', '--force' => true])->assertSuccessful();

    config(['saas.crypto.vault' => 'remote', 'saas.crypto.master_key' => null]);
    foreach ([KeyVault::class, KeyCache::class, KeyRing::class, OrgKeys::class, FieldCipher::class, Sealer::class, EncryptedFiles::class] as $service) {
        $this->app->forgetInstance($service);
    }
    $calls = $this->service->calls;

    expect($this->orgContext()->run($org->id, fn () => Person::findOrFail($person->id)->tax_code))->toBe('RSSMRA80A01H501U')
        ->and($this->service->calls)->toBeGreaterThan($calls);
});

it('un\'associazione con una chiave che non si apre resta com\'era, le altre passano', function (): void {
    $bad = $this->createOrg('Polisportiva Alfa');
    $this->createOrg('Circolo Beta');
    $broken = Bytea::encode((new KeyWrap(random_bytes(32)))->wrap($bad->id, 1, random_bytes(32)));
    DB::connection('pgsql_owner')->table('core.org_keys')->where('org_id', $bad->id)->where('version', 1)->update(['wrapped_key' => $broken]);
    $before = wrappedKeys();

    $this->artisan('saas:keys:rewrap', ['verso' => 'remote', '--force' => true])
        ->expectsOutputToContain("non riuscita: {$bad->id}")
        ->assertFailed();

    $after = wrappedKeys();
    foreach ($before as $id => $wrapped) {
        if (str_starts_with($id, $bad->id.':')) {
            expect($after[$id])->toBe($wrapped);
        } else {
            expect($after[$id])->not->toBe($wrapped);
        }
    }
    expect(json_decode((string) DB::table('core.platform_audit')->where('action', 'keys.rewrap')->value('details'), true))->toMatchArray(['failed' => 1]);
});

it('con il servizio chiavi giù non tocca nulla', function (): void {
    $this->createOrg('Polisportiva Alfa');
    $before = wrappedKeys();
    $this->service->down = true;

    $this->artisan('saas:keys:rewrap', ['verso' => 'remote', '--force' => true])
        ->expectsOutputToContain('Custodi della chiave non pronti')
        ->assertFailed();

    expect(wrappedKeys())->toBe($before);
});

it('rifiuta un verso sconosciuto e, senza conferma, non fa nulla', function (): void {
    $this->createOrg('Polisportiva Alfa');
    $before = wrappedKeys();

    $this->artisan('saas:keys:rewrap', ['verso' => 'altrove'])->assertFailed();
    $this->artisan('saas:keys:rewrap', ['verso' => 'remote'])
        ->expectsConfirmation('Riavvolgere le chiavi dati di tutte le associazioni verso il servizio chiavi? (Prima: php artisan down)', 'no')
        ->assertFailed();

    expect(wrappedKeys())->toBe($before);
});

it('la chiave locale si distrugge solo dopo 30 giorni, con il servizio in uso e senza SAAS_MASTER_KEY', function (): void {
    $this->createOrg('Polisportiva Alfa');

    $this->artisan('saas:keys:local-retire', ['--distruggi' => true, '--force' => true])
        ->expectsOutputToContain('Nessun passaggio al servizio chiavi registrato')
        ->assertFailed();

    $this->artisan('saas:keys:rewrap', ['verso' => 'remote', '--force' => true])->assertSuccessful();

    $this->artisan('saas:keys:local-retire', ['--distruggi' => true, '--force' => true])
        ->expectsOutputToContain('Troppo presto')
        ->assertFailed();

    $this->travel(31)->days();

    // Il SaaS usa ancora la chiave locale.
    $this->artisan('saas:keys:local-retire', ['--distruggi' => true, '--force' => true])
        ->expectsOutputToContain('SAAS_KEY_VAULT non è remote')
        ->assertFailed();

    config(['saas.crypto.vault' => 'remote']);
    $this->artisan('saas:keys:local-retire', ['--distruggi' => true, '--force' => true])
        ->expectsOutputToContain('SAAS_MASTER_KEY è ancora nel file d\'ambiente')
        ->assertFailed();

    config(['saas.crypto.master_key' => null]);
    $dir = sys_get_temp_dir().'/as-retire-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $old = $dir.'/vecchia.askr';
    file_put_contents($old, RecoveryFile::seal(['saas_master_key' => LocalKeyVault::TEST_MASTER_KEY], 'una frase segreta di prova lunga', SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE));
    $other = $dir.'/appunti.txt';
    file_put_contents($other, 'non è una copia');

    // Un file che non è una copia di recupero non si tocca, e non si distrugge nulla.
    $this->artisan('saas:keys:local-retire', ['--distruggi' => true, '--force' => true, '--file' => [$other]])
        ->expectsOutputToContain('non è una copia di recupero')
        ->assertFailed();
    expect(file_exists($other))->toBeTrue();

    $this->artisan('saas:keys:local-retire', ['--distruggi' => true, '--force' => true, '--file' => [$old]])
        ->expectsOutputToContain('Distruzione della chiave locale registrata')
        ->assertSuccessful();
    expect(file_exists($old))->toBeFalse()
        ->and(DB::table('core.platform_audit')->where('action', 'keys.local_destroyed')->count())->toBe(1);

    $this->artisan('saas:keys:local-retire')->expectsOutputToContain('già registrata')->assertSuccessful();

    unlink($other);
    rmdir($dir);
});

it('dopo il ritorno alla chiave locale non c\'è nulla da distruggere', function (): void {
    $this->createOrg('Polisportiva Alfa');
    $this->artisan('saas:keys:rewrap', ['verso' => 'remote', '--force' => true])->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('saas:keys:rewrap', ['verso' => 'local', '--force' => true])->assertSuccessful();
    $this->travel(31)->days();
    config(['saas.crypto.vault' => 'remote', 'saas.crypto.master_key' => null]);

    $this->artisan('saas:keys:local-retire', ['--distruggi' => true, '--force' => true])
        ->expectsOutputToContain('ritorno alla chiave locale')
        ->assertFailed();
});
