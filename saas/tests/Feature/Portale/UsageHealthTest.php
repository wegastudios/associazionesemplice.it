<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/PortalHelpers.php';

// «invio dei consumi al portale» in saas:health: avviso dopo due notti di fila senza invio riuscito.

beforeEach(function (): void {
    $this->travelTo(now()->setTime(5, 0));
    fakePortal();
    // Un solo finto portale, con l'esito che si cambia da un invio all'altro.
    $this->status = 200;
    Http::fake(['https://portale.example.test/api/saas/v1/usage' => fn (HttpRequest $r) => Http::response([], $this->status)]);
    $this->sendFails = function (): void {
        $this->status = 422;
        expect(fn () => Artisan::call('saas:portal:usage'))->toThrow(RuntimeException::class, 'HTTP 422');
    };
});

it('non avvisa con un invio riuscito ieri', function (): void {
    $this->travelTo(now()->subDay());
    $this->artisan('saas:portal:usage')->assertSuccessful();
    $this->travelBack();

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    invio dei consumi al portale');
});

it('avvisa con l\'ultimo invio riuscito tre giorni fa e non è mai un errore', function (): void {
    $this->travelTo(now()->subDays(3));
    $this->artisan('saas:portal:usage')->assertSuccessful();
    $this->travelBack();
    ($this->sendFails)();

    config(['saas.health_strict' => true]);
    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('AVVISO invio dei consumi al portale: ultimo invio dei consumi al portale riuscito il')
        ->doesntExpectOutputToContain('ERRORE invio dei consumi al portale');
});

it('non avvisa se il portale non è configurato', function (): void {
    config(['saas.portal.signing_key' => null]);

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    invio dei consumi al portale');
});

it('non avvisa al primo giorno di fallimento, avvisa alla seconda notte', function (): void {
    ($this->sendFails)();

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('ok    invio dei consumi al portale');

    $this->travelTo(now()->addDays(2)->addMinute());
    ($this->sendFails)();

    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('AVVISO invio dei consumi al portale: i consumi non sono mai stati accettati dal portale');
});
