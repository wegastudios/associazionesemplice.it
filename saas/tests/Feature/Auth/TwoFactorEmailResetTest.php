<?php

declare(strict_types=1);

use App\Modules\Core\Auth\TwoFactorEmailResetNotification;
use App\Modules\Core\Auth\TwoFactorResetNoticeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

// Dati di prova inventati.

function resetLink(object $test, object $user): string
{
    $url = '';
    Notification::assertSentTo($user, TwoFactorEmailResetNotification::class, function (TwoFactorEmailResetNotification $n) use (&$url): bool {
        $url = $n->url;

        return true;
    });

    return $url;
}

it('toglie la verifica in due passaggi col link ricevuto per email e la fa riattivare al prossimo accesso', function (): void {
    Notification::fake();
    $user = $this->createUser('anna@example.com', twoFactor: true);

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD])->assertRedirect('/verifica-accesso');
    $this->post('/verifica-accesso/recupero-email')->assertSessionHas('status');
    $link = resetLink($this, $user);

    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeTrue();
    $this->get($link)->assertRedirect('/accesso')->assertSessionHas('status');

    $fresh = $user->fresh();
    expect($fresh?->hasConfirmedTwoFactor())->toBeFalse()
        ->and($fresh?->two_factor_recovery_codes)->toBeNull();
    Notification::assertSentTo($user, TwoFactorResetNoticeNotification::class);
    expect(DB::table('core.security_events')->where('user_id', $user->id)->whereIn('event', ['two_factor.reset_requested', 'two_factor.reset_by_email'])->pluck('event')->sort()->values()->all())
        ->toBe(['two_factor.reset_by_email', 'two_factor.reset_requested']);

    // Con la sola password si entra, ma non si va oltre: prima va riattivata.
    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD])->assertRedirect();
    $this->get('/gestionale')->assertRedirect('/account/verifica-in-due-passaggi');
});

it('il link vale una volta sola e non se è stato manomesso', function (): void {
    Notification::fake();
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);
    $this->post('/verifica-accesso/recupero-email');
    $link = resetLink($this, $user);

    $this->get($link.'x')->assertRedirect('/accesso')->assertSessionHasErrors('email');
    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeTrue();

    $this->get($link)->assertSessionHas('status');
    $this->get($link)->assertSessionHasErrors('email');
});

it('senza la password giusta non parte nessuna email', function (): void {
    Notification::fake();
    $user = $this->createUser('anna@example.com', twoFactor: true);

    $this->post('/verifica-accesso/recupero-email')->assertSessionHas('status');
    Notification::assertNothingSent();

    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => 'sbagliata']);
    $this->post('/verifica-accesso/recupero-email');
    Notification::assertNothingSent();
    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeTrue();
});

it('manda al massimo tre link all\'ora per account', function (): void {
    Notification::fake();
    RateLimiter::clear('2fa-reset-mail|x');
    $user = $this->createUser('anna@example.com', twoFactor: true);
    RateLimiter::clear('2fa-reset-mail|'.$user->id);
    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);

    foreach (range(1, 4) as $ignored) {
        $this->post('/verifica-accesso/recupero-email');
    }

    Notification::assertSentToTimes($user, TwoFactorEmailResetNotification::class, 3);
});
