<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Support\Facades\DB;

// H26, H27: indici per le date degli eventi dell'area soci e per l'elenco dei pagamenti online.

it('gli indici di eventi dell\'area soci e pagamenti online esistono, sono validi e coprono le query', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection(null));
    $rows = $owner->select(<<<'SQL'
        SELECT c.relname AS name, i.indisvalid AS valid, pg_get_indexdef(i.indexrelid) AS def
        FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'app' AND c.relname IN ('activity_sessions_upcoming', 'online_payments_recent')
        ORDER BY c.relname
    SQL);
    expect(array_column($rows, 'name'))->toBe(['activity_sessions_upcoming', 'online_payments_recent'])
        ->and(array_column($rows, 'valid'))->toBe([true, true])
        ->and($rows[0]->def)->toContain('COALESCE(end_date, session_date)')->toContain('WHERE (cancelled_at IS NULL)')
        ->and($rows[1]->def)->toContain('created_at DESC');

    // Elenco dei pagamenti dal più recente: senza scansione completa né ordinamento, il piano legge il nuovo indice.
    $plan = $owner->transaction(function () use ($owner, $org): string {
        $owner->select("SELECT set_config('app.org_id', ?, true)", [$org->id]);
        $owner->statement('SET LOCAL enable_seqscan = off');
        $owner->statement('SET LOCAL enable_sort = off');

        return (string) ($owner->select('EXPLAIN (FORMAT JSON) SELECT id FROM app.online_payments WHERE org_id = ? ORDER BY created_at DESC LIMIT 100', [$org->id])[0]->{'QUERY PLAN'} ?? '');
    });

    expect($plan)->toContain('"Index Name": "online_payments_recent"');
});
