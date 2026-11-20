<?php

declare(strict_types=1);

use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Auth\ResetPasswordNotification;

const FOREIGN_HOST = 'https://circolo.associazionesemplice.it';

it('non serve accesso, gestionale e API su un indirizzo dei soci o su uno qualunque', function (string $host): void {
    $this->get($host.'/accesso')->assertNotFound();
    $this->get($host.'/gestionale')->assertNotFound();
    $this->get($host.'/password-dimenticata')->assertNotFound();
    $this->post($host.'/password-dimenticata', ['email' => 'anna@example.com'])->assertNotFound();
    $this->get($host.'/saas/admin/accesso')->assertNotFound();
    $this->put($host.'/api/portale/v2/orgs/'.fake()->uuid().'/subscription')->assertNotFound();
})->with([FOREIGN_HOST, 'https://dominio-altrui.example']);

it('lascia l\'area soci e i moduli pubblici sugli indirizzi dei soci', function (): void {
    $this->get(FOREIGN_HOST.'/area/accesso')->assertStatus(404)->assertDontSee('Accedi');
    // La pagina dell'area risponde con il proprio messaggio (associazione inesistente), non con il blocco dell'host.
    expect($this->get(FOREIGN_HOST.'/dona/inesistente')->getStatusCode())->toBe(404);
    $this->get(FOREIGN_HOST.'/up')->assertOk();
});

it('serve l\'accesso sull\'indirizzo dell\'app e su quello pubblico del portale', function (): void {
    $this->get('http://localhost/accesso')->assertOk();

    config(['saas.public_url' => 'https://associazionesemplice.it']);
    $this->get('https://associazionesemplice.it/accesso')->assertOk();
});

it('scrive nelle email i link all\'indirizzo configurato, non a quello della richiesta', function (): void {
    config(['app.url' => 'https://app.example.test']);
    $user = $this->createUser('anna@example.com', twoFactor: true);

    $reset = (new ResetPasswordNotification('token-di-prova'))->toMail($user);
    expect($reset->actionUrl)->toStartWith('https://app.example.test/reimposta-password/token-di-prova');

    $invite = (new InvitationNotification('token-di-prova', 'Circolo Alfa', null))->toMail($user);
    expect($invite->actionUrl)->toStartWith('https://app.example.test/');
});
