<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Abbonamenti, in sola lettura, per la vendita e il supporto: quale piano (e versione), in che stato, fino a quando,
 * con quali pacchetti e promozione. Sono i dati che il portale ha comunicato (core.org_entitlements); nomi di soci,
 * operatori e contenuti dell'associazione non si vedono qui: stanno nell'app.
 */
final readonly class AdminSubscriptionsController
{
    private const PER_PAGE = 50;

    private const STATUSES = ['trial' => 'In prova', 'active' => 'Attivo', 'past_due' => 'Pagamento in ritardo', 'suspended' => 'Sospeso', 'cancelled' => 'Disdetto'];

    public function __construct(private DatabaseManager $db) {}

    public function index(Request $request): View
    {
        $status = $request->string('stato')->toString();
        $plan = $request->string('piano')->toString();
        $search = trim($request->string('q')->toString());
        $page = max(1, $request->integer('pagina', 1));

        $query = $this->db->connection('pgsql')->table('core.org_entitlements as e')
            ->join('core.orgs as o', 'o.id', '=', 'e.org_id')
            ->whereNull('o.deleted_at');
        if (isset(self::STATUSES[$status])) {
            $query->where('e.status', $status);
        }
        if ($plan !== '') {
            $query->whereRaw("e.claims->'subscription'->>'plan' = ?", [$plan]);
        }
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('lower(o.name) like ?', [$like])->orWhereRaw('lower(coalesce(o.portal_ref, \'\')) like ?', [$like]));
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('e.until')->orderBy('o.name')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['o.name', 'o.portal_ref', 'e.status', 'e.until', 'e.received_at', 'e.claims']);

        $subscriptions = [];
        foreach ($rows as $row) {
            $claims = is_string($row->claims) ? json_decode($row->claims, true) : null;
            $sub = is_array($claims) && is_array($claims['subscription'] ?? null) ? $claims['subscription'] : [];
            $addons = [];
            foreach (is_array($sub['addons'] ?? null) ? $sub['addons'] : [] as $addon) {
                if (is_array($addon) && is_string($addon['code'] ?? null)) {
                    $addons[] = $addon['code'].(is_int($addon['qty'] ?? null) && $addon['qty'] > 1 ? ' ×'.$addon['qty'] : '');
                }
            }
            $subscriptions[] = [
                'name' => is_string($row->name) ? $row->name : '',
                'ref' => is_string($row->portal_ref) ? $row->portal_ref : '—',
                'status' => is_string($row->status) ? $row->status : '',
                'until' => is_string($row->until) ? $row->until : '—',
                'plan' => is_string($sub['plan'] ?? null) ? $sub['plan'] : '—',
                'plan_version' => is_int($sub['plan_version'] ?? null) ? $sub['plan_version'] : null,
                'members' => is_array($claims) && is_int($claims['members'] ?? null) ? $claims['members'] : null,
                'addons' => $addons,
                'promo' => is_string($sub['promo'] ?? null) ? $sub['promo'] : null,
                'price' => is_int($sub['price_cents'] ?? null) ? number_format($sub['price_cents'] / 100, 2, ',', '') : null,
            ];
        }

        $byStatus = $this->db->connection('pgsql')->table('core.org_entitlements as e')
            ->join('core.orgs as o', 'o.id', '=', 'e.org_id')->whereNull('o.deleted_at')
            ->selectRaw('e.status, count(*) as n')->groupBy('e.status')->pluck('n', 'status')->all();

        return view('admin.subscriptions', [
            'subscriptions' => $subscriptions,
            'statuses' => self::STATUSES,
            'counts' => $byStatus,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'filters' => ['stato' => $status, 'piano' => $plan, 'q' => $search],
        ]);
    }
}
