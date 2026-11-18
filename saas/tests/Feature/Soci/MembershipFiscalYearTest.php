<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgFiscal;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Services\MembershipService;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Circolo Delta');
    $this->orgContext()->enter($this->org->id);
    $this->memberships = app(MembershipService::class);
    $this->travelTo('2026-03-10');
});

it('la tessera nuova segue l\'anno fiscale, non quello delle attività', function (): void {
    app(OrgSettings::class)->set(OrgFiscal::FISCAL_START_MONTH, 9);
    app(OrgSettings::class)->set(MembershipService::START_MONTH, 1);

    expect($this->memberships->membershipYearLabel('2026-03-10'))->toBe('2025/2026')
        ->and($this->memberships->membershipYearLabel('2026-09-01'))->toBe('2026/2027')
        ->and($this->memberships->socialYearLabel('2026-03-10'))->toBe('2026')
        ->and($this->memberships->membershipYearInfo('2025/2026'))->toMatchArray(['start' => '2025-09-01', 'end' => '2026-08-31'])
        ->and($this->memberships->socialYearInfo('2026'))->toMatchArray(['start' => '2026-01-01', 'end' => '2026-12-31']);
});
