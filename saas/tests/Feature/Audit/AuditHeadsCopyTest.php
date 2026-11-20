<?php

declare(strict_types=1);

use App\Modules\Audit\Log\ChainVerifier;
use App\Modules\Audit\Log\HeadCopies;
use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Soci\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * La copia giornaliera di numero e sigillo di testa, fuori dal database, fa vedere ciò che la catena da sola non vede:
 * le ultime righe tolte insieme alla testa, un'ancora riscritta. Chi altera il database è il proprietario: i dati si
 * confermano davvero e si cancellano alla fine.
 */

beforeEach(function (): void {
    DB::commit();
    Storage::disk('backups')->deleteDirectory('testing/audit-heads');
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($this->org->id, function (): void {
        foreach (range(1, 4) as $i) {
            Person::create(['first_name' => "Socio {$i}", 'last_name' => 'Prova']);
        }
    });
    $this->owner = DB::connection('pgsql_owner');
});

afterEach(function (): void {
    $this->owner->statement('ALTER TABLE audit.log ENABLE TRIGGER log_append_only');
    $this->owner->select("SELECT set_config('app.org_id', '', false)");
    $this->owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $this->owner->statement('TRUNCATE core.org_keys, core.org_users, core.orgs CASCADE');
    Storage::disk('backups')->deleteDirectory('testing/audit-heads');
    DB::beginTransaction();
});

/** Toglie l'ultima riga e riporta la testa a quella di prima: dentro il database la catena torna. */
function cutLastRow(): void
{
    $owner = DB::connection('pgsql_owner');
    $owner->select("SELECT set_config('app.org_id', ?, false)", [test()->org->id]);
    $owner->statement('ALTER TABLE audit.log DISABLE TRIGGER log_append_only');
    $last = (int) $owner->scalar('SELECT max(seq) FROM audit.log');
    $owner->statement("DELETE FROM audit.log WHERE seq = {$last}");
    $owner->statement('UPDATE audit.heads SET seq = seq - 1, hash = (SELECT hash FROM audit.log ORDER BY seq DESC LIMIT 1)');
    $owner->statement('ALTER TABLE audit.log ENABLE TRIGGER log_append_only');
}

it('senza una copia esterna la coda tagliata e la testa riportata indietro non si vedono; con la copia sì', function (): void {
    $verifier = app(ChainVerifier::class);
    expect($verifier->verify($this->org->id)['ok'])->toBeTrue();

    $this->artisan('saas:audit:heads:copy')->assertSuccessful();
    cutLastRow();

    $result = app(ChainVerifier::class)->verify($this->org->id);
    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toContain('copia esterna');
});

it('con la copia esterna una catena intatta, anche con righe nuove dopo la copia, resta valida', function (): void {
    $this->artisan('saas:audit:heads:copy')->assertSuccessful();
    $this->orgContext()->run($this->org->id, fn () => Person::create(['first_name' => 'Nuovo', 'last_name' => 'Socio']));

    expect(app(ChainVerifier::class)->verify($this->org->id)['ok'])->toBeTrue();
});

it('rifiuta una copia esterna alterata o firmata da altri', function (): void {
    $this->artisan('saas:audit:heads:copy')->assertSuccessful();
    $disk = Storage::disk('backups');
    $path = $disk->allFiles('testing/audit-heads')[0];
    $file = json_decode((string) $disk->get($path), true);
    $file['data'] = str_replace('"v":1', '"v":2', $file['data']);
    $disk->put($path, (string) json_encode($file));

    $result = app(ChainVerifier::class)->verify($this->org->id);
    expect($result['ok'])->toBeFalse()->and($result['reason'])->toContain('non autentica');
});

it('vede un\'ancora riscritta o tolta rispetto alla copia', function (): void {
    $this->owner->select("SELECT set_config('app.org_id', ?, false)", [$this->org->id]);
    $this->owner->statement('INSERT INTO audit.anchors (org_id, seq, hash) SELECT org_id, seq, hash FROM audit.log WHERE seq = 2');
    $anchorHash = Bytea::decode($this->owner->table('audit.anchors')->value('hash'));

    $this->artisan('saas:audit:heads:copy')->assertSuccessful();
    $copies = app(HeadCopies::class);
    $this->owner->select("SELECT set_config('app.org_id', ?, false)", [$this->org->id]);
    $connection = $this->owner;
    $head = $this->owner->table('audit.heads')->first(['seq', 'hash']);
    $headHash = Bytea::decode($head->hash);
    $conflict = fn (?array $anchor): ?string => $copies->conflict($this->org->id, $connection, (int) $head->seq, $headHash, $anchor);

    expect($conflict(['seq' => 2, 'hash' => $anchorHash]))->toBeNull()
        ->and($conflict(['seq' => 3, 'hash' => $anchorHash]))->toBeNull() // la pulizia ha spostato l'ancora avanti
        ->and($conflict(['seq' => 2, 'hash' => random_bytes(32)]))->toContain('ancora')
        ->and($conflict(['seq' => 1, 'hash' => $anchorHash]))->toContain('ancora')
        ->and($conflict(null))->toContain('ancora');
});

it('la verifica esce con errore se la copia giornaliera si è fermata', function (): void {
    $this->artisan('saas:audit:heads:copy')->assertSuccessful();
    $this->artisan('saas:audit:verify')->assertSuccessful();

    $this->travelTo(CarbonImmutable::now()->addDays(4));
    $this->artisan('saas:audit:verify')->assertFailed();
});

it('pulisce le copie più vecchie di 400 giorni', function (): void {
    $copies = app(HeadCopies::class);
    $copies->export(CarbonImmutable::now()->subDays(401));

    $result = $copies->export(CarbonImmutable::now());

    expect($result['removed'])->toBe(1)
        ->and(Storage::disk('backups')->allFiles('testing/audit-heads'))->toHaveCount(1);
});
