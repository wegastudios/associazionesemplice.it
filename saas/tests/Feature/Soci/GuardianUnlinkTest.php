<?php

declare(strict_types=1);

use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\GuardianService;
use Illuminate\Support\Facades\DB;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
});

function minorWithTwoGuardians(): array
{
    $minor = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'birth_date' => now()->subYears(10)->toDateString()]);
    $mum = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi', 'email' => 'anna@example.com', 'birth_date' => '1985-01-01']);
    $dad = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'birth_date' => '1983-01-01']);
    $g = app(GuardianService::class);
    $g->link($minor->id, $mum->id);
    $g->link($minor->id, $dad->id);

    return [$minor, $mum, $dad];
}

it('scollegando il tutore che opera passa a operare l\'altro, se è idoneo', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        [$minor, $mum, $dad] = minorWithTwoGuardians();
        app(GuardianService::class)->unlink($minor->id, $mum->id);

        expect(DB::table('app.guardians')->where('person_id', $minor->id)->where('guardian_person_id', $dad->id)->value('is_operative'))->toBeTrue();
    });
});

it('non promuove un tutore non più idoneo (senza email)', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        [$minor, $mum, $dad] = minorWithTwoGuardians();
        DB::table('app.people')->where('id', $dad->id)->update(['email' => null]);
        app(GuardianService::class)->unlink($minor->id, $mum->id);

        expect(DB::table('app.guardians')->where('person_id', $minor->id)->where('guardian_person_id', $dad->id)->value('is_operative'))->toBeFalse();
    });
});
