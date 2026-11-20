<?php

declare(strict_types=1);

use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Auth\ResetPasswordNotification;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;

it('non serve accesso, gestionale e API su un indirizzo dei soci o su uno qualunque', function (string $host): void {
    $this->get($host.'/accesso')->assertNotFound();
    $this->get($host.'/gestionale')->assertNotFound();
    $this->get($host.'/password-dimenticata')->assertNotFound();
    $this->post($host.'/password-dimenticata', ['email' => 'anna@example.com'])->assertNotFound();
    $this->get($host.'/saas/admin/accesso')->assertNotFound();
    $this->put($host.'/api/portale/v2/orgs/'.fake()->uuid().'/subscription')->assertNotFound();
})->with(['https://circolo.associazionesemplice.it', 'https://dominio-altrui.example']);

it('lascia l\'area soci sugli indirizzi dei soci', function (): void {
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null);
        }
    });
    $org = $this->createOrg('Polisportiva Alfa');

    $this->get('http://'.$org->slug.'.associazionesemplice.it/area/accesso')->assertOk();
    $this->get('http://'.$org->slug.'.associazionesemplice.it/up')->assertOk();
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

    $invite = (new InvitationNotification('Circolo Alfa', 'token-di-prova'))->toMail($user);
    expect($invite->actionUrl)->toStartWith('https://app.example.test/reimposta-password/token-di-prova');

    $existing = (new InvitationNotification('Circolo Alfa', null))->toMail($user);
    expect($existing->actionUrl)->toBe('https://app.example.test/accesso');
});
