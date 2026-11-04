<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MemberBookService;
use App\Modules\Soci\Services\MembershipService;
use App\Modules\Soci\Services\PeopleService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($this->org->id, function (): void {
        $old = Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore', 'joined_on' => '2015-03-01', 'tax_code' => 'RSSMRA80A01H501U', 'card_number' => '1']);
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi', 'joined_on' => '2020-09-10', 'birth_date' => '1985-01-01', 'birth_place' => 'Roma', 'address' => ['text' => 'Via Roma 1, 00100 Roma']]);
        $gone = Person::create(['first_name' => 'Gino', 'last_name' => 'Uscito', 'joined_on' => '2018-01-15']);
        Person::create(['type' => 'guest', 'first_name' => 'Ospite', 'last_name' => 'Esterno', 'host_person_id' => $anna->id, 'phone' => '333 1234567']);
        Person::create(['type' => 'guardian', 'first_name' => 'Non', 'last_name' => 'Socio']);
        app(PeopleService::class)->leave($gone->id, '2024-06-30', 'Recesso');
        app(MembershipService::class)->renewWithPayment($anna->id, Account::query()->sole()->id, 2500);
        unset($old);
    });
});

it('elenca i soci in ordine di ingresso con quota, cessazione e filtri', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $book = app(MemberBookService::class);
        $rows = $book->rows();
        expect(array_column($rows, 'name'))->toBe(['Fondatore Franco', 'Uscito Gino', 'Rossi Anna'])
            ->and(array_column($rows, 'n'))->toBe([1, 2, 3])
            ->and($rows[0]['tax_code'])->toBe('RSSMRA80A01H501U')
            ->and($rows[1])->toMatchArray(['left_on' => '30/06/2024', 'left_reason' => 'Recesso', 'in_force' => false])
            ->and($rows[2])->toMatchArray(['birth' => '01/01/1985 Roma', 'residence' => 'Via Roma 1, 00100 Roma', 'fee' => '25,00 €', 'fee_year' => now()->format('Y'), 'joined_on' => '10/09/2020'])
            ->and(array_column($book->rows(MemberBookService::IN_FORCE), 'n'))->toBe([1, 3])
            ->and(array_column($book->rows(MemberBookService::LEFT), 'name'))->toBe(['Uscito Gino']);
    });
});

it('controlla la data di uscita', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::query()->where('first_name', 'Anna')->sole();
        expect(fn () => app(PeopleService::class)->leave($p->id, now()->addDay()->toDateString(), ''))->toThrow(PeopleError::class)
            ->and(fn () => app(PeopleService::class)->leave($p->id, '2019-01-01', ''))->toThrow(PeopleError::class);
    });
});

it('mostra, stampa ed esporta il libro soci secondo i permessi', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $acc = $this->createUser('acc@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->addOperator($this->org, $acc, OrgRole::Accountant);

    $this->actingAs($op)->get('/gestionale/soci/libro?stato=in_force')->assertInertia(fn (Assert $page) => $page->component('Soci/LibroSoci')
        ->where('filter', 'in_force')->has('rows', 2)->where('canExport', true));
    $pdf = (string) $this->get('/gestionale/soci/libro.pdf')->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
    expect($pdf)->toContain('Libro soci')->toContain('Fondatore Franco')->toContain('Recesso')->toContain('/MediaBox [0 0 842 595]');
    $csv = (string) $this->get('/gestionale/soci/libro.csv?stato=left')->assertOk()->getContent();
    expect($csv)->toContain('Uscito Gino')->not->toContain('Rossi Anna');
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('audit.log')->where('action', 'member_book.exported')->count())->toBe(2));

    $this->actingAs($acc)->get('/gestionale/soci/libro')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canExport', false));
    $this->get('/gestionale/soci/libro.pdf')->assertForbidden();
});

it('va a capo di pagina con molti soci', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $rows = array_fill(0, 45, app(MemberBookService::class)->rows()[0]);
        expect(app(MemberBookService::class)->pdf($rows, 'X', ''))->toContain('/Count 3')->toContain('pagina 3');
    });
});
