<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Audit\Log\ChainVerifier;
use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Contracts\NoOrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\HealthRecord;

/** @return list<object> */
function auditRows(string $orgId): array
{
    return test()->orgContext()->run($orgId, fn () => DB::table('audit.log')->orderBy('seq')->get()->all());
}

it('annota creazione, modifica e cancellazione con i nomi dei campi, mai i valori', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $this->actingAs($user = $this->createUser('anna@example.com'));

    $this->orgContext()->run($org->id, function (): void {
        $person = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U']);
        $person->update(['phone' => '+39 333 1234567']);
        $person->update(['phone' => '+39 333 1234567']); // stesso valore cifrato di nuovo: è comunque una modifica
        $person->delete();
    });

    $rows = auditRows($org->id);
    $actions = array_column($rows, 'action');
    expect($actions)->toBe(['person.created', 'person.updated', 'person.updated', 'person.deleted'])
        ->and(array_column($rows, 'seq'))->toBe([1, 2, 3, 4])
        ->and($rows[0]->actor_type)->toBe('user')
        ->and($rows[0]->actor_id)->toBe($user->id)
        ->and(json_decode($rows[0]->details, true)['fields'])->toBe(['first_name', 'last_name', 'tax_code'])
        ->and(json_decode($rows[1]->details, true)['fields'])->toBe(['phone']);

    $all = (string) json_encode(array_map(static fn (object $r): array => array_diff_key((array) $r, ['hash' => 0]), $rows));
    expect($all)->not->toContain('Mario')->not->toContain('Rossi')->not->toContain('RSSMRA')->not->toContain('333');
});

it('tiene una catena per associazione che si verifica', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    foreach ([$alfa, $beta, $alfa] as $org) {
        $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'X', 'last_name' => 'Y']));
    }

    $verifier = app(ChainVerifier::class);
    expect($verifier->verify($alfa->id))->toBe(['ok' => true, 'checked' => 2, 'broken_at' => null, 'reason' => null])
        ->and($verifier->verify($beta->id)['checked'])->toBe(1)
        ->and(array_column(auditRows($beta->id), 'seq'))->toBe([1]);

    $this->artisan('saas:audit:verify', ['org' => $alfa->id])->assertSuccessful();
});

it('si accorge di una testa della catena alterata', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($org->id, function (): void {
        Person::create(['first_name' => 'X', 'last_name' => 'Y']);
        DB::table('audit.heads')->update(['seq' => 5]);
    });

    expect(app(ChainVerifier::class)->verify($org->id)['ok'])->toBeFalse();
    $this->artisan('saas:audit:verify', ['org' => $org->id])->assertFailed();
});

it('non lascia modificare o cancellare il registro all\'applicazione', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');

    $this->orgContext()->run($org->id, function (): void {
        Person::create(['first_name' => 'X', 'last_name' => 'Y']);

        expect(fn () => DB::transaction(fn () => DB::table('audit.log')->update(['action' => 'person.viewed'])))
            ->toThrow(QueryException::class, 'permission denied')
            ->and(fn () => DB::transaction(fn () => DB::table('audit.log')->delete()))
            ->toThrow(QueryException::class, 'permission denied')
            ->and(fn () => DB::transaction(fn () => DB::table('audit.log_default')->count()))
            ->toThrow(QueryException::class, 'permission denied');
    });
});

it('scrive nel registro solo dentro un\'associazione e accetta solo dettagli semplici', function (): void {
    $audit = app(AuditLog::class);
    expect(fn () => $audit->record('person.viewed'))->toThrow(NoOrgContext::class);

    $org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($org->id, function () use ($audit): void {
        expect(fn () => $audit->record('Person Viewed'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $audit->record('person.viewed', null, null, ['amount' => 1.5]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $audit->record('person.viewed', null, null, ['note' => str_repeat('x', 201)]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $audit->record('person.viewed', null, null, ['Nota' => 'x']))->toThrow(InvalidArgumentException::class);

        $audit->record('export.done', null, null, ['rows' => 3, 'format' => 'csv', 'with_header' => true, 'columns' => ['a', 'b']]);
    });

    expect(app(ChainVerifier::class)->verify($org->id)['ok'])->toBeTrue();
});

it('annota le letture dei dati sanitari, una volta per richiesta', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');

    $this->orgContext()->run($org->id, function (): void {
        $record = HealthRecord::create(['first_name' => 'X', 'last_name' => 'Y', 'phone' => 'diagnosi']);
        $fresh = HealthRecord::findOrFail($record->id);
        $fresh->phone;
        $fresh->phone;
        HealthRecord::findOrFail($record->id)->phone;

        $reads = DB::table('audit.log')->where('action', 'health.read')->get();
        expect($reads)->toHaveCount(1)
            ->and($reads[0]->subject_id)->toBe($record->id)
            ->and(json_decode($reads[0]->details, true))->toBe(['fields' => ['phone']]);
    });
});

it('annota inviti e cambi di ruolo nel registro dell\'associazione', function (): void {
    Notification::fake();
    $org = $this->createOrg('Polisportiva Alfa');
    $members = app(OrgMembers::class);
    $owner = $members->invite($org, 'titolare@example.com', 'Titolare', OrgRole::Owner, null);
    $by = app(App\Modules\Core\Access\Memberships::class)->find($owner->id, $org->id);
    $op = $members->invite($org, 'op@example.com', 'Op', OrgRole::Operator, $by);
    $members->changeRole($org, $op->id, OrgRole::Accountant, $by);
    $members->remove($org, $op->id, $by);

    $rows = auditRows($org->id);
    expect(array_column($rows, 'action'))->toBe(['operator.invited', 'operator.invited', 'operator.role_changed', 'operator.removed'])
        ->and(json_decode($rows[2]->details, true))->toEqual(['from' => 'operator', 'to' => 'accountant'])
        ->and($rows[3]->subject_id)->toBe($op->id);
});

it('annota quando un operatore apre l\'associazione, non ogni pagina', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user);

    $this->actingAs($user)->get('/gestionale')->assertOk();
    $this->get('/gestionale')->assertOk();

    $opened = array_values(array_filter(auditRows($org->id), fn (object $r): bool => $r->action === 'org.opened'));
    expect($opened)->toHaveCount(1)->and($opened[0]->actor_id)->toBe($user->id);
});

it('scrive il registro di un\'associazione dedicata nel suo database', function (): void {
    $this->useDedicatedDatabase();
    $org = $this->createOrg('Fondazione Gamma', $this::DEDICATED);

    $connection = $this->orgContext()->run($org->id, function () {
        Person::create(['first_name' => 'X', 'last_name' => 'Y']);

        return $this->orgContext()->require()->connection;
    });

    expect($this->orgContext()->run($org->id, fn () => DB::connection($connection)->table('audit.log')->count()))->toBe(1)
        ->and(DB::table('audit.heads')->count())->toBe(0)
        ->and(app(ChainVerifier::class)->verify($org->id)['ok'])->toBeTrue();
});

it('prepara le partizioni dei mesi successivi senza errori se esistono già', function (): void {
    $this->artisan('saas:audit:partitions', ['--months' => 4])->assertSuccessful();
    $this->artisan('saas:audit:partitions', ['--months' => 4])->assertSuccessful();

    $next = now()->utc()->startOfMonth()->addMonths(3)->format('Y_m');
    $exists = DB::connection('pgsql_owner')->selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', ["audit.log_{$next}"]);
    expect($exists->ok)->toBeTrue();
});
