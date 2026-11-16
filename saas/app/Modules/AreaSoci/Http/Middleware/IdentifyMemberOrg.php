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
        $host = $request->route()?->parameter('host');
        $request->route()?->forgetParameter('org');
        $request->route()?->forgetParameter('host');
        $orgs = $this->db->connection('pgsql')->table('core.orgs');
        if (is_string($slug)) {
            $org = preg_match('/^[a-z0-9-]{3,40}$/', $slug) === 1
                ? $orgs->where('slug', $slug)->where('status', 'active')->whereNull('deleted_at')->first(['id', 'name', 'slug'])
                : null;
        } else {
            // Dominio personalizzato: vale solo se è verificato (stato «active») e l'associazione è attiva.
            $org = is_string($host)
                ? $orgs->join('core.org_domains as d', 'd.org_id', '=', 'core.orgs.id')->where('d.domain', mb_strtolower($host))->where('d.state', 'active')
                    ->where('core.orgs.status', 'active')->whereNull('core.orgs.deleted_at')->first(['core.orgs.id', 'core.orgs.name', 'core.orgs.slug'])
                : null;
        }
        if ($org === null || ! is_string($org->id ?? null) || ! is_string($org->slug ?? null) || ! $this->entitlements->for($org->id)->has(Package::MemberArea)) {
            abort(404);
        }
        $this->context->enter($org->id);
        $request->attributes->set('member_org', ['id' => $org->id, 'name' => is_string($org->name ?? null) ? $org->name : '', 'slug' => $org->slug]);

        return $next($request);
    }
}
