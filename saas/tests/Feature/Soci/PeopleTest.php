<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Contracts\Members;
use AppModulesSociContractsMemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleService;
use Illuminate\Support\Facades\DB;

const VALID_CF = 'VRDGPP75B10F205B';

beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->enter($this->org->id);
    $this->people = app(PeopleService::class);
    $this->memberships = app(MembershipService::class);
    $this->cash = Account::query()->sole()->id;
});

it('crea una scheda con i dati sensibili cifrati e la ritrova per codice fiscale', function (): void {
    $p = $this->people->create(['first_name' => 'Giuseppe', 'last_name' => 'Verdi', 'tax_code' => 'vrdgpp75b10f205b', 'email' => 'G.Verdi@Example.com', 'iban' => 'it60 x054 2811 1010 0000 0123 456', 'notes' => 'Allergico alle api']);

    expect($p->tax_code)->toBe(VALID_CF)
        ->and($p->email)->toBe('g.verdi@example.com')
        ->and($p->iban)->toBe('IT60X0542811101000000123456')
        ->and($this->people->search(VALID_CF)->sole()->id)->toBe($p->id)
        ->and($this->people->search('verdi giu')->sole()->id)->toBe($p->id)
        ->and($this->people->search('giuseppe verdi')->sole()->id)->toBe($p->id);

    $raw = implode('|', array_map(fn ($v) => is_resource($v) ? (string) stream_get_contents($v) : (string) $v, (array) DB::table('app.people')->where('id', $p->id)->first()));
    expect($raw)->not->toContain('Allergico')->not->toContain(VALID_CF)->not->toContain('IT60X054');
});

it('rifiuta dati non validi e doppioni di codice fiscale e tessera', function (): void {
    $this->people->create(['first_name' => 'Giuseppe', 'last_name' => 'Verdi', 'tax_code' => VALID_CF, 'card_number' => '7']);

    expect(fn () => $this->people->create(['first_name' => 'A', 'last_name' => 'B', 'tax_code' => VALID_CF]))->toThrow(PeopleError::class, 'codice fiscale')
        ->and(fn () => $this->people->create(['first_name' => 'A', 'last_name' => 'B', 'card_number' => '7']))->toThrow(PeopleError::class, 'tessera')
        ->and(fn () => $this->people->create(['first_name' => '', 'last_name' => 'B']))->toThrow(PeopleError::class, 'obbligatori')
        ->and(fn () => $this->people->create(['first_name' => 'A', 'last_name' => 'B', 'tax_code' => 'XXXXXX00X00X000X']))->toThrow(PeopleError::class, 'codice fiscale non è valido');
});

it('propone il primo numero di tessera libero, riempiendo i buchi', function (): void {
    expect($this->people->firstFreeCard())->toBe('1');
    foreach (['1', '2', '4', 'A12'] as $i => $card) {
        $this->people->create(['first_name' => 'S'.$i, 'last_name' => 'Socio', 'card_number' => $card]);
    }

    expect($this->people->firstFreeCard())->toBe('3');
});

it('collega un ospite solo a un socio', function (): void {
    $socio = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    $guest = $this->people->create(['type' => 'guest', 'first_name' => 'Ospite', 'last_name' => 'Uno', 'phone' => '333 1234567', 'host_person_id' => $socio->id]);

    expect($guest->host_person_id)->toBe($socio->id)
        ->and(fn () => $this->people->create(['type' => 'guest', 'first_name' => 'O', 'last_name' => 'Due', 'phone' => '333 7654321', 'host_person_id' => $guest->id]))
        ->toThrow(PeopleError::class, 'ospitante non è valido');
});

it('rinnova la tessera incassando la quota con ricevuta, e lo storno la toglie', function (): void {
    $socio = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
    app(OrgSettings::class)->set(MembershipService::DEFAULT_FEE, 3000);
    $year = $this->memberships->socialYear()->label();

    $r = $this->memberships->renewWithPayment($socio->id, $this->cash);

    $tx = Transaction::query()->sole();
    expect($r['receipt'])->toBe('1/'.now()->format('Y'))
        ->and($tx->amount_cents)->toBe(3000)
        ->and($tx->person_id)->toBe($socio->id)
        ->and($tx->description)->toBe("Quota associativa {$year}")
        ->and($this->memberships->isActive($socio->id))->toBeTrue()
        ->and(app(Members::class)->activeCount())->toBe(1)
        ->and(fn () => $this->memberships->renewWithPayment($socio->id, $this->cash))->toThrow(PeopleError::class, 'già la tessera');

    app(Ledger::class)->void($tx->id, 'Pagamento non arrivato');

    expect($this->memberships->isActive($socio->id))->toBeFalse()
        ->and(app(Members::class)->activeCount())->toBe(0)
        ->and($this->memberships->history($socio->id)[0]['valid'])->toBeFalse();

    // Dopo lo storno si può rinnovare di nuovo.
    $this->memberships->renewWithPayment($socio->id, $this->cash, 2500);
    expect($this->memberships->isActive($socio->id))->toBeTrue();
});

