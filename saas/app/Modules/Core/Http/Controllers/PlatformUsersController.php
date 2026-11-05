<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Utenti e associazioni della piattaforma: chi è attivo e chi no. Si può disattivare un utente (non entra più, le sessioni
 * aperte si chiudono) e sospendere o riattivare un'associazione. Niente si cancella e nessun dato di un'associazione si
 * legge da qui: solo nomi, indirizzi email, ruoli e stati.
 */
final readonly class PlatformUsersController
{
    public function __construct(
        private DatabaseManager $db,
        private OrgDirectory $orgs,
        private SecurityLog $log,
    ) {}

    public function index(): Response
    {
        $db = $this->db->connection('pgsql');

        $memberships = [];
        $rows = $db->table('core.org_users as m')
            ->join('core.orgs as o', 'o.id', '=', 'm.org_id')
            ->whereNull('o.deleted_at')
            ->orderBy('o.name')
            ->get(['m.user_id', 'm.role', 'o.name as org_name', 'o.status as org_status']);
        foreach ($rows as $r) {
            $memberships[(string) $r->user_id][] = ['org' => (string) $r->org_name, 'role' => (string) $r->role, 'status' => (string) $r->org_status];
        }

        $users = [];
        foreach ($db->table('core.users')->orderBy('created_at')->get(['id', 'name', 'email', 'platform_admin', 'two_factor_confirmed_at', 'disabled_at', 'created_at']) as $u) {
            $users[] = [
                'id' => (string) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'platform_admin' => (bool) $u->platform_admin,
                'two_factor' => $u->two_factor_confirmed_at !== null,
                'disabled' => $u->disabled_at !== null,
                'created_at' => $u->created_at === null ? null : substr((string) $u->created_at, 0, 10),
                'orgs' => $memberships[(string) $u->id] ?? [],
            ];
        }

        $counts = [];
        foreach ($db->table('core.org_users')->selectRaw('org_id, count(*) as n')->groupBy('org_id')->get() as $c) {
            $counts[(string) $c->org_id] = (int) $c->n;
        }
        $orgs = [];
        foreach ($db->table('core.orgs')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'slug', 'status', 'created_at']) as $o) {
            $orgs[] = [
                'id' => (string) $o->id,
                'name' => (string) $o->name,
                'slug' => (string) $o->slug,
                'status' => (string) $o->status,
                'operators' => $counts[(string) $o->id] ?? 0,
                'created_at' => $o->created_at === null ? null : substr((string) $o->created_at, 0, 10),
            ];
        }

        return Inertia::render('Admin/Utenti', ['users' => $users, 'orgs' => $orgs]);
    }

    public function disable(Request $request, string $user): RedirectResponse
    {
        $target = User::query()->findOrFail($user);
        $actor = $request->user();

        if ($actor !== null && (string) $actor->getAuthIdentifier() === $target->id) {
            return back()->withErrors(['users' => 'Non puoi disattivare il tuo utente.']);
        }

        $target->disabled_at = now();
        $target->save();
        $this->log->record('platform.user_disabled', $actor === null ? null : (string) $actor->getAuthIdentifier(), ['user' => $target->id]);

        return back()->with('status', "Utente {$target->email} disattivato: non può più entrare.");
    }

    public function enable(Request $request, string $user): RedirectResponse
    {
        $target = User::query()->findOrFail($user);
        $target->disabled_at = null;
        $target->save();
        $this->log->record('platform.user_enabled', $request->user() === null ? null : (string) $request->user()->getAuthIdentifier(), ['user' => $target->id]);

        return back()->with('status', "Utente {$target->email} riattivato.");
    }

    public function orgStatus(Request $request, string $org): RedirectResponse
    {
        $status = $request->input('status');
        $row = $this->db->connection('pgsql')->table('core.orgs')->where('id', $org)->whereNull('deleted_at')->first(['id', 'name', 'status']);
        abort_if($row === null, 404);
        abort_unless($status === 'active' || $status === 'suspended', 422);
        if ($row->status === 'closed') {
            return back()->withErrors(['users' => 'Un\'associazione chiusa non si riattiva da qui.']);
        }

        $this->orgs->setStatus($org, $status);
        $this->log->record('platform.org_status', $request->user() === null ? null : (string) $request->user()->getAuthIdentifier(), ['org' => $org, 'status' => $status]);

        return back()->with('status', $status === 'suspended' ? "Associazione «{$row->name}» sospesa." : "Associazione «{$row->name}» riattivata. Se ha un abbonamento scaduto sul portale, può essere sospesa di nuovo alla prossima sincronizzazione.");
    }
}
