<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Identity\StaffAccess;
use App\Modules\Piattaforma\Identity\StaffAreas;
use App\Modules\Piattaforma\Identity\StaffDirectory;
use App\Modules\Piattaforma\Identity\StaffIdentityConfig;
use App\Modules\Piattaforma\Identity\StaffPerson;
use App\Modules\Piattaforma\Identity\StaffTokenIssuer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * La pagina di accesso unica dello staff (docs/LOGIN_UNICO_E_DATABASE.md, passo 2): email e password; il codice a due
 * passaggi solo se la persona lo ha attivato; poi la scelta dell'area (o il passaggio diretto se è una sola). Il
 * recupero password è uno solo. Spenta (404) finché SAAS_UNIFIED_LOGIN non è acceso.
 *
 * Finché il codice non è giusto la persona NON è autenticata: sta solo «in attesa» nella sessione (10 minuti).
 */
final readonly class StaffLoginController
{
    private const PENDING_SECONDS = 600;

    public function __construct(
        private StaffDirectory $directory,
        private StaffAccess $access,
        private StaffIdentityConfig $identity,
        private StaffTokenIssuer $issuer,
    ) {}

    public static function enabled(): bool
    {
        return config('saas.unified_login.enabled') === true;
    }

    public function show(Request $request): View|RedirectResponse
    {
        $this->open();

        return $this->current($request) !== null ? redirect('/saas/staff/aree') : view('staff.access', ['mode' => 'login']);
    }

    public function login(Request $request): View|RedirectResponse
    {
        $this->open();
        $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:1024']]);
        $email = $request->string('email')->toString();

        $person = $this->directory->authenticate($email, $request->string('password')->toString());
        if ($person === null) {
            $this->directory->audit('staff.login_failed', null, mb_strtolower($email), [], $request->ip());

            return back()->withErrors(['email' => 'Email o password non corrette.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        if ($this->access->hasTwoFactor($person)) {
            $request->session()->put(['staff.pending' => $person->id, 'staff.pending_at' => time()]);

            return redirect('/saas/staff/codice');
        }

        return $this->enter($request, $person);
    }

    public function showCode(Request $request): View|RedirectResponse
    {
        $this->open();

        return $this->pending($request) === null ? redirect('/saas/staff/accedi') : view('staff.access', ['mode' => 'code']);
    }

    public function code(Request $request): View|RedirectResponse
    {
        $this->open();
        $person = $this->pending($request);
        if ($person === null) {
            return redirect('/saas/staff/accedi');
        }
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        if (! $this->access->codeIsValid($person, $request->string('code')->toString())) {
            $this->directory->audit('staff.code_failed', $person->id, null, [], $request->ip());

            return back()->withErrors(['code' => 'Codice non valido.']);
        }

        return $this->enter($request, $person);
    }

    public function areas(Request $request): View|RedirectResponse
    {
        $this->open();
        $person = $this->current($request);
        if ($person === null) {
            return redirect('/saas/staff/accedi');
        }
        $areas = $this->reachable($this->directory->areas($person));

        return view('staff.access', ['mode' => 'areas', 'name' => $person->name, 'areas' => $areas, 'names' => self::NAMES]);
    }

    /** Il passaggio a un'area: un token di 60 secondi, a un solo uso, mandato all'indirizzo fisso dell'area. */
    public function go(Request $request): View|RedirectResponse
    {
        $this->open();
        $person = $this->current($request);
        $sid = $request->session()->get('staff.sid');
        $area = $request->string('area')->toString();
        $areas = $person === null ? [] : $this->reachable($this->directory->areas($person));
        if ($person === null || ! is_string($sid) || ! isset($areas[$area]) || ! $this->identity->enabled()) {
            return redirect('/saas/staff/accedi');
        }
        $this->directory->audit('staff.jump', $person->id, null, ['from' => 'accesso', 'to' => $area], $request->ip());

        return view('staff.access', [
            'mode' => 'go',
            'dest' => $this->destinations()[$area],
            'token' => $this->issuer->issue($person, $area, $sid, $this->directory->areas($person)),
            'area' => self::NAMES[$area] ?? $area,
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->open();
        $sid = $request->session()->get('staff.sid');
        if (is_string($sid)) {
            $this->access->revokeSession($sid);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/saas/staff/accedi');
    }

    public function showForgot(): View
    {
        $this->open();

        return view('staff.access', ['mode' => 'forgot']);
    }

    /** Risponde sempre allo stesso modo: non rivela se l'email c'è. */
    public function forgot(Request $request): View
    {
        $this->open();
        $request->validate(['email' => ['required', 'email', 'max:254']]);
        $this->access->requestReset($request->string('email')->toString(), self::linkBase(), $request->ip());

        return view('staff.access', ['mode' => 'forgot_sent']);
    }

    public function showReset(string $code): View
    {
        $this->open();

        return view('staff.access', $this->access->resetIsOpen($code) ? ['mode' => 'reset', 'code' => $code] : ['mode' => 'reset_invalid']);
    }

    public function reset(Request $request, string $code): View
    {
        $this->open();
        $errors = $this->access->reset($code, $request->string('password')->toString(), $request->string('password_confirmation')->toString(), $request->ip());

        if ($errors === []) {
            return view('staff.access', ['mode' => 'reset_done']);
        }

        return view('staff.access', $this->access->resetIsOpen($code) ? ['mode' => 'reset', 'code' => $code, 'problems' => $errors] : ['mode' => 'reset_invalid']);
    }

    /** Nomi delle aree nella scelta. */
    private const NAMES = ['wordpress' => 'Vendita del plugin', 'saas' => 'Amministrazione SaaS', 'supporto' => 'Supporto'];

    private function open(): void
    {
        abort_unless(self::enabled(), 404);
    }

    private function enter(Request $request, StaffPerson $person): RedirectResponse
    {
        $request->session()->forget(['staff.pending', 'staff.pending_at']);
        $request->session()->regenerate();
        $request->session()->put('staff.sid', $this->directory->openSession($person, $request->ip()));
        $this->directory->audit('staff.login', $person->id, null, ['area' => 'accesso'], $request->ip());

        return redirect('/saas/staff/aree');
    }

    private function current(Request $request): ?StaffPerson
    {
        $sid = $request->session()->get('staff.sid');

        return is_string($sid) && preg_match('/^[0-9a-f]{32}$/', $sid) === 1 ? $this->directory->personOfSession($sid) : null;
    }

    private function pending(Request $request): ?StaffPerson
    {
        $id = $request->session()->get('staff.pending');
        $at = $request->session()->get('staff.pending_at');

        return is_string($id) && is_int($at) && time() - $at <= self::PENDING_SECONDS ? $this->access->find($id) : null;
    }

    /**
     * Le aree della persona che hanno un indirizzo di ingresso.
     *
     * @param  array<string, string>  $areas
     * @return array<string, string>
     */
    private function reachable(array $areas): array
    {
        return array_intersect_key($areas, $this->destinations());
    }

    /**
     * Dove il browser porta il token di ciascuna area (POST, campo `token`): indirizzi fissi, mai presi dalla richiesta.
     *
     * @return array<string, string>
     */
    private function destinations(): array
    {
        $public = config('saas.public_url');
        $portal = config('saas.portal.url');
        $base = rtrim(is_string($public) && $public !== '' ? $public : (is_string($portal) ? $portal : ''), '/');
        $dest = ['wordpress' => $base.'/wordpress/admin/sso', 'saas' => $base.'/saas/admin/sso'];

        $support = config('saas.unified_login.support_sso_url');
        if (is_string($support) && $support !== '' && StaffAreas::exists('supporto')) {
            $dest['supporto'] = $support;
        }

        return $dest;
    }

    private static function linkBase(): string
    {
        $base = config('saas.staff_issuer.invite_base');

        return rtrim(is_string($base) && $base !== '' ? $base : url('/saas'), '/');
    }
}
