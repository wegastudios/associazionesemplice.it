<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Keys\KeyRing;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;

/*
 * Cancellare un'associazione = distruggere la sua chiave. Il comando usa il ruolo proprietario, che non vede la
 * transazione del test: qui i dati si confermano davvero e si cancellano alla fine.
 */

beforeEach(function (): void {
    DB::commit();
});

afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $owner->statement('TRUNCATE core.org_keys, core.org_users, core.orgs CASCADE');
    DB::beginTransaction();
});

it('dopo la distruzione delle chiavi i dati cifrati non si aprono più', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $person = $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U']));

    // Finché l'associazione esiste, il comando si rifiuta.
    $this->artisan('saas:keys:destroy', ['org' => $org->id, '--force' => true])->assertFailed();
    expect($this->orgContext()->run($org->id, fn () => Person::findOrFail($person->id)->tax_code))->toBe('RSSMRA80A01H501U');

    $org->delete();
    $this->artisan('saas:keys:destroy', ['org' => $org->id, '--force' => true])->assertSuccessful();

    // Anche con le righe ancora nel database (o in un backup), il codice fiscale è perso.
    $this->app->forgetInstance(KeyRing::class);
    $org->restore();
    expect(fn () => $this->orgContext()->run($org->id, fn () => Person::findOrFail($person->id)->tax_code))
        ->toThrow(DecryptionFailed::class);
});
