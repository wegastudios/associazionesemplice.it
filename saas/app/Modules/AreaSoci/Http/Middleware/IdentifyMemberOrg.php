<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Middleware;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Area soci: l'associazione si riconosce dal sottodominio (<slug>.<dominio dei soci>). Deve essere attiva e avere il
 * pacchetto Area soci; altrimenti la pagina non esiste.
 */
final readonly class IdentifyMemberOrg
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private Entitlements $entitlements,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route()?->parameter('org');
        $request->route()?->forgetParameter('org');
        $org = is_string($slug) && preg_match('/^[a-z0-9-]{3,40}$/', $slug) === 1
            ? $this->db->connection('pgsql')->table('core.orgs')->where('slug', $slug)->where('status', 'active')->whereNull('deleted_at')->first(['id', 'name', 'slug'])
            : null;
        if ($org === null || ! is_string($org->id ?? null) || ! $this->entitlements->for($org->id)->has(Package::MemberArea)) {
            abort(404);
        }
        $this->context->enter($org->id);
        $request->attributes->set('member_org', ['id' => $org->id, 'name' => is_string($org->name ?? null) ? $org->name : '', 'slug' => (string) $slug]);

        return $next($request);
    }
}
