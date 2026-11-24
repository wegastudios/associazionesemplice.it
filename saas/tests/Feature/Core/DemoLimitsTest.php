<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Core\Demo\DemoLimits;
use App\Modules\Soci\Models\Person;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

/*
 * Protezioni della demo pubblica: frequenza per indirizzo IP, tetti sul totale, nessun file da nessuna rotta, nessun link né
 * HTML nei testi. Tutto vale solo con la demo attiva (SAAS_DEMO acceso E APP_ENV=demo): fuori, nessuna restrizione.
 */

/** Dalla richiesta successiva in poi, il visitatore ha questo indirizzo. */
function fromIp(string $ip): void
{
    test()->withServerVariables(['REMOTE_ADDR' => $ip]);
}

it('la frase del limite è quella chiesta e ha la forma degli altri errori della demo (422 con chiave «ledger»)', function (): void {
    expect(DemoScale::LIMIT_OFF)->toBe('Nella demo hai raggiunto il limite: si azzera ogni notte alle 03:30.');
    demoOn(['saas.demo.limits.creations_per_hour' => 1]);
    fromIp('10.1.0.1');
    Route::middleware('web')->post('/gestionale/soci/azione-prova', fn () => response('fatto'));

    $this->post('/gestionale/soci/azione-prova')->assertOk();
    $this->postJson('/gestionale/soci/azione-prova')->assertStatus(422)->assertJsonValidationErrors(['ledger'])
        ->assertJsonPath('errors.ledger.0', DemoScale::LIMIT_OFF);
});

it('nella demo le creazioni hanno un limite per indirizzo IP: un altro indirizzo ha il suo', function (): void {
    demoOn(['saas.demo.limits.creations_per_hour' => 3]);
    Route::middleware('web')->post('/gestionale/soci/azione-prova', fn () => response('fatto'));

    fromIp('10.1.0.2');
    foreach (range(1, 3) as $i) {
        $this->post('/gestionale/soci/azione-prova')->assertOk();
    }
    $this->post('/gestionale/soci/azione-prova')->assertSessionHasErrors(['ledger' => DemoScale::LIMIT_OFF]);

    fromIp('10.1.0.3');
    $this->post('/gestionale/soci/azione-prova')->assertOk();
});

it('nella demo anche le scritture di ogni tipo hanno un limite per indirizzo IP', function (): void {
    demoOn(['saas.demo.limits.writes_per_hour' => 2, 'saas.demo.limits.creations_per_hour' => 100]);
    Route::middleware('web')->put('/gestionale/soci/azione-prova', fn () => response('fatto'));
    fromIp('10.1.0.4');

    $this->put('/gestionale/soci/azione-prova')->assertOk();
    $this->put('/gestionale/soci/azione-prova')->assertOk();
    $this->put('/gestionale/soci/azione-prova')->assertSessionHasErrors(['ledger' => DemoScale::LIMIT_OFF]);
});

it('i limiti «throttle» delle rotte valgono anche per indirizzo IP, anche se gli utenti sono diversi', function (): void {
    demoOn();
    Route::middleware(['web', 'throttle:2,1'])->get('/gestionale/prova-esportazione', fn () => response('scaricato'));
    $alfa = $this->createUser('alfa@example.com', twoFactor: true);
    $beta = $this->createUser('beta@example.com', twoFactor: true);
    fromIp('10.1.0.5');

    $this->actingAs($alfa)->get('/gestionale/prova-esportazione')->assertOk();
    $this->actingAs($alfa)->get('/gestionale/prova-esportazione')->assertOk();
    // Un altro utente ha il suo conteggio per utente (vuoto), ma lo stesso indirizzo IP.
    $this->actingAs($beta)->get('/gestionale/prova-esportazione')->assertSessionHasErrors(['ledger' => DemoScale::LIMIT_OFF]);
});

it('il tetto dei soci: raggiunto, la creazione è rifiutata con la frase del limite', function (): void {
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user);
    demoOn(['saas.demo.org_slug' => $org->slug, 'saas.demo.limits.people_extra' => 1]);
    fromIp('10.1.0.6');

    $this->actingAs($user)->post('/gestionale/soci', ['first_name' => 'Anna', 'last_name' => 'Rossi'])->assertSessionDoesntHaveErrors(['ledger']);
    $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'card_number' => '7', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']));

    $this->actingAs($user)->post('/gestionale/soci', ['first_name' => 'Luca', 'last_name' => 'Bianchi'])->assertSessionHasErrors(['ledger' => DemoScale::LIMIT_OFF]);
    // Il tetto è «conteggio di partenza + margine»: con il conteggio di partenza scritto più alto, la creazione passa di nuovo.
    $this->orgContext()->run($org->id, fn () => app(OrgSettings::class)->set(DemoLimits::BASELINE.'people', 5));
    $this->actingAs($user)->post('/gestionale/soci', ['first_name' => 'Luca', 'last_name' => 'Bianchi'])->assertSessionDoesntHaveErrors(['ledger']);
});

it('il tetto dei file: con lo spazio pieno ogni creazione è rifiutata', function (): void {
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user);
    demoOn(['saas.demo.org_slug' => $org->slug]);
    fromIp('10.1.0.7');
    $files = Mockery::mock(EncryptedFiles::class);
    $files->shouldReceive('usedBytes')->andReturn(DemoScale::storageBytes());
    app()->instance(EncryptedFiles::class, $files);

    $this->actingAs($user)->post('/gestionale/prima-nota/spese', ['amount' => '1,00'])->assertSessionHasErrors(['ledger' => DemoScale::LIMIT_OFF]);
});

