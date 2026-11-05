<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Middleware;

use App\Modules\AreaSoci\Services\CurrentMember;
use App\Modules\AreaSoci\Services\MemberAccess;
use App\Modules\Audit\Contracts\ActingMember;
use App\Modules\Soci\Contracts\MemberProfiles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Area soci: il socio della sessione (cookie proprio), con le schede che può aprire. Senza sessione, all'accesso. */
final readonly class AuthenticateMember
{
    public const COOKIE = 'as_socio';

    public const ACTIVE = 'member_active_profile';

    public function __construct(
        private MemberAccess $access,
        private MemberProfiles $profiles,
        private CurrentMember $current,
        private ActingMember $acting,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE);
        $session = is_string($token) ? $this->access->resolve($token) : null;
        $org = $request->attributes->get('member_org');
        if ($session === null || ! is_array($org)) {
            return $request->expectsJson() ? response()->json(['error' => 'login'], 401) : redirect('/area/accesso');
        }
        $profiles = $this->profiles->profiles($session['person_id']);
        $wanted = $request->session()->get(self::ACTIVE);
        $active = is_string($wanted) && in_array($wanted, array_column($profiles, 'id'), true) ? $wanted : $session['person_id'];
        $this->current->set(['session' => $session['id'], 'person' => $session['person_id'], 'active' => $active, 'profiles' => $profiles,
            'org_name' => is_string($org['name'] ?? null) ? $org['name'] : '', 'org_slug' => is_string($org['slug'] ?? null) ? $org['slug'] : '']);
        $this->acting->set($session['person_id']);

        try {
            return $next($request);
        } finally {
            $this->current->set(null);
            $this->acting->set(null);
        }
    }
}
