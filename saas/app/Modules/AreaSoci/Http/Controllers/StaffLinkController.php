<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Http\Controllers;

use App\Modules\AreaSoci\Services\MemberAccess;
use App\Modules\AreaSoci\Services\MemberAccessError;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gestionale: la segreteria prepara il link d'accesso all'area soci (per chi non ha email: si manda su WhatsApp). */
final readonly class StaffLinkController
{
    public function __construct(
        private MemberAccess $access,
        private OrgContext $context,
        private DatabaseManager $db,
    ) {}

    public function create(Request $request, string $person): JsonResponse
    {
        $slug = $this->db->connection('pgsql')->table('core.orgs')->where('id', $this->context->require()->id)->value('slug');
        $user = $request->user()?->getAuthIdentifier();
        try {
            $custom = $this->db->connection('pgsql')->table('core.org_domains')->where('org_id', $this->context->require()->id)->where('state', 'active')->value('domain');
            $link = $this->access->staffLink($person, is_string($user) ? $user : null, self::baseUrl(is_string($slug) ? $slug : '', is_string($custom) ? $custom : null));
        } catch (MemberAccessError $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['link' => $link, 'days' => MemberAccess::STAFF_DAYS]);
    }

    public function logoutAll(string $person): JsonResponse
    {
        return response()->json(['closed' => $this->access->closeAll($person)]);
    }

    /** https://<slug>.<dominio dei soci>, o il dominio personalizzato se verificato, con lo schema dell'applicazione (http in locale). */
    public static function baseUrl(string $slug, ?string $customDomain = null): string
    {
        $url = config('app.url');
        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
        $port = is_string($url) ? parse_url($url, PHP_URL_PORT) : null;
        $domain = config('saas.members_domain');

        $host = $customDomain !== null && $customDomain !== '' ? $customDomain : $slug.'.'.(is_string($domain) ? $domain : 'associazionesemplice.it');

        return (is_string($scheme) ? $scheme : 'https').'://'.$host.(is_int($port) ? ':'.$port : '');
    }
}
