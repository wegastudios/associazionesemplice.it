<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Admin\PlatformAudit;
use App\Modules\Piattaforma\Http\Middleware\UsePlatformSession;
use App\Modules\Piattaforma\Models\PlatformStaff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Accesso allo staff: email e password, poi il codice a due passaggi (obbligatorio per tutti: alla prima volta si
 * attiva). Finché il codice non è giusto la persona NON è autenticata: sta solo «in attesa» nella sessione.
 */
final readonly class AdminLoginController
{
    private const PENDING_SECONDS = 600;

    public function __construct(private PlatformAudit $audit, private TwoFactorAuthenticationProvider $totp) {}

    public static function url(string $path): string
    {
        return '/'.UsePlatformSession::path().($path === '' ? '' : '/'.$path);
    }

    /** L'accesso unico è acceso (SAAS_UNIFIED_LOGIN): l'accesso è la pagina /accedi del portale, non questo modulo. */
    public static function unified(): bool
    {
        return config('saas.unified_login.enabled') === true;
    }

    /** L'indirizzo pubblico della pagina di accesso unica (portale, alla radice del dominio). */
    public static function portalLogin(): string
    {
        $public = config('saas.public_url');
        $portal = config('saas.portal.url');

        return rtrim(is_string($public) && $public !== '' ? $public : (is_string($portal) ? $portal : 'https://associazionesemplice.it'), '/').'/accedi';
    }

    public function show(): View|RedirectResponse
    {
        // Con l'accesso unico acceso questa pagina non ha più un modulo proprio: rinvia a /accedi del portale.
        if (self::unified()) {
            return redirect()->away(self::portalLogin());
        }

        return Auth::guard(UsePlatformSession::GUARD)->check() ? redirect(self::url('')) : view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        if (self::unified()) {
            return redirect()->away(self::portalLogin());
        }
        $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:200']]);
        $password = $request->string('password')->toString();
        $staff = PlatformStaff::query()->where('email', $request->string('email')->toString())->first();

        // Stesso messaggio con email sconosciuta, password sbagliata o persona disattivata; il controllo della
        // password si fa comunque (contro un hash finto) per non rivelare quali email esistono dal tempo di risposta.
        $ok = Hash::check($password, $staff->password ?? Hash::make('non-esiste'));
        if ($staff === null || ! $ok || $staff->disabled_at !== null) {
            $this->audit->record('staff.login_failed', $staff?->id, null, [], $request);

            return back()->withErrors(['email' => 'Email o password non corrette.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->put(['platform.pending' => $staff->id, 'platform.pending_at' => time()]);

        return redirect(self::url($staff->two_factor_confirmed_at === null ? 'attivazione' : 'verifica'));
    }

    public function showVerify(Request $request): View|RedirectResponse
    {
        $staff = $this->pending($request);

        return $staff === null || $staff->two_factor_confirmed_at === null ? redirect(self::url('accesso')) : view('admin.verify');
    }

    public function verify(Request $request): RedirectResponse
    {
        $staff = $this->pending($request);
        if ($staff === null || $staff->two_factor_confirmed_at === null) {
            return redirect(self::url('accesso'));
        }

        return $this->checkCode($request, $staff);
    }

    public function showEnroll(Request $request): View|RedirectResponse
    {
        $staff = $this->pending($request);
        if ($staff === null || $staff->two_factor_confirmed_at !== null) {
            return redirect(self::url('accesso'));
        }
        if ($staff->two_factor_secret === null) {
            $staff->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->totp->generateSecretKey())])->save();
        }
        $secret = Fortify::currentEncrypter()->decrypt((string) $staff->two_factor_secret);

        return view('admin.enroll', ['qr' => $staff->twoFactorQrCodeSvg(), 'secret' => is_string($secret) ? $secret : '']);
    }

    public function enroll(Request $request): RedirectResponse
    {
        $staff = $this->pending($request);
        if ($staff === null || $staff->two_factor_confirmed_at !== null || $staff->two_factor_secret === null) {
            return redirect(self::url('accesso'));
        }

        return $this->checkCode($request, $staff, confirm: true);
    }

    public function logout(Request $request): RedirectResponse
    {
        $staff = Auth::guard(UsePlatformSession::GUARD)->user();
        $this->audit->record('staff.logout', $staff instanceof PlatformStaff ? $staff->id : null, null, [], $request);
        Auth::guard(UsePlatformSession::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(self::url('accesso'));
    }

    private function checkCode(Request $request, PlatformStaff $staff, bool $confirm = false): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $code = $request->string('code')->toString();
        $secret = Fortify::currentEncrypter()->decrypt((string) $staff->two_factor_secret);

        if (! is_string($secret) || ! $this->totp->verify($secret, $code)) {
            $this->audit->record('staff.code_failed', $staff->id, null, [], $request);

            return back()->withErrors(['code' => 'Codice non valido.']);
        }

        if ($confirm) {
            $staff->forceFill(['two_factor_confirmed_at' => now()])->save();
            $this->audit->record('staff.two_factor_enabled', $staff->id, null, [], $request);
        }
        $staff->forceFill(['last_login_at' => now()])->save();

        $request->session()->forget(['platform.pending', 'platform.pending_at']);
        Auth::guard(UsePlatformSession::GUARD)->login($staff);
        $request->session()->regenerate();
        $request->session()->put('platform.mfa_at', time());
        $this->audit->record('staff.login', $staff->id, null, [], $request);

        return redirect(self::url(''));
    }

    private function pending(Request $request): ?PlatformStaff
    {
        $id = $request->session()->get('platform.pending');
        $at = $request->session()->get('platform.pending_at');
        if (! is_string($id) || ! is_int($at) || time() - $at > self::PENDING_SECONDS) {
            return null;
        }
        $staff = PlatformStaff::query()->find($id);

        return $staff instanceof PlatformStaff && $staff->disabled_at === null ? $staff : null;
    }
}
