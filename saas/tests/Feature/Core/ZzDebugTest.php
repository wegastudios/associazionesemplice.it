<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('debug elenco', function (): void {
    $rows = DB::connection('pgsql_owner')->select("SELECT n.nspname || '.' || o.relname AS t, o.relkind AS k, o.relispartition AS p, (SELECT string_agg(a.privilege_type, ',') FROM aclexplode(o.relacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = 'as_app')) AS app FROM pg_class o JOIN pg_namespace n ON n.oid = o.relnamespace WHERE n.nspname IN ('app','audit','core','sites','portal') AND o.relkind IN ('r','p') AND NOT o.relispartition AND NOT EXISTS (SELECT 1 FROM aclexplode(o.relacl) a WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname = 'as_app')) ORDER BY 1");
    expect(array_map(fn ($r) => $r->t.' '.$r->k, $rows))->toBe(['DEBUG']);
});
