<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Core\Auth\TwoFactorResetNoticeNotification;
use App\Modules\Core\Http\Controllers\TwoFactorEmailResetController;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sblocco fatto dal supporto per chi lavora nel gestionale e ha finito le richieste per email (2 al giorno, 6 al mese):
 * toglie la verifica in due passaggi, azzera i conteggi, annota l'evento e avvisa la persona per email. La verifica va
 * riattivata subito al prossimo accesso.
 */
final class ResetUserTwoFactorCommand extends Command
{
    protected $signature = 'saas:user:reset-2fa {email}';

    protected $description = 'Toglie la verifica in due passaggi a chi lavora nel gestionale (sblocco dal supporto)';

    public function handle(SecurityLog $log): int
    {
        $user = User::query()->whereRaw('lower(email) = ?', [Str::lower((string) $this->argument('email'))])->first();
        if (! $user instanceof User) {
            $this->error('Nessun utente con questa email.');

            return self::FAILURE;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        foreach (TwoFactorEmailResetController::KEYS as $prefix) {
            RateLimiter::clear($prefix.$user->id);
        }
        $log->record('two_factor.reset_by_support', (string) $user->id);
        $user->notify(new TwoFactorResetNoticeNotification($user->name, true));
        $this->info('Verifica in due passaggi tolta e conteggi azzerati: la persona la riattiva al prossimo accesso.');

        return self::SUCCESS;
    }
}
