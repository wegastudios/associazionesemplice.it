<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Org\SetupWizard;
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
    $cash = test()->orgContext()->run(test()->org->id, fn () => Account::query()->sole()->id);

    return array_replace_recursive(['steps' => [
        'ente' => ['kind' => 'aps', 'tax_code' => '12345678903', 'vat' => '', 'runts' => '12345', 'address' => 'Via Roma 1', 'zip' => '00100', 'city' => 'Roma', 'province' => 'rm', 'email' => 'Info@Alfa.example', 'pec' => '', 'phone' => ''],
        'tesseramento' => ['start_month' => 9, 'fee' => '25,00'],
        'conti' => ['openings' => [$cash => '150,50'], 'bank_name' => 'Banca di prova', 'bank_opening' => '1.000,00'],
        'soci' => ['applications_enabled' => true, 'applications_rules' => false, 'privacy_url' => 'https://alfa.example/privacy', 'rules_url' => '', 'reminders_enabled' => true],
    ]], $over);
}

it('si apre da sola al primo accesso del titolare, una volta', function (): void {
    $this->actingAs($this->owner)->get('/gestionale')->assertRedirect('/gestionale/configurazione');
    $this->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('setupPending', true));
    $this->get('/gestionale/configurazione')->assertInertia(fn (Assert $page) => $page->component('Configurazione')
        ->where('status', null)->where('steps.0.key', 'ente')->where('steps.1.key', 'tesseramento')->where('steps.2.key', 'conti')->where('steps.3.key', 'soci'));

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

it('si può rimandare e riaprire', function (): void {
    $this->actingAs($this->owner)->post('/gestionale/configurazione/rimanda')->assertRedirect('/gestionale');
    $this->get('/gestionale')->assertOk()->assertInertia(fn (Assert $page) => $page->where('setupPending', false)->where('canSetup', true));
    $this->get('/gestionale/configurazione')->assertOk()->assertInertia(fn (Assert $page) => $page->where('status', 'skipped')->where('steps.1.props.start_month', 1));
});
