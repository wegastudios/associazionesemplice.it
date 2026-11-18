<?php

declare(strict_types=1);

use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Services\MembershipService;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Circolo Gamma');
    $this->orgContext()->enter($this->org->id);
    $this->travelTo('2026-03-10');
});

it('i totali dell\'anno delle attività si dividono per gli anni fiscali che tocca', function (): void {
    app(OrgSettings::class)->set(MembershipService::START_MONTH, 9);
    $activities = app(ActivityService::class);
    $cash = Account::query()->sole()->id;
    $corso = $activities->save(null, ['name' => 'Corso di vela', 'kind' => 'course', 'social_year' => '2025/2026', 'fee_cents' => 1000]);
    $activities->expense($corso->id, $cash, '2025-10-10', 1000, 'Istruttore', null, null);
    $activities->expense($corso->id, $cash, '2026-02-10', 2500, 'Istruttore', null, null);

    $blocks = $activities->totalsByFiscalYear('2025/2026', [$corso->id]);

    expect($blocks)->toBe([
        ['label' => 'set–dic 2025', 'income' => 0, 'expense' => 1000],
        ['label' => 'gen–ago 2026', 'income' => 0, 'expense' => 2500],
    ]);
});

it('con l\'anno delle attività dentro un solo anno fiscale c\'è un solo blocco', function (): void {
    $activities = app(ActivityService::class);
    $cash = Account::query()->sole()->id;
    $corso = $activities->save(null, ['name' => 'Yoga', 'kind' => 'course', 'social_year' => '2026', 'fee_cents' => 1000]);
    $activities->expense($corso->id, $cash, '2026-02-10', 700, 'Sala', null, null);

    expect($activities->totalsByFiscalYear('2026', [$corso->id]))->toBe([['label' => 'gen–dic 2026', 'income' => 0, 'expense' => 700]]);
});
