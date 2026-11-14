<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Org\SetupWizard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa', setupDone: false);
    $this->owner = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
});

function setupInput(array $over = []): array
{
    $cash = test()->orgContext()->run(test()->org->id, fn () => Account::query()->where('type', 'cash')->sole()->id);

    return array_replace_recursive(['steps' => [
        'ente' => ['kind' => 'aps', 'tax_code' => '12345678903', 'vat' => '', 'runts' => '12345', 'address' => 'Via Roma 1', 'zip' => '00100', 'city' => 'Roma', 'province' => 'rm', 'email' => 'Info@Alfa.example', 'pec' => '', 'phone' => ''],
        'anno_sociale' => ['start_month' => 9],
        'anno_fiscale' => ['start_month' => 9],
        'tesseramento' => ['fee' => '25,00'],
        'conti' => ['openings' => [$cash => '150,50'], 'bank_name' => 'Banca di prova', 'bank_opening' => '1.000,00'],
        'soci' => ['applications_enabled' => true, 'applications_rules' => false, 'privacy_url' => 'https://alfa.example/privacy', 'rules_url' => '', 'reminders_enabled' => true],
    ]], $over);
}

it('si apre da sola ad ogni accesso del titolare finché anno sociale e anno fiscale non sono scelti', function (): void {
    $this->actingAs($this->owner)->get('/gestionale')->assertRedirect('/gestionale/configurazione');
    $this->get('/gestionale')->assertRedirect('/gestionale/configurazione');
    $this->get('/gestionale/configurazione')->assertInertia(fn (Assert $page) => $page->component('Configurazione')->where('canSkip', false)
        ->where('status', null)->where('steps.0.key', 'anno_sociale')->where('steps.1.key', 'anno_fiscale')->where('steps.2.key', 'ente')->where('steps.3.key', 'tesseramento')->where('steps.4.key', 'conti')->where('steps.5.key', 'soci'));

    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->actingAs($op)->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->where('setupPending', false));
    $this->get('/gestionale/configurazione')->assertForbidden();
});

it('salva tutti i passi insieme', function (): void {
    $this->actingAs($this->owner)->post('/gestionale/configurazione', setupInput())
        ->assertRedirect('/gestionale')->assertSessionHas('status');

    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(OrgSettings::class);
        expect($s->get('ente.tax_code'))->toBe('12345678903')->and($s->get('ente.province'))->toBe('RM')->and($s->get('ente.email'))->toBe('info@alfa.example')
            ->and($s->get('ente.vat'))->toBeNull()
            ->and($s->int('soci.social_year_start_month', 1))->toBe(9)->and($s->int('soci.membership_fee_cents', 0))->toBe(2500)
            ->and($s->get('soci.applications_enabled'))->toBeTrue()->and($s->get('soci.reminders_enabled'))->toBeTrue()
            ->and(Account::query()->orderBy('sort_order')->pluck('opening_cents', 'name')->all())->toBe(['Cassa contanti' => 15050, 'Banca di prova' => 100000])
            ->and(app(SetupWizard::class)->status())->toBe('done')
            ->and(DB::table('audit.log')->where('action', 'setup.completed')->exists())->toBeTrue();
    });
    $this->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->where('setupPending', false));
});

it('non salva nulla se un passo ha errori e li indica per passo', function (): void {
    $this->actingAs($this->owner)->post('/gestionale/configurazione', setupInput(['steps' => [
        'ente' => ['tax_code' => '12345678901', 'zip' => '1'],
        'soci' => ['privacy_url' => 'http://insicuro.example'],
    ]]))->assertSessionHasErrors(['steps.ente', 'steps.soci'])->assertSessionDoesntHaveErrors('steps.tesseramento');

    $this->orgContext()->run($this->org->id, function (): void {
        expect(app(OrgSettings::class)->get('soci.social_year_start_month'))->toBeNull()
            ->and(Account::query()->count())->toBe(1)
            ->and(app(SetupWizard::class)->pending())->toBeTrue();
    });

    $this->post('/gestionale/configurazione', setupInput(['steps' => ['soci' => ['privacy_url' => '']]]))->assertSessionHasErrors('steps.soci');
});

it('non lascia rimandare la scelta dell\'anno sociale e dell\'anno fiscale', function (): void {
    $this->actingAs($this->owner)->post('/gestionale/configurazione/rimanda')->assertRedirect('/gestionale/configurazione')->assertSessionHasErrors('steps.anno_sociale');
    $this->get('/gestionale/configurazione')->assertOk()->assertInertia(fn (Assert $page) => $page->where('status', null)->where('steps.0.props.start_month', null)->where('steps.1.props.start_month', null));

    // Scelti i mesi (e salvata la configurazione) si torna al gestionale e la pagina si riapre quando si vuole.
    $this->post('/gestionale/configurazione', setupInput())->assertRedirect('/gestionale');
    $this->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->where('setupPending', false)->where('canSetup', true));
    $this->get('/gestionale/configurazione')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canSkip', true)->where('steps.0.props.start_month', 9));
});

it('chiede subito il mese dell\'anno sociale e dell\'anno fiscale e rifà l\'anno fiscale di partenza', function (): void {
    $this->actingAs($this->owner)->post('/gestionale/configurazione', setupInput(['steps' => ['anno_sociale' => ['start_month' => ''], 'anno_fiscale' => ['start_month' => '']]]))
        ->assertSessionHasErrors(['steps.anno_sociale', 'steps.anno_fiscale']);

    $this->post('/gestionale/configurazione', setupInput())->assertRedirect('/gestionale');
    $this->orgContext()->run($this->org->id, function (): void {
        $today = CarbonImmutable::today();
        $start = CarbonImmutable::create($today->month >= 9 ? $today->year : $today->year - 1, 9, 1)->toDateString();
        $years = DB::table('app.fiscal_years')->get(['starts_on', 'status']);
        expect($years)->toHaveCount(1)->and($years[0]->starts_on)->toBe($start)->and($years[0]->status)->toBe('open')
            ->and(app(FiscalYearService::class)->yearFor($today->toDateString()))->toBe((int) substr($start, 0, 4));

        // Con movimenti registrati il cambio del mese chiede conferma.
        app(Ledger::class)->recordIncome(new Income($today->toDateString(), Account::query()->where('type', 'cash')->sole()->id, [new IncomeLine(Category::query()->where('kind', 'donation')->sole()->id, 1000)], null, null, false));
    });
    $this->post('/gestionale/configurazione', setupInput(['steps' => ['anno_fiscale' => ['start_month' => 3]]]))->assertSessionHasErrors('steps.anno_fiscale');
});
