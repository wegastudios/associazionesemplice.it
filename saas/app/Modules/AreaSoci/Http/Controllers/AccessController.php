<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Http\Middleware\AuthenticateMember;
use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\AreaSoci\Services\MemberAccess;
use App\Modules\Core\Contracts\Input;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie;

/** Entrata e uscita dei soci: email → link e codice, link della segreteria, scelta della scheda, uscita. */
final readonly class AccessController
{
    private const SENT = 'Se l\'email è di un socio ti abbiamo scritto: apri il link oppure scrivi qui il codice a 6 cifre (guarda anche nella posta indesiderata).';

    public function __construct(
        private MemberAccess $access,
        private CurrentMember $current,
        private RateLimiter $limiter,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('AreaSoci/Accesso', [
            'orgName' => self::org($request)['name'],
            'step' => $request->session()->get('member_login_step', 'email'),
            'email' => $request->session()->get('member_login_email', ''),
            'message' => $request->session()->get('member_login_message'),
        ]);
    }

    public function request(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate(['email' => ['required', 'email:rfc', 'max:254']]));
        $email = mb_strtolower(Input::string($data, 'email'));
        $this->throttle('member-mail:'.$request->ip(), 10, 600);
        // Per email: una richiesta ogni minuto, cinque all'ora. La risposta è comunque la stessa.
        if ($this->limiter->attempt('member-mail:'.hash('sha256', $email), 5, static fn (): bool => true, 3600)) {
            $this->access->requestEmail($email, $request->getSchemeAndHttpHost(), self::org($request)['name']);
        }

        return redirect('/area/accesso')->with('member_login_step', 'code')->with('member_login_email', $email)->with('member_login_message', self::SENT);
    }

    public function code(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'code' => ['required', 'digits:6']]));
        $this->throttle('member-code:'.$request->ip(), 20, 600);
        $person = $this->access->verifyCode(Input::string($data, 'email'), Input::string($data, 'code'));
        if ($person === null) {
            return redirect('/area/accesso')->with('member_login_step', 'code')->with('member_login_email', Input::string($data, 'email'))
                ->withErrors(['code' => 'Codice non corretto o scaduto: riprova o chiedine uno nuovo.']);
        }

        return $this->enter($request, $person, mb_strtolower(Input::string($data, 'email')));
    }

    public function link(Request $request, string $token): RedirectResponse
    {
        $this->throttle('member-link:'.$request->ip(), 20, 600);
        $who = $this->access->consumeLink($token);
        if ($who === null) {
            return redirect('/area/accesso')->with('member_login_message', 'Il link non vale più (è scaduto o è già stato usato): chiedine uno nuovo.');
        }

        return $this->enter($request, $who['person_id'], $who['email']);
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->access->close($this->current->require()['session']);
        $request->session()->invalidate();

        return redirect('/area/accesso')->withCookie(Cookie::create(AuthenticateMember::COOKIE)->withPath('/area')->withExpires(1));
    }

    public function logoutAll(Request $request): RedirectResponse
    {
        $this->access->closeAll($this->current->require()['person']);
        $request->session()->invalidate();

        return redirect('/area/accesso')->withCookie(Cookie::create(AuthenticateMember::COOKIE)->withPath('/area')->withExpires(1))
            ->with('member_login_message', 'Sei uscito da tutti i dispositivi.');
    }

    /** Apre la scheda di un minore di cui si è tutori (o torna alla propria). */
    public function switch(Request $request, string $person): RedirectResponse
    {
        if (! in_array($person, $this->current->ids(), true)) {
            abort(404);
        }
        $request->session()->put(AuthenticateMember::ACTIVE, $person);

        return redirect('/area');
    }

    private function enter(Request $request, string $personId, ?string $email): RedirectResponse
    {
        $token = $this->access->open($personId, $email, $request->userAgent());
        $request->session()->regenerate();
        $request->session()->forget(AuthenticateMember::ACTIVE);
        $cookie = Cookie::create(AuthenticateMember::COOKIE, $token, now()->addDays(MemberAccess::MAX_DAYS), '/area', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);

        return redirect('/area')->withCookie($cookie);
    }

    private function throttle(string $key, int $max, int $seconds): void
    {
        if ($this->limiter->tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['email' => 'Troppi tentativi: riprova tra qualche minuto.']);
        }
        $this->limiter->hit($key, $seconds);
    }

    /** @return array{id: string, name: string, slug: string} */
    public static function org(Request $request): array
    {
        $org = $request->attributes->get('member_org');

        return is_array($org) && is_string($org['id'] ?? null) && is_string($org['name'] ?? null) && is_string($org['slug'] ?? null)
            ? ['id' => $org['id'], 'name' => $org['name'], 'slug' => $org['slug']] : abort(404);
    }
}
