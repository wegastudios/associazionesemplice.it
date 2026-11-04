<?php

declare(strict_types=1);

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Notification;

it('crea un\'associazione, invita il titolare e poi un operatore', function (): void {
    Notification::fake();

    $this->artisan('saas:org:create', [
        'name' => 'Polisportiva Alfa',
        'slug' => 'polisportiva-alfa',
        'owner-email' => 'titolare@example.com',
        'owner-name' => 'Titolare',
    ])->assertSuccessful();

    $org = Org::query()->where('slug', 'polisportiva-alfa')->sole();
    $owner = User::query()->where('email', 'titolare@example.com')->sole();
    expect(app(Memberships::class)->find($owner->id, $org->id)?->role)->toBe(OrgRole::Owner);

    $this->artisan('saas:operator:invite', ['org' => 'polisportiva-alfa', 'email' => 'segreteria@example.com', 'name' => 'Segreteria'])
        ->assertSuccessful();
    $this->artisan('saas:operator:invite', ['org' => 'polisportiva-alfa', 'email' => 'x@example.com', 'name' => 'X', '--role' => 're'])
        ->assertFailed();

    Notification::assertSentTimes(InvitationNotification::class, 2);
});

it('rifiuta un sottodominio non valido', function (): void {
    Notification::fake();

    $this->artisan('saas:org:create', ['name' => 'X', 'slug' => '-no-', 'owner-email' => 'a@example.com', 'owner-name' => 'A'])
        ->assertFailed();
});