it('usa l\'anno sociale dell\'associazione', function (): void {
    app(OrgSettings::class)->set(MembershipService::START_MONTH, 9);
    $socio = $this->people->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);

    $this->memberships->grantManually($socio->id, '2025/2026', 'Socia onoraria');

    expect($this->memberships->isActive($socio->id, '2026-08-31'))->toBeTrue()
        ->and($this->memberships->isActive($socio->id, '2026-09-01'))->toBeFalse()
        ->and($this->memberships->socialYear('2026-09-15')->label())->toBe('2026/2027');
});

it('tratta fondatori, ospiti, usciti e tessere manuali come il plugin', function (): void {
    $founder = $this->people->create(['type' => 'founder', 'first_name' => 'F', 'last_name' => 'Fondatore']);
    $socio = $this->people->create(['first_name' => 'S', 'last_name' => 'Socio']);
    $guest = $this->people->create(['type' => 'guest', 'first_name' => 'O', 'last_name' => 'Ospite', 'phone' => '333 1234567', 'host_person_id' => $socio->id]);
    $year = $this->memberships->socialYear()->label();

    expect($this->memberships->isActive($founder->id))->toBeTrue()
        ->and(fn () => $this->memberships->renewWithPayment($founder->id, $this->cash, 100))->toThrow(PeopleError::class, 'fondatore')
        ->and(fn () => $this->memberships->renewWithPayment($guest->id, $this->cash, 100))->toThrow(PeopleError::class, 'Solo i soci')
        ->and(fn () => $this->memberships->renewWithPayment($socio->id, $this->cash))->toThrow(PeopleError::class, 'importo')
        ->and(fn () => $this->memberships->grantManually($socio->id, $year, ' '))->toThrow(PeopleError::class, 'perché');

    $manual = $this->memberships->grantManually($socio->id, $year, 'Socio onorario');
    expect(app(Members::class)->activeCount())->toBe(2);

    $this->memberships->revoke($manual);
    $this->people->leave($founder->id, now()->toDateString(), 'Trasferito');
    expect(app(Members::class)->activeCount())->toBe(0)
        ->and(DB::table('audit.log')->whereIn('action', ['membership.manual', 'membership.revoked'])->count())->toBe(2);
});

it('non vede le schede di un\'altra associazione', function (): void {
    $this->people->create(['first_name' => 'Giuseppe', 'last_name' => 'Verdi', 'tax_code' => VALID_CF]);
    $other = $this->createOrg('Circolo Beta');

    $this->orgContext()->run($other->id, function (): void {
        expect(app(PeopleService::class)->search('verdi')->count())->toBe(0)
            ->and(app(PeopleService::class)->search(VALID_CF)->count())->toBe(0);
        // Lo stesso codice fiscale può esistere in un'altra associazione.
        app(PeopleService::class)->create(['first_name' => 'Giuseppe', 'last_name' => 'Verdi', 'tax_code' => VALID_CF]);
    });
});

it('registra un socio ente con denominazione e partita IVA, senza nome né nascita', function (): void {
    $p = $this->people->create([
        'is_entity' => true, 'last_name' => 'Pro Loco Beta', 'first_name' => 'ignorato', 'tax_code' => '01234567890',
        'birth_date' => '1990-01-01', 'entity_same_section' => true,
    ]);

    expect($p->is_entity)->toBeTrue()
        ->and($p->first_name)->toBe('')
        ->and($p->birth_date)->toBeNull()
        ->and($p->entity_same_section)->toBeTrue()
        ->and($p->tax_code)->toBe('01234567890');
    expect(fn () => $this->people->create(['is_entity' => true, 'last_name' => '']))->toThrow(PeopleError::class);
    expect(fn () => $this->people->create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => '01234567890']))->toThrow(PeopleError::class);
});

it('dà il diritto di voto ai soli soci', function (): void {
    expect(\App\Modules\Soci\Contracts\MemberType::Ordinary->hasVotingRights())->toBeTrue()
        ->and(\App\Modules\Soci\Contracts\MemberType::Volunteer->hasVotingRights())->toBeTrue()
        ->and(\App\Modules\Soci\Contracts\MemberType::Guest->hasVotingRights())->toBeFalse()
        ->and(\App\Modules\Soci\Contracts\MemberType::Guardian->hasVotingRights())->toBeFalse();
});
