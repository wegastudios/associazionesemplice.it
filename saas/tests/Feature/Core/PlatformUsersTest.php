<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Nomi e indirizzi inventati.
function platformAdmin(mixed $test, string $email = 'admin@example.com'): User
{
    $user = $test->createUser($email, twoFactor: true);
    DB::table('core.users')->where('id', $user->id)->update(['platform_admin' => true]);
    $admin = $user->fresh() ?? $user;
    $test->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

    return $admin;
}

it('elenca utenti e associazioni con ruoli e stato, solo agli amministratori della piattaforma', function (): void {
    $anna = $this->createUser('anna@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $anna, OrgRole::Owner);

    $this->actingAs($anna)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/admin/piattaforma/utenti')->assertForbidden();

    platformAdmin($this);
    $this->get('/admin/piattaforma/utenti')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Utenti')
        ->has('users', 2)
        ->where('users.0.email', 'anna@example.com')
        ->where('users.0.disabled', false)
        ->where('users.0.orgs.0.org', 'Polisportiva Alfa')
        ->where('users.0.orgs.0.role', 'owner')
        ->has('orgs', 1)
        ->where('orgs.0.status', 'active')->where('orgs.0.operators', 1));
});

it('disattiva e riattiva un utente, registra l\'azione e non lascia disattivare se stessi', function (): void {
    $anna = $this->createUser('anna@example.com', twoFactor: true);
    $admin = platformAdmin($this);

    $this->post("/admin/piattaforma/utenti/{$anna->id}/disattiva")->assertSessionHas('status');
    expect(DB::table('core.users')->where('id', $anna->id)->value('disabled_at'))->not->toBeNull()
        ->and(DB::table('core.security_events')->where('event', 'platform.user_disabled')->exists())->toBeTrue();

    $this->post("/admin/piattaforma/utenti/{$admin->id}/disattiva")->assertSessionHasErrors('users');
    expect(DB::table('core.users')->where('id', $admin->id)->value('disabled_at'))->toBeNull();

    $this->post("/admin/piattaforma/utenti/{$anna->id}/riattiva")->assertSessionHas('status');
    expect(DB::table('core.users')->where('id', $anna->id)->value('disabled_at'))->toBeNull();
});

it('un utente disattivato perde la sessione alla prima richiesta', function (): void {
    $anna = $this->createUser('anna@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $anna, OrgRole::Owner);
    DB::table('core.users')->where('id', $anna->id)->update(['disabled_at' => now()]);

    $this->actingAs($anna->fresh() ?? $anna)->get('/gestionale')->assertRedirect('/accesso');
    $this->assertGuest();
});

it('sospende e riattiva un\'associazione, ma non una chiusa', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    platformAdmin($this);

    $this->post("/admin/piattaforma/associazioni/{$org->id}/stato", ['status' => 'suspended'])->assertSessionHas('status');
    expect(DB::table('core.orgs')->where('id', $org->id)->value('status'))->toBe('suspended');

    $this->post("/admin/piattaforma/associazioni/{$org->id}/stato", ['status' => 'active'])->assertSessionHas('status');
    expect(DB::table('core.orgs')->where('id', $org->id)->value('status'))->toBe('active');

    $this->post("/admin/piattaforma/associazioni/{$org->id}/stato", ['status' => 'closed'])->assertStatus(422);

    DB::table('core.orgs')->where('id', $org->id)->update(['status' => 'closed']);
    $this->post("/admin/piattaforma/associazioni/{$org->id}/stato", ['status' => 'active'])->assertSessionHasErrors('users');
    expect(DB::table('core.orgs')->where('id', $org->id)->value('status'))->toBe('closed');
});
