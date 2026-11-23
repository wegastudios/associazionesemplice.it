<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// H28, secondo tempo: il registro non ha più l'indice per scheda, né sulla tabella né sulle partizioni.
it('il registro non ha l\'indice per scheda, nemmeno sulle partizioni', function (): void {
    $names = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.relname AS name FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
        WHERE i.indrelid = 'audit.log'::regclass
           OR i.indrelid IN (SELECT inhrelid FROM pg_inherits WHERE inhparent = 'audit.log'::regclass)
    SQL);
    $names = array_map(static fn (object $r): string => (string) $r->name, $names);

    expect($names)->not->toBeEmpty()
        ->and(array_filter($names, static fn (string $n): bool => str_contains($n, 'subject')))->toBe([]);
});
