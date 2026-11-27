<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Middleware;

use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\AreaSoci\Services\MemberAccess;
use App\Modules\AreaSoci\Services\MemberTwoFactor;
use App\Modules\Audit\Contracts\ActingMember;
use App\Modules\Core\Access\OrgTwoFactor;
use App\Modules\Core\Contracts\HostCookies;
use App\Modules\Soci\Contracts\MemberProfiles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Area soci: il socio della sessione (cookie proprio), con le schede che può aprire. Senza sessione, all'accesso. */
final readonly class AuthenticateMember
{
    public const COOKIE = 'as_socio';

    public const ACTIVE = 'member_active_profile';

    /** Le sole pagine aperte a chi deve ancora accendere la verifica chiesta dall'associazione (anche col prefisso «custom.»). */
    private const SETUP_ROUTES = ['*member.profile', '*member.tfa.*', '*member.logout', '*member.logout.all'];

    /**
     * `as_socio` (percorso /area) senza prefisso; `__Host-as_socio` (percorso /) dove c'è HTTPS (Core\Contracts\HostCookies):
     * un sottodominio fratello non può sostituirlo. Il valore è lo stesso: un codice casuale riconosciuto dal database.
     */
    public static function cookieName(Request $request): string
    {
        return HostCookies::name(self::COOKIE, $request);
    }

    public function __construct(
        private MemberAccess $access,
        private MemberProfiles $profiles,
        private CurrentMember $current,
        private ActingMember $acting,
        private OrgTwoFactor $orgTwoFactor,
        private MemberTwoFactor $twoFactor,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::cookieName($request));
        $session = is_string($token) ? $this->access->resolve($token) : null;
        $org = $request->attributes->get('member_org');
        if ($session === null || ! is_array($org)) {
            return $request->expectsJson() ? response()->json(['error' => 'login'], 401) : redirect('/area/accesso');
        }
        $profiles = $this->profiles->profiles($session['person_id']);
        $wanted = $request->session()->get(self::ACTIVE);
        // Senza scheda propria (chi ha lasciato l'associazione e segue un figlio) si apre la prima scheda disponibile.
        $active = is_string($wanted) && in_array($wanted, array_column($profiles, 'id'), true) ? $wanted : ($profiles[0]['id'] ?? $session['person_id']);
        $this->current->set(['session' => $session['id'], 'person' => $session['person_id'], 'active' => $active, 'profiles' => $profiles,
            'org_name' => is_string($org['name'] ?? null) ? $org['name'] : '', 'org_slug' => is_string($org['slug'] ?? null) ? $org['slug'] : '']);
        $this->acting->set($session['person_id']);

        // L'associazione chiede la verifica ai soci: chi non l'ha accesa vede solo la sezione per accenderla (nel profilo)
        // e può uscire; il resto si rifiuta finché non la conferma.
        if (! $request->routeIs(...self::SETUP_ROUTES) && $this->orgTwoFactor->membersRequiredHere() && ! $this->twoFactor->isOn($session['person_id'])) {
            $this->current->set(null);
            $this->acting->set(null);

            return $request->expectsJson()
                ? response()->json(['error' => 'two_factor'], 403)
                : redirect('/area/profilo#verifica');
        }

        try {
            $response = $next($request);
            // Pagine con dati personali del socio: né il browser né i proxy le tengono in cache.
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        } finally {
            $this->current->set(null);
            $this->acting->set(null);
        }
    }
}
