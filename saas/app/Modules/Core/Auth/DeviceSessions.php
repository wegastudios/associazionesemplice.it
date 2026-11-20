<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chiude gli accessi di una persona su altri dispositivi: cancella le sessioni e cambia il token «ricordami», così un
 * cookie vecchio non rientra. Si usa a ogni cambio o reimpostazione della password e con «scollega dispositivi».
 */
final class DeviceSessions
{
    /** @param  string|null  $keep  la sessione da tenere (quella di chi sta facendo l'operazione), se c'è */
    public static function closeOthers(User $user, ?string $keep): void
    {
        $sessions = DB::table('core.sessions')->where('user_id', $user->id);
        if ($keep !== null) {
            $sessions->where('id', '!=', $keep);
        }
        $sessions->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
