<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\View\View;

/** Pagine di sola lettura dell'amministrazione: riepilogo, associazioni, registro delle azioni. */
final readonly class AdminConsoleController
{
    public function __construct(private DatabaseManager $db) {}

    public function home(): View
    {
        $db = $this->db->connection('pgsql');

        return view('admin.home', [
            'orgs' => $db->table('core.orgs')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'staff' => $db->table('core.platform_staff')->whereNull('disabled_at')->count(),
        ]);
    }

    public function orgs(): View
    {
        $rows = $this->db->connection('pgsql')->table('core.orgs as o')
            ->leftJoin('core.org_entitlements as e', 'e.org_id', '=', 'o.id')
            ->orderByDesc('o.created_at')->limit(200)
            ->get(['o.name', 'o.slug', 'o.status', 'o.created_at', 'e.status as subscription', 'e.until', 'e.rev']);

        return view('admin.orgs', ['orgs' => $rows]);
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
