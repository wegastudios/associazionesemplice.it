<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    Notification::fake();
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->owner = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
    $this->year = now()->format('Y');
    $this->employee = function (array $denied): User {
        $members = app(OrgMembers::class);
        $owner = app(Memberships::class)->find($this->owner->id, $this->org->id) ?? throw new RuntimeException('manca');
        $user = $members->invite($this->org, 'dip'.uniqid().'@example.com', 'Dina Dipendente', OrgRole::Employee, $owner);
        User::query()->whereKey($user->id)->update(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $members->setDeniedAbilities($this->org, $user->id, $denied, $owner);

        return $user->fresh() ?? $user;
    };
});

it('importa solo le righe del tipo per cui chi importa ha il permesso e dice quali ha scartato', function (): void {
    $y = $this->year;
    $csv = "Data;Tipo;Conto;Voce;Importo;Descrizione\n"
        ."10/01/{$y};Entrata;Cassa contanti;Quota associativa;30,00;Quota\n"
        ."12/01/{$y};Uscita;Cassa contanti;Affitto sala;20,00;Sala\n";
    $upload = fn () => UploadedFile::fake()->createWithContent('pn.csv', $csv);

    $this->actingAs(($this->employee)(['accounting.expense']));
    $this->post('/gestionale/prima-nota/importa', ['file' => $upload(), 'apply' => true])
        ->assertInertia(fn (Assert $page) => $page->where('result.income', 1)->where('result.expense', 0)
            ->where('result.failed.0', fn (string $m): bool => str_contains($m, 'non hai il permesso di registrare spese')));
    expect(DB::table('app.transactions')->where('type', 'expense')->count())->toBe(0)
        ->and(DB::table('app.transactions')->where('type', 'income')->count())->toBe(1);

    $this->actingAs(($this->employee)(['accounting.income']));
    $this->post('/gestionale/prima-nota/importa', ['file' => $upload(), 'apply' => true])
        ->assertInertia(fn (Assert $page) => $page->where('result.expense', 1)->where('result.income', 0)
            ->where('result.failed.0', fn (string $m): bool => str_contains($m, 'non hai il permesso di registrare entrate')));
});

it('manda i saldi dei conti solo a chi può vedere fondi e conti', function (): void {
    $this->actingAs(($this->employee)(['accounting.funds']));
    $this->get('/gestionale/prima-nota')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Contabilita/PrimaNota')->where('balances', null));
    $this->get('/gestionale/conti')->assertForbidden();

    $this->actingAs($this->owner);
    $this->get('/gestionale/prima-nota')->assertOk()->assertInertia(fn (Assert $page) => $page->has('balances'));
});

it('rifiuta date fuori dall\'intervallo, troppi presenti e commissioni oltre il 100%', function (): void {
    $this->actingAs($this->owner);
    $cash = $this->orgContext()->run($this->org->id, fn (): string => Account::query()->firstOrFail()->id);

    foreach (['1999-12-31', now()->addYears(11)->toDateString()] as $date) {
        $this->post('/gestionale/prima-nota/incassi', ['date' => $date, 'account_id' => $cash, 'lines' => []])->assertSessionHasErrors('date');
        $this->post('/gestionale/prima-nota/spese', ['date' => $date, 'account_id' => $cash])->assertSessionHasErrors('date');
    }

    $present = array_map(static fn (): string => (string) Illuminate\Support\Str::uuid(), range(1, 1001));
    $this->post('/gestionale/attivita/'.Illuminate\Support\Str::uuid().'/presenze', ['date' => now()->toDateString(), 'present' => $present])->assertSessionHasErrors('present');

    $this->post("/gestionale/conti/{$cash}/commissione", ['fee_fixed' => '0', 'fee_percent' => '150'])->assertSessionHasErrors('fee_percent');
    $this->post("/gestionale/conti/{$cash}/commissione", ['fee_fixed' => '0', 'fee_percent' => '1,5'])->assertSessionHasNoErrors();
});
