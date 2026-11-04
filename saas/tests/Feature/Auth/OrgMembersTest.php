<?php

declare(strict_types=1);

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Auth\ResetPasswordNotification;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

function members(): OrgMembers
{
    return app(OrgMembers::class);
}

it('invita una persona nuova: crea l\'utente e le manda il link per la password', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');

    $user = members()->invite($org, ' Nuova@Example.com ', 'Nuova Persona', OrgRole::Owner, null);

    expect($user->email)->toBe('nuova@example.com')
        ->and(app(Memberships::class)->find($user->id, $org->id)?->role)->toBe(OrgRole::Owner);

    $token = null;
    Notification::assertSentTo($user, InvitationNotification::class, function (InvitationNotification $n) use (&$token): bool {
        $token = $n->token;

        return $n->orgName === 'Polisportiva Alfa' && $n->token !== null;
    });

    // Con il link sceglie la password e può entrare.
    $this->post('/reimposta-password', [
        'token' => $token,
        'email' => 'nuova@example.com',
        'password' => 'la-mia-password-nuova',
        'password_confirmation' => 'la-mia-password-nuova',
    ])->assertRedirect('/accesso');

    expect($user->fresh()?->email_verified_at)->not->toBeNull();
    $this->post('/accesso', ['email' => 'nuova@example.com', 'password' => 'la-mia-password-nuova'])->assertRedirect('/gestionale');
});

it('invita chi ha già un utente senza cambiargli la password', function (): void {
    $existing = $this->createUser('anna@example.com');
    $org = $this->createOrg('Polisportiva Alfa');

    $user = members()->invite($org, 'anna@example.com', 'Altro nome', OrgRole::Accountant, null);

    expect($user->id)->toBe($existing->id)->and($user->name)->toBe('Utente di prova');
    Notification::assertSentTo($user, InvitationNotification::class, fn (InvitationNotification $n) => $n->token === null);
});

it('fa assegnare solo i ruoli permessi', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $admin = new Operator('00000000-0000-7000-8000-000000000001', $org->id, $org->name, OrgRole::Admin);
    $operator = new Operator('00000000-0000-7000-8000-000000000002', $org->id, $org->name, OrgRole::Operator);
    $otherOrgOwner = new Operator('00000000-0000-7000-8000-000000000003', (string) Str::uuid7(), 'Altra', OrgRole::Owner);

    expect(fn () => members()->invite($org, 'x@example.com', 'X', OrgRole::Owner, $admin))->toThrow(AuthorizationException::class)
        ->and(fn () => members()->invite($org, 'x@example.com', 'X', OrgRole::Operator, $operator))->toThrow(AuthorizationException::class)
        ->and(fn () => members()->invite($org, 'x@example.com', 'X', OrgRole::Operator, $otherOrgOwner))->toThrow(AuthorizationException::class);

    expect(User::query()->where('email', 'x@example.com')->exists())->toBeFalse();

    members()->invite($org, 'x@example.com', 'X', OrgRole::Operator, $admin);
    expect(User::query()->where('email', 'x@example.com')->exists())->toBeTrue();
});

it('non lascia un\'associazione senza titolare', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $owner = members()->invite($org, 'titolare@example.com', 'Titolare', OrgRole::Owner, null);
    $by = app(Memberships::class)->find($owner->id, $org->id);

    expect(fn () => members()->remove($org, $owner->id, $by))->toThrow(InvalidArgumentException::class)
        ->and(fn () => members()->changeRole($org, $owner->id, OrgRole::Admin, $by))->toThrow(InvalidArgumentException::class);

    $second = members()->invite($org, 'secondo@example.com', 'Secondo', OrgRole::Owner, $by);
    members()->remove($org, $second->id, $by);

    expect(app(Memberships::class)->find($second->id, $org->id))->toBeNull();
});

it('manda il link per la password con lo stesso messaggio che l\'email esista o no', function (): void {
    $user = $this->createUser('anna@example.com');

    $known = $this->from('/password-dimenticata')->post('/password-dimenticata', ['email' => 'anna@example.com']);
    $unknown = $this->from('/password-dimenticata')->post('/password-dimenticata', ['email' => 'nessuno@example.com']);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
    expect($known->getSession()?->get('status'))->toBe($unknown->getSession()?->get('status'));
    $unknown->assertSessionHasNoErrors();
});

it('rifiuta una password nuova troppo corta', function (): void {
    $user = $this->createUser('anna@example.com');
    $token = app('auth.password.broker')->createToken($user);

    $this->post('/reimposta-password', [
        'token' => $token,
        'email' => 'anna@example.com',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertSessionHasErrors('password');
});
