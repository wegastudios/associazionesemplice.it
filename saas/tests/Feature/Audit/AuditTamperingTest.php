<?php

declare(strict_types=1);

use App\Modules\Audit\Log\ChainVerifier;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Chi ha accesso diretto al database (il proprietario) e altera il registro viene scoperto dalla verifica.
 * Il proprietario non vede la transazione del test: qui i dati si confermano davvero e si cancellano alla fine.
 */

beforeEach(function (): void {
    DB::commit();
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($this->org->id, function (): void {
        foreach (range(1, 4) as $i) {
            Person::create(['first_name' => "Socio {$i}", 'last_name' => 'Prova']);
        }
    });
    $this->owner = DB::connection('pgsql_owner');
    $this->owner->select("SELECT set_config('app.org_id', ?, false)", [$this->org->id]);
});

afterEach(function (): void {
    $this->owner->statement('ALTER TABLE audit.log ENABLE TRIGGER log_append_only');
    $this->owner->select("SELECT set_config('app.org_id', '', false)");
    $this->owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $this->owner->statement('TRUNCATE core.org_keys, core.org_users, core.orgs CASCADE');
    DB::beginTransaction();
});

function tamper(string $sql): void
{
    $owner = DB::connection('pgsql_owner');
    $owner->statement('ALTER TABLE audit.log DISABLE TRIGGER log_append_only');
    $owner->statement($sql);
    $owner->statement('ALTER TABLE audit.log ENABLE TRIGGER log_append_only');
}

it('il trigger ferma anche il proprietario', function (): void {
    expect(fn () => $this->owner->statement("UPDATE audit.log SET action = 'person.viewed' WHERE seq = 2"))
        ->toThrow(QueryException::class, 'solo in aggiunta');
});

it('trova una riga modificata', function (): void {
    tamper("UPDATE audit.log SET details = '{\"fields\": [\"first_name\"]}' WHERE seq = 2");

    expect(app(ChainVerifier::class)->verify($this->org->id))->toMatchArray(['ok' => false, 'broken_at' => 2, 'checked' => 1]);
});

it('trova una riga cancellata nel mezzo o in fondo', function (int $seq, string $reason): void {
    tamper("DELETE FROM audit.log WHERE seq = {$seq}");

    expect(app(ChainVerifier::class)->verify($this->org->id))->toMatchArray(['ok' => false, 'reason' => $reason]);
})->with([
    'nel mezzo' => [2, 'numero mancante o ripetuto'],
    'in fondo' => [4, 'righe mancanti in fondo alla catena'],
]);

it('trova una riga aggiunta con un sigillo inventato', function (): void {
    tamper("INSERT INTO audit.log (org_id, occurred_at, seq, actor_type, action, hash) VALUES ('{$this->org->id}', now(), 3, 'system', 'person.deleted', '\\x00')");

    expect(app(ChainVerifier::class)->verify($this->org->id)['ok'])->toBeFalse();
});

it('trova un registro riscritto da capo senza la chiave', function (): void {
    // Anche ricostruendo una catena coerente, senza la chiave dell'associazione i sigilli non tornano.
    tamper('UPDATE audit.log SET hash = sha256(hash)');
    $this->owner->statement('UPDATE audit.heads SET hash = (SELECT hash FROM audit.log ORDER BY seq DESC LIMIT 1)');

    expect(app(ChainVerifier::class)->verify($this->org->id))->toMatchArray(['ok' => false, 'broken_at' => 1]);
});
