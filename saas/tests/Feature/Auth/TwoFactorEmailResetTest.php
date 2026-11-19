<?php

declare(strict_types=1);

use App\Modules\Core\Auth\TwoFactorEmailResetNotification;
use App\Modules\Core\Auth\TwoFactorResetNoticeNotification;
use App\Modules\Core\Http\Controllers\TwoFactorEmailResetController;
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
    // Aprire il link (come fanno i filtri della posta) non toglie niente: mostra solo la pagina col pulsante.
    $this->get($link)->assertOk()->assertInertia(fn ($page) => $page->component('Auth/TwoFactorEmailReset'));
    $this->get($link)->assertOk();
    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeTrue();
    $this->post($link)->assertRedirect('/accesso')->assertSessionHas('status');

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
    $this->post($link.'x')->assertRedirect('/accesso')->assertSessionHasErrors('email');
    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeTrue();

    $this->post($link)->assertSessionHas('status');
    $this->post($link)->assertSessionHasErrors('email');
    $this->get($link)->assertRedirect('/accesso')->assertSessionHasErrors('email');
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

it('manda al massimo 2 link al giorno e 6 al mese per utente, poi dice di scrivere al supporto', function (): void {
    Notification::fake();
    $user = $this->createUser('anna@example.com', twoFactor: true);
    foreach (TwoFactorEmailResetController::KEYS as $prefix) {
        RateLimiter::clear($prefix.$user->id);
    }
    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);

    $this->post('/verifica-accesso/recupero-email')->assertSessionDoesntHaveErrors();
    $this->post('/verifica-accesso/recupero-email')->assertSessionDoesntHaveErrors();
    $this->post('/verifica-accesso/recupero-email')->assertSessionHasErrors(['reset' => 'Hai già chiesto troppi link: scrivi all\'assistenza di Associazione Semplice per sbloccare l\'accesso.']);
    Notification::assertSentToTimes($user, TwoFactorEmailResetNotification::class, 2);

    // Passato il giorno restano i 6 al mese (2 già usati più 3 pregressi: la sesta richiesta passa, la settima no).
    foreach (range(1, 3) as $ignored) {
        RateLimiter::hit(TwoFactorEmailResetController::KEYS[1].$user->id, 30 * 86_400);
    }
    RateLimiter::clear(TwoFactorEmailResetController::KEYS[0].$user->id);
    $this->post('/verifica-accesso/recupero-email')->assertSessionDoesntHaveErrors();
    RateLimiter::clear(TwoFactorEmailResetController::KEYS[0].$user->id);
    $this->post('/verifica-accesso/recupero-email')->assertSessionHasErrors('reset');
    Notification::assertSentToTimes($user, TwoFactorEmailResetNotification::class, 3);
});

it('il link vale 15 minuti', function (): void {
    Notification::fake();
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->post('/accesso', ['email' => 'anna@example.com', 'password' => $this::PASSWORD]);
    $this->post('/verifica-accesso/recupero-email');
    $link = resetLink($this, $user);

    $this->travel(16)->minutes();
    $this->post($link)->assertSessionHasErrors('email');
    $this->get($link)->assertRedirect('/accesso')->assertSessionHasErrors('email');
    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeTrue();
});

it('lo sblocco del supporto toglie la verifica, azzera i conteggi e avvisa la persona', function (): void {
    Notification::fake();
    $user = $this->createUser('anna@example.com', twoFactor: true);
    RateLimiter::hit(TwoFactorEmailResetController::KEYS[0].$user->id, 86_400);
    RateLimiter::hit(TwoFactorEmailResetController::KEYS[0].$user->id, 86_400);

    $this->artisan('saas:user:reset-2fa', ['email' => 'ANNA@example.com'])->assertSuccessful();

    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeFalse()
        ->and(RateLimiter::attempts(TwoFactorEmailResetController::KEYS[0].$user->id))->toBe(0)
        ->and(DB::table('core.security_events')->where('user_id', $user->id)->where('event', 'two_factor.reset_by_support')->exists())->toBeTrue();
    Notification::assertSentTo($user, TwoFactorResetNoticeNotification::class, fn (TwoFactorResetNoticeNotification $n): bool => $n->bySupport);

    $this->artisan('saas:user:reset-2fa', ['email' => 'nessuno@example.com'])->assertFailed();
});
