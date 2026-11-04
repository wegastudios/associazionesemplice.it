<?php

declare(strict_types=1);

use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;

/*
 * Pacchetto Database dedicato: i dati dell'associazione stanno fisicamente in un altro database.
 */

beforeEach(function (): void {
    $this->useDedicatedDatabase();
});

it('scrive i dati di un\'associazione dedicata solo nel suo database', function (): void {
    $shared = $this->createOrg('Polisportiva Alfa');
    $dedicated = $this->createOrg('Fondazione Gamma', $this::DEDICATED);

    $this->orgContext()->run($shared->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Alfa']));
    $current = $this->orgContext()->run($dedicated->id, function () {
        Person::create(['first_name' => 'Gina', 'last_name' => 'Gamma']);

        return $this->orgContext()->require();
    });

    expect($current->connection)->not->toBe('pgsql');

    // Nel database condiviso, anche impostando a mano l'associazione dedicata, non c'è nulla.
    DB::select("SELECT set_config('app.org_id', ?, false)", [$dedicated->id]);
    expect(DB::table('app.people')->count())->toBe(0)
        ->and(DB::table('app.orgs')->count())->toBe(0);
    DB::select("SELECT set_config('app.org_id', '', false)");

    // Nel database dedicato c'è solo lei.
    expect($this->orgContext()->run($dedicated->id, fn () => Person::pluck('first_name')->all()))->toBe(['Gina'])
        ->and($this->orgContext()->run($dedicated->id, fn () => DB::connection($current->connection)->table('app.orgs')->pluck('id')->all()))->toBe([$dedicated->id]);
});

it('passando a un\'associazione dedicata azzera il database condiviso', function (): void {
    $shared = $this->createOrg('Polisportiva Alfa');
    $dedicated = $this->createOrg('Fondazione Gamma', $this::DEDICATED);

    $this->orgContext()->enter($shared->id);
    expect($this->orgSetting())->toBe($shared->id);

    $current = $this->orgContext()->enter($dedicated->id);
    expect($this->orgSetting())->toBe('')
        ->and($this->orgSetting($current->connection))->toBe($dedicated->id);

    $this->orgContext()->leave();
    expect($this->orgSetting($current->connection))->toBe('');
});

it('rifiuta un database dedicato sconosciuto', function (): void {
    expect(fn () => $this->createOrg('Fondazione Gamma', 'inesistente'))->toThrow(InvalidArgumentException::class);
});
