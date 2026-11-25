<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Http\Middleware\AuthenticateMember;
use App\Modules\AreaSoci\Services\MemberAccess;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Soci\Contracts\MemberProfiles;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * «Entra come socio» (solo demo): apre la sessione di UN socio inventato, scelto dal server, senza email né codice (la
 * posta della demo è spenta). Esiste solo con SAAS_DEMO acceso E APP_ENV=demo e solo sull'associazione della demo
 * (DemoScale::memberEntryOpen): altrove risponde 404, come se la rotta non ci fosse. Nulla arriva dalla richiesta
 * (né socio né durata), la sessione dura un'ora, e i limiti per indirizzo IP valgono come nel resto della demo.
 */
final readonly class DemoMemberEntryController
{
    /** Ingressi per indirizzo IP in un'ora. */
    private const PER_HOUR = 20;

    public function __construct(
        private MemberProfiles $profiles,
        private MemberAccess $access,
        private RateLimiter $limiter,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        if (! DemoScale::memberEntryOpen(AccessController::org($request)['slug'])) {
            abort(404);
        }
        $key = 'demo-member-entry:'.($request->ip() ?? 'ignoto');
        if ($this->limiter->tooManyAttempts($key, self::PER_HOUR)) {
            throw ValidationException::withMessages(['demo' => DemoScale::LIMIT_OFF]);
        }
        $this->limiter->hit($key, 3600);

        $personId = $this->profiles->loginCandidates(DemoScale::memberEmail())[0] ?? null;
        if ($personId === null) {
            return redirect('/area/accesso')->with('member_login_message', 'L\'accesso di prova non è ancora pronto: riprova tra qualche minuto.');
        }

        $token = $this->access->open($personId, null, $request->userAgent(), DemoScale::MEMBER_SESSION_MINUTES);
        $request->session()->regenerate();
        $request->session()->forget(AuthenticateMember::ACTIVE);

        return redirect('/area')->withCookie(AccessController::sessionCookie($request, $token, now()->addMinutes(DemoScale::MEMBER_SESSION_MINUTES)));
    }
}
