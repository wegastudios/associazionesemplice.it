<?php

declare(strict_types=1);

use App\Modules\Core\Http\TooManyAttempts;
use Illuminate\Support\Facades\Route;

/*
 * Limite di frequenza superato: dalle pagine del gestionale e dell'area soci si torna indietro con l'avviso (mai il riquadro
 * d'errore); pagine aperte, accesso e moduli pubblici mostrano la pagina 429. Vale ovunque, non solo nella demo.
 */

beforeEach(function (): void {
    Route::middleware(['web', 'throttle:1,10'])->group(function (): void {
        foreach (['/gestionale/_test/limite', '/area/_test/limite', '/area/accesso/_test'] as $path) {
            Route::match(['get', 'post', 'put', 'delete'], $path, static fn () => response('fatto'));
        }
    });
    $this->inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];
});

it('da una pagina torna indietro con l\'avviso e i minuti da aspettare', function (string $path, string $method): void {
    $this->from('/gestionale')->{$method}($path, [], $this->inertia)->assertOk();
    $this->from('/gestionale')->{$method}($path, [], $this->inertia)
        ->assertStatus(303)->assertRedirect('/gestionale')
        ->assertSessionHasErrors(['ledger' => 'Hai fatto troppi tentativi in poco tempo. Riprova tra 10 minuti.']);
})->with(['/gestionale/_test/limite', '/area/_test/limite'])->with(['post', 'put', 'delete']);

it('aprendo una pagina mostra la pagina in italiano, senza tornare indietro', function (): void {
    $this->get('/gestionale/_test/limite', $this->inertia)->assertOk();
    $this->get('/gestionale/_test/limite', $this->inertia)->assertTooManyRequests()
        ->assertSee('Troppi tentativi')->assertSee('Riprova tra 10 minuti.');
});

it('nelle pagine di accesso, che non hanno l\'avviso, resta la pagina 429', function (): void {
    $this->post('/area/accesso/_test', [], $this->inertia)->assertOk();
    $this->post('/area/accesso/_test', [], $this->inertia)->assertTooManyRequests()->assertSee('Riprova tra 10 minuti.');
});

it('senza Inertia e in JSON la risposta resta 429', function (): void {
    $this->post('/gestionale/_test/limite')->assertOk();
    $this->post('/gestionale/_test/limite')->assertTooManyRequests();
    $this->postJson('/gestionale/_test/limite')->assertTooManyRequests()->assertHeader('Retry-After');
});

it('scrive il tempo da aspettare', function (?int $seconds, string $wait): void {
    expect(TooManyAttempts::message($seconds))->toBe('Hai fatto troppi tentativi in poco tempo. '.$wait);
})->with([
    [null, 'Aspetta qualche minuto e riprova.'], [0, 'Aspetta qualche minuto e riprova.'],
    [1, 'Riprova tra un minuto.'], [60, 'Riprova tra un minuto.'], [61, 'Riprova tra 2 minuti.'], [494, 'Riprova tra 9 minuti.'],
    [3600, 'Riprova tra un\'ora.'], [3601, 'Riprova tra 2 ore.'],
]);
