<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSettings;
use Illuminate\Support\Facades\DB;

// Le impostazioni dell'associazione si leggono con una sola query per richiesta. Dati di prova inventati.

it('legge le impostazioni con una sola query e vede subito quelle scritte', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');

    $this->orgContext()->run($org->id, function (): void {
        $settings = app(OrgSettings::class);
        $settings->set('prova.numero', 7);
        $settings->set('prova.testo', 'ciao');

        $fresh = new OrgSettings(app('db'), app(App\Modules\Core\Contracts\OrgContext::class));
        $n = 0;
        DB::listen(static function () use (&$n): void {
            $n++;
        });

        expect($fresh->int('prova.numero', 0))->toBe(7)
            ->and($fresh->get('prova.testo'))->toBe('ciao')
            ->and($fresh->get('prova.assente', 'x'))->toBe('x')
            ->and($n)->toBe(1);

        $fresh->set('prova.numero', 9);
        expect($fresh->int('prova.numero', 0))->toBe(9)->and($n)->toBe(2);
    });
});

it('tiene separate le impostazioni di due associazioni', function (): void {
    $a = $this->createOrg('Polisportiva Alfa');
    $b = $this->createOrg('Polisportiva Beta');
    $settings = app(OrgSettings::class);

    $this->orgContext()->run($a->id, fn () => $settings->set('prova.nome', 'alfa'));
    $this->orgContext()->run($b->id, fn () => $settings->set('prova.nome', 'beta'));

    expect($this->orgContext()->run($a->id, fn () => $settings->get('prova.nome')))->toBe('alfa')
        ->and($this->orgContext()->run($b->id, fn () => $settings->get('prova.nome')))->toBe('beta');
});
