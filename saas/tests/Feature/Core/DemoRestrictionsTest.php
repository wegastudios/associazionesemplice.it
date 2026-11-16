<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\DemoScale;
use Illuminate\Support\Facades\Route;

dataset('scritture della demo', [
    'password' => ['put', '/user/password'],
    'due passaggi' => ['post', '/user/two-factor-authentication'],
    'email del profilo' => ['put', '/gestionale/profilo/email'],
    'altri dispositivi' => ['post', '/gestionale/profilo/dispositivi'],
    'invito operatore' => ['post', '/gestionale/operatori'],
    'ruolo di un operatore' => ['put', '/gestionale/operatori/0190a8e0-0000-7000-8000-000000000001'],
    'ruolo personalizzato' => ['post', '/gestionale/ruoli'],
    'chiavi dei pagamenti' => ['put', '/gestionale/pagamenti-online'],
    'prova dei pagamenti' => ['post', '/gestionale/pagamenti-online/prova/stripe'],
    'dati dell\'ente' => ['post', '/gestionale/configurazione'],
    'archivia l\'anno' => ['post', '/gestionale/archivio/2025'],
    'rimetti i file' => ['post', '/gestionale/archivio/2025/rimetti'],
    'verifica ricevute' => ['post', '/gestionale/verifica-ricevute/fissa'],
    'chiusura anno fiscale' => ['post', '/gestionale/esercizi/2025/chiudi'],
    'riapertura anno fiscale' => ['post', '/gestionale/esercizi/2025/riapri'],
]);

it('nella demo rifiuta le scritture che toccherebbero tutti i visitatori', function (string $method, string $url): void {
    config(['saas.demo.enabled' => true]);
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->$method($url, ['email' => 'altra@example.com', 'password' => $this::PASSWORD])
        ->assertSessionHasErrors(['ledger' => DemoScale::CHANGE_OFF]);
    expect($user->refresh()->email)->toBe('titolare@example.com');
})->with('scritture della demo');

it('nella demo il resto del profilo e la lettura delle stesse pagine restano liberi', function (): void {
    config(['saas.demo.enabled' => true]);
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->put('/gestionale/profilo/nome', ['name' => 'Anna Rossi'])->assertSessionHasNoErrors();
    $this->get('/gestionale/profilo')->assertOk();
    expect($user->refresh()->name)->toBe('Anna Rossi');
});

it('fuori dalla demo non cambia nulla', function (): void {
    config(['saas.demo.enabled' => false]);
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->put('/gestionale/profilo/email', ['email' => 'nuova@example.com', 'password' => $this::PASSWORD])->assertSessionHasNoErrors();
    expect($user->refresh()->email)->toBe('nuova@example.com');
});

it('nella demo una rotta di scrittura mai vista viene rifiutata, una sotto un\'azione permessa passa', function (): void {
    config(['saas.demo.enabled' => true]);
    Route::middleware('web')->group(function (): void {
        Route::post('/gestionale/inventata-domani', fn () => response('fatto'));
        Route::post('/gestionale/configurazione/passo-nuovo', fn () => response('fatto'));
        Route::post('/gestionale/soci/azione-nuova', fn () => response('fatto'));
        Route::post('/area/azione-del-socio', fn () => response('fatto'));
        Route::get('/gestionale/inventata-domani', fn () => response('letto'));
    });

    $this->post('/gestionale/inventata-domani')->assertSessionHasErrors(['ledger' => DemoScale::CHANGE_OFF]);
    $this->post('/gestionale/configurazione/passo-nuovo')->assertSessionHasErrors(['ledger' => DemoScale::CHANGE_OFF]);
    $this->get('/gestionale/inventata-domani')->assertOk()->assertSee('letto');
    $this->post('/gestionale/soci/azione-nuova')->assertOk()->assertSee('fatto');
    $this->post('/area/azione-del-socio')->assertOk()->assertSee('fatto');
});

it('nella demo lo scarico della copia è chiuso e l\'amministrazione della piattaforma non esiste', function (): void {
    config(['saas.demo.enabled' => true]);
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);

    $this->actingAs($user)->get('/gestionale/esportazione.zip')->assertSessionHasErrors(['ledger' => DemoScale::BACKUP_OFF]);
    $this->post('/gestionale/esportazione.zip')->assertSessionHasErrors(['ledger' => DemoScale::BACKUP_OFF]);
    $this->get('/saas/admin/accesso')->assertNotFound();
    $this->post('/saas/admin/accesso', ['email' => 'staff@example.com', 'password' => 'x'])->assertNotFound();
    $this->get('/saas/staff/invito/qualcosa')->assertNotFound();
});
