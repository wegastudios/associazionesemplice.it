<?php

declare(strict_types=1);

use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MatricolaService;
use App\Modules\Soci\Services\MemberBookService;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
});

it('dà la matricola in ordine di ingresso, senza buchi, una volta sola', function (): void {
    $matricole = $this->orgContext()->run($this->org->id, function (): array {
        $tardi = Person::create(['type' => 'ordinary', 'first_name' => 'Tardi', 'last_name' => 'Arrivato', 'joined_on' => '2024-05-01']);
        $primo = Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore', 'joined_on' => '2015-03-01']);
        $ospite = Person::create(['type' => 'guest', 'first_name' => 'Ospite', 'last_name' => 'Esterno', 'host_person_id' => $primo->id]);

        app(MatricolaService::class)->assignMissing();
        $first = [$primo->refresh()->matricola, $tardi->refresh()->matricola, $ospite->refresh()->matricola];

        // Chi entra dopo prende il numero successivo; chi ce l'ha già non cambia.
        $nuovo = Person::create(['type' => 'volunteer', 'first_name' => 'Vera', 'last_name' => 'Volontaria', 'joined_on' => '2010-01-01']);
        app(MatricolaService::class)->assignMissing();
        app(MatricolaService::class)->assignMissing();

        return [$first, $nuovo->refresh()->matricola, $primo->refresh()->matricola];
    });

    expect($matricole)->toBe([[1, 2, null], 3, 1]);
});

it('mostra la matricola nel libro soci', function (): void {
    $row = $this->orgContext()->run($this->org->id, function (): array {
        Person::create(['type' => 'founder', 'first_name' => 'Franco', 'last_name' => 'Fondatore', 'joined_on' => '2015-03-01']);

        return app(MemberBookService::class)->rows()[0];
    });

    expect($row['matricola'])->toBe(1)->and($row['n'])->toBe(1);
});
