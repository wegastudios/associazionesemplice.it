<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\View\View;

/** Pagine di sola lettura dell'amministrazione: riepilogo e registro delle azioni. */
final readonly class AdminConsoleController
{
    public function __construct(private DatabaseManager $db) {}

    public function home(): View
    {
        $db = $this->db->connection('pgsql');

        return view('admin.home', [
            'staff' => $db->table('core.platform_staff')->whereNull('disabled_at')->count(),
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