it('i limiti hanno valori di partenza nel codice e in config, uguali', function (): void {
    foreach (DemoScale::LIMITS as $key => $value) {
        expect(config('saas.demo.limits.'.$key))->toBe($value, $key)->and(DemoScale::limit($key))->toBe($value, $key);
    }
    config(['saas.demo.limits' => []]);
    expect(DemoScale::limit('people_extra'))->toBe(DemoScale::LIMITS['people_extra'])->and(DemoScale::storageBytes())->toBe(300 * 1024 ** 2);
});

it('nella demo nessun file passa da nessuna rotta, anche una non presente in alcun elenco', function (): void {
    demoOn();
    Route::middleware('web')->post('/gestionale/rotta-mai-vista-prima', fn () => response('fatto'));
    Route::middleware('web')->post('/gestionale/soci/carica-qualcosa', fn () => response('fatto'));
    fromIp('10.1.0.8');

    foreach (['/gestionale/rotta-mai-vista-prima', '/gestionale/soci/carica-qualcosa', '/area/qualcosa', '/accesso'] as $url) {
        $this->post($url, ['allegato' => UploadedFile::fake()->create('carta.pdf', 10)])
            ->assertStatus(422)->assertJsonPath('errors.ledger.0', DemoScale::UPLOADS_OFF);
    }
    // Anche un file dentro un campo annidato.
    $this->post('/gestionale/soci/carica-qualcosa', ['righe' => [['foto' => UploadedFile::fake()->create('a.png', 5)]]])->assertStatus(422);
    // Senza file la richiesta passa.
    $this->post('/gestionale/soci/carica-qualcosa', ['nota' => 'ciao'])->assertOk();
});

it('fuori dalla demo nessuna protezione: file, scritture ripetute, link e chiusura dei conti passano', function (): void {
    config(['saas.demo.enabled' => false, 'saas.demo.limits.creations_per_hour' => 1, 'saas.demo.limits.writes_per_hour' => 1]);
    Route::middleware('web')->post('/gestionale/soci/azione-prova', fn () => response('fatto'));
    fromIp('10.1.0.9');

    foreach (range(1, 4) as $i) {
        $this->post('/gestionale/soci/azione-prova', ['nota' => 'vedi https://esempio.it', 'f' => UploadedFile::fake()->create('a.pdf', 5)])->assertOk();
    }
    $user = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user);
    $this->actingAs($user)->put('/gestionale/profilo/nome', ['name' => 'Anna Rossi'])->assertSessionDoesntHaveErrors(['ledger']);

    // Anche con SAAS_DEMO acceso, in un ambiente diverso da «demo» non cambia nulla.
    config(['saas.demo.enabled' => true]);
    foreach (['staging', 'local', 'production', 'testing'] as $env) {
        app()->detectEnvironment(fn (): string => $env);
        expect(DemoScale::active())->toBeFalse($env)->and(DemoScale::restricted())->toBeFalse($env)->and(DemoScale::uploadsOff())->toBeFalse($env);
    }
});

dataset('testi con link o HTML', [
    'http' => 'Vedi http://esempio.it',
    'https' => 'Vedi https://esempio.it/pagina',
    'https maiuscolo' => 'VEDI HTTPS://ESEMPIO.IT',
    'www' => 'Scrivici su www.esempio.it',
    'tag' => 'Corso <b>speciale</b>',
    'script' => '<script>alert(1)</script>',
    'tag che chiude' => 'fine</div>',
    'schema ftp' => 'ftp://file.esempio.it',
    'javascript' => 'javascript:alert(1)',
]);

dataset('testi normali', [
    'frase' => 'Corso di yoga per principianti, ogni martedì alle 18:30',
    'maggiore e minore' => 'Quota 10 < 20 euro e 30 > 25',
    'indirizzo' => 'Via Roma 1, 3° piano, scala B',
    'email' => 'mario.rossi@example.com',
    'orario' => 'Dalle 9:00 alle 12:00 (https non c\'entra)',
]);

it('nella demo i testi con link o HTML sono rifiutati, anche dentro campi annidati', function (string $text): void {
    demoOn();
    fromIp('10.1.1.1');

    $this->post('/gestionale/attivita', ['name' => $text])->assertSessionHasErrors(['ledger' => DemoScale::NO_LINKS]);
    $this->post('/gestionale/domande/impostazioni', ['intro' => ['it' => $text]])->assertSessionHasErrors(['ledger' => DemoScale::NO_LINKS]);
    $this->post('/adesione/demo', ['note' => $text])->assertSessionHasErrors(['ledger' => DemoScale::NO_LINKS]);
})->with('testi con link o HTML');

it('nella demo i testi normali e le password non sono toccati dal controllo dei link', function (string $text): void {
    demoOn();
    fromIp('10.1.1.2');

    $this->post('/gestionale/attivita', ['name' => $text])->assertSessionDoesntHaveErrors(['ledger']);
    // Le password non sono testi pubblici: non si controllano.
    $this->post('/accesso', ['email' => 'x@example.com', 'password' => 'www.https://<b>'])->assertSessionDoesntHaveErrors(['ledger']);
})->with('testi normali');

it('nella demo le richieste di sola lettura non sono contate né fermate dal controllo dei link', function (): void {
    demoOn();
    fromIp('10.1.1.3');

    $this->get('/accesso?da=https://esempio.it')->assertOk();
});
