<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Admin;

use App\Modules\Piattaforma\Http\Middleware\UsePlatformSession;
use App\Modules\Piattaforma\Models\PlatformStaff;
use App\Modules\Portale\Contracts\StaffAccess;
use App\Modules\Portale\Contracts\StaffAccessDenied;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Apre la sessione locale a chi arriva con un token `staff_sso` del portale (docs/UTENTI_E_ACCESSI.md §5). Il secondo
 * fattore è già stato fatto al portale: qui si controlla solo il token, che è monouso. La persona nasce o si aggiorna
 * nella tabella locale con il ruolo del livello; la password locale è casuale e nessuno la conosce.
 */
final readonly class StaffSso
{
    public function __construct(private StaffAccess $access, private DatabaseManager $db, private PlatformAudit $audit) {}

    /** @throws StaffAccessDenied */
    public function signIn(string $token, Request $request): PlatformStaff
    {
        $grant = $this->access->verify($token);
        $db = $this->db->connection('pgsql');

        // I token scaduti non servono più: si ripuliscono qui, a ogni ingresso.
        $db->table('core.platform_sso_tokens')->where('expires_at', '<', now())->delete();
        if ($db->table('core.platform_sso_tokens')->insertOrIgnore([
            'jti' => $grant->jti,
            'expires_at' => date('c', $grant->expiresAt + 60),
        ]) === 0) {
            throw new StaffAccessDenied('token già usato');
        }

        $staff = PlatformStaff::query()->where('email', $grant->email)->first();
        if ($staff?->disabled_at !== null) {
            $this->audit->record('staff.sso_denied', $staff?->id, null, ['reason' => 'disabled'], $request);
            throw new StaffAccessDenied('persona disattivata');
        }
        if ($staff === null) {
            $staff = new PlatformStaff;
            $staff->forceFill([
                'email' => $grant->email,
                'name' => Str::before($grant->email, '@'),
                'password' => Str::random(64),
            ]);
        }
        $staff->forceFill(['role' => PlatformStaff::roleForLevel($grant->level), 'last_login_at' => now()])->save();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard(UsePlatformSession::GUARD)->login($staff);
        $now = now()->getTimestamp();
        $request->session()->put([
            // La sessione dura al massimo 8 ore: il limite del secondo fattore del login locale non si applica.
            'platform.mfa_at' => $grant->mfaAt ?? $now,
            'platform.sso' => ['sid' => $grant->sid, 'level' => $grant->level, 'started' => $now, 'checked' => $now, 'fails' => 0],
            'platform.last' => $now,
        ]);
        $this->audit->record('staff.sso_login', $staff->id, null, ['level' => $grant->level], $request);

        return $staff;
    }
}
