<?php

namespace App\Services\Staff;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sessione locale dello staff entrato dal portale: inattività massima 2 ore, durata massima 8, riconferma al portale ogni 5 minuti.
 * Livello cambiato = adottato subito; sessione revocata = chiusa; portale muto per due controlli di fila = chiusa.
 */
class StaffSession
{
    public const LEVELS = ['admin' => 'admin', 'agente' => 'agent'];

    public function __construct(private readonly StaffPortal $portal) {}

    public function open(Request $request, User $user, string $sid, string $level): void
    {
        $now = now()->timestamp;
        $request->session()->put('staff', ['sid' => $sid, 'login_at' => $now, 'seen_at' => $now, 'checked_at' => $now, 'fails' => 0]);
        $user->forceFill(['global_role' => self::LEVELS[$level]])->save();
    }

    /** @return bool true se la sessione resta valida (e viene aggiornata), false se va chiusa */
    public function touch(Request $request, User $user): bool
    {
        $s = $request->session()->get('staff');
        if (! is_array($s)) {
            return true;
        }
        $now = now()->timestamp;
        if ($now - $s['login_at'] > (int) config('support.staff_sso.max_hours') * 3600
            || $now - $s['seen_at'] > (int) config('support.staff_sso.idle_minutes') * 60) {
            return false;
        }
        $s['seen_at'] = $now;

        if ($now - $s['checked_at'] >= (int) config('support.staff_sso.check_seconds')) {
            $answer = $this->portal->checkSession($s['sid']);
            $s['checked_at'] = $now;
            if ($answer === null) {
                $s['fails']++;
                if ($s['fails'] >= 2) {
                    return false;
                }
            } else {
                $s['fails'] = 0;
                $role = $answer['valid'] ? (self::LEVELS[$answer['level'] ?? ''] ?? null) : null;
                if ($role === null) {
                    return false;
                }
                if ($user->global_role !== $role) {
                    $user->forceFill(['global_role' => $role])->save();
                }
            }
        }
        $request->session()->put('staff', $s);

        return true;
    }

    /** Chiude la sessione locale; chi è gestito dal portale perde anche il ruolo. */
    public function close(Request $request, User $user): void
    {
        if ($user->sso_managed) {
            $user->forceFill(['global_role' => null])->save();
        }
        Auth::logout();
        $request->session()->invalidate();
    }
}
