<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\View\View;

/** Pagine di sola lettura dell'amministrazione: cruscotto e registro delle azioni. */
final readonly class AdminConsoleController
{
    public function __construct(private DatabaseManager $db) {}

    public function home(): View
    {
        $db = $this->db->connection('pgsql');
        $entitlements = fn () => $db->table('core.org_entitlements as e')->join('core.orgs as o', 'o.id', '=', 'e.org_id')->whereNull('o.deleted_at');

        /** @var array<string, int|string> $byStatus */
        $byStatus = $entitlements()->selectRaw('e.status, count(*) as n')->groupBy('e.status')->pluck('n', 'status')->all();
        $expiring = $entitlements()->whereIn('e.status', ['active', 'trial'])->whereBetween('e.until', [now()->toDateString(), now()->addDays(30)->toDateString()])->count();

        $recent = [];
        foreach ($entitlements()->orderBy('e.until')->limit(6)->get(['o.name', 'e.status', 'e.until', 'e.claims']) as $row) {
            $claims = is_string($row->claims) ? json_decode($row->claims, true) : null;
            $sub = is_array($claims) && is_array($claims['subscription'] ?? null) ? $claims['subscription'] : [];
            $recent[] = [
                'name' => is_string($row->name) ? $row->name : '',
                'status' => is_string($row->status) ? $row->status : '',
                'until' => is_string($row->until) ? $row->until : '',
                'plan' => is_string($sub['plan'] ?? null) ? $sub['plan'] : '—',
            ];
        }

        return view('admin.home', [
            'staff' => $db->table('core.platform_staff')->whereNull('disabled_at')->count(),
            'counts' => array_map(static fn (int|string $n): int => (int) $n, $byStatus),
            'expiring' => $expiring,
            'newOrgs' => $db->table('core.orgs')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(30))->count(),
            'draft' => $db->table('core.catalog_draft')->exists(),
            'recent' => $recent,
            'statuses' => ['trial' => 'In prova', 'active' => 'Attivo', 'past_due' => 'Pagamento in ritardo', 'suspended' => 'Sospeso', 'cancelled' => 'Disdetto'],
        ]);
    }

    public function audit(): View
    {
        $rows = $this->db->connection('pgsql')->table('core.platform_audit as a')
            ->leftJoin('core.platform_staff as s', 's.id', '=', 'a.staff_id')
            ->orderByDesc('a.created_at')->limit(200)
            ->get(['a.created_at', 'a.action', 'a.subject', 'a.ip', 's.email']);

        return view('admin.audit', ['rows' => $rows]);
    }
}
