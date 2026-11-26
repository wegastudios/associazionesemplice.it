<?php

declare(strict_types=1);

use App\Modules\Core\Demo\DemoLimits;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Ogni rotta POST del gestionale è o coperta da un tetto della demo (DemoLimits::CAPS) o esentata qui, con il motivo.
 * Una rotta nuova non classificata fa fallire la prova: chi la aggiunge decide se serve un tetto o scrive il motivo.
 * Il limite per indirizzo IP (creazioni e scritture) vale comunque per tutte.
 */

const DEMO_OFF = 'spenta nella demo: non è in DemoRestrictions::ALLOWED';
const DEMO_ACTION = 'azione su un dato già esistente: non aggiunge righe che pesano';
const DEMO_CHILD = 'aggiunge poche righe figlie di un dato già contato (e limitato) dal suo tetto';

/** @return array<string, string> uri => motivo */
function demoExemptions(): array
{
    $off = [
        'gestionale/prima-nota/importa', 'gestionale/prima-nota/importa/{import}/annulla', 'gestionale/prima-nota/importa/{import}/riprendi',
        'gestionale/soci/importa', 'gestionale/soci/importa/{import}/annulla', 'gestionale/soci/importa/{import}/riprendi',
        'gestionale/soci/{person}/certificati', 'gestionale/ricevute/{receipt}/invia',
        'gestionale/verifica-ricevute/fissa', 'gestionale/verifica-ricevute/{receipt}/rifai',
        'gestionale/esercizi/{year}/chiudi', 'gestionale/esercizi/{year}/riapri',
        'gestionale/configurazione', 'gestionale/esportazione.zip',
        'gestionale/archivio/{year}', 'gestionale/archivio/{year}/rimetti', 'gestionale/archivio/{year}/ricostruisci',
        'gestionale/operatori', 'gestionale/ruoli', 'gestionale/profilo/dispositivi',
        'gestionale/pagamenti-online/prova/{provider}', 'gestionale/pagamenti-online/{payment}/controllato', 'gestionale/pagamenti-online/{payment}/rimborso',
        'gestionale/conti/{account}/commissione', 'gestionale/conti/{account}/chiudi', 'gestionale/conti/{account}/riapri',
    ];
    $actions = [
        'gestionale/configurazione/rimanda', 'gestionale/attivita/{activity}/archivia', 'gestionale/risorse/{resource}/archivia',
        'gestionale/attivita/date/{session}/annulla', 'gestionale/attivita/prenotazioni/{booking}/annulla', 'gestionale/prenotazioni/{reservation}/annulla',
        'gestionale/attivita/{activity}/presenze', 'gestionale/attivita/{activity}/iscrizioni/{person}/chiudi',
        'gestionale/prima-nota/{transaction}/attivita', 'gestionale/prima-nota/{transaction}/storno',
        'gestionale/risorse/{resource}/preventivo',
        'gestionale/soci/{person}/uscita', 'gestionale/soci/{person}/tessera-manuale', 'gestionale/tessere/{membership}/togli',
        'gestionale/soci/{person}/consensi', 'gestionale/domande/{application}/respingi',
        'gestionale/soci/{person}/area-soci/link', 'gestionale/soci/{person}/area-soci/esci',
        'gestionale/soci/impostazioni', 'gestionale/domande/impostazioni', 'gestionale/soci/livelli',
        'gestionale/soci/{person}/tutori', 'gestionale/soci/{person}/tutori/{guardian}/operativo',
    ];
    $children = [
        'gestionale/attivita/{activity}/date', 'gestionale/attivita/date/{session}/attesa', 'gestionale/attivita/date/{session}/attesa/{person}/esci',
        'gestionale/risorse/{resource}/attesa', 'gestionale/risorse/{resource}/attesa/{person}/esci',
        'gestionale/conti',
    ];

    return array_fill_keys($off, DEMO_OFF) + array_fill_keys($actions, DEMO_ACTION) + array_fill_keys($children, DEMO_CHILD);
}

/** @return list<string> uri delle rotte POST del gestionale */
function gestionalePostRoutes(): array
{
    $uris = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (in_array('POST', $route->methods(), true) && str_starts_with($route->uri(), 'gestionale/')) {
            $uris[] = $route->uri();
        }
    }

    return array_values(array_unique($uris));
}

function demoCovered(string $uri): bool
{
    return Str::is(DemoLimits::capPaths(), (string) preg_replace('/\{[^}]+\}/', 'x', $uri));
}

it('ogni rotta POST del gestionale ha un tetto nella demo o un\'esenzione scritta con il motivo', function (): void {
    $exempt = demoExemptions();
    $unclassified = array_values(array_filter(gestionalePostRoutes(), static fn (string $uri): bool => ! demoCovered($uri) && ! isset($exempt[$uri])));

    expect(gestionalePostRoutes())->not->toBeEmpty()->and($unclassified)->toBe([]);
});

it('le esenzioni sono rotte che esistono e non hanno già un tetto', function (): void {
    $routes = gestionalePostRoutes();
    $stale = array_values(array_filter(array_keys(demoExemptions()), static fn (string $uri): bool => ! in_array($uri, $routes, true)));
    $covered = array_values(array_filter(array_keys(demoExemptions()), static fn (string $uri): bool => demoCovered($uri)));

    expect($stale)->toBe([])->and($covered)->toBe([]);
});
