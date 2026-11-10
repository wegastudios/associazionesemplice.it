<?php

declare(strict_types=1);

namespace App\Modules\Audit\Listeners;

use App\Modules\Audit\Contracts\SecurityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/** Accessi, tentativi falliti, password e verifica in due passaggi finiscono in core.security_events. */
final readonly class RecordSecurityEvents
{
    /** Lo staff della piattaforma ha il suo registro (core.platform_audit): qui solo gli operatori. */
    private const PLATFORM_GUARD = 'platform';

    public function __construct(private SecurityLog $log) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'failed',
            Lockout::class => 'lockout',
            Logout::class => 'logout',
            PasswordReset::class => 'passwordReset',
            TwoFactorAuthenticationChallenged::class => 'twoFactorChallenged',
            TwoFactorAuthenticationFailed::class => 'twoFactorFailed',
            TwoFactorAuthenticationEnabled::class => 'twoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            RecoveryCodesGenerated::class => 'recoveryCodesGenerated',
            RecoveryCodeReplaced::class => 'recoveryCodeUsed',
        ];
    }

    public function login(Login $event): void
    {
        if ($event->guard === self::PLATFORM_GUARD) {
            return;
        }

        $this->log->record('auth.login', self::id($event->user), ['remember' => $event->remember]);
    }

    public function failed(Failed $event): void
    {
        if ($event->guard === self::PLATFORM_GUARD) {
            return;
        }

        // Utente sconosciuto: niente email nel registro, solo l'indirizzo di provenienza.
        $this->log->record('auth.failed', self::id($event->user));
    }

    public function lockout(Lockout $event): void
    {
        $this->log->record('auth.lockout', null);
    }

    public function logout(Logout $event): void
    {
        if ($event->guard === self::PLATFORM_GUARD) {
            return;
        }

        $this->log->record('auth.logout', self::id($event->user));
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->log->record('auth.password_reset', self::id($event->user));
    }

    public function twoFactorChallenged(TwoFactorAuthenticationChallenged $event): void
    {
        $this->log->record('two_factor.challenged', self::id($event->user));
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->log->record('two_factor.failed', self::id($event->user));
    }

    public function twoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->log->record('two_factor.enabled', self::id($event->user));
    }

    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->log->record('two_factor.confirmed', self::id($event->user));
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->log->record('two_factor.disabled', self::id($event->user));
    }

    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->log->record('two_factor.recovery_codes_generated', self::id($event->user));
    }

    public function recoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        $this->log->record('two_factor.recovery_code_used', self::id($event->user));
    }

    private static function id(mixed $user): ?string
    {
        $id = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        return is_string($id) ? $id : null;
    }
}
