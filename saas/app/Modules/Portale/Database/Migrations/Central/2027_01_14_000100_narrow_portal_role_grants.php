<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use App\Modules\Core\Platform\PortalGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Porta i permessi diretti del ruolo del portale sullo schema `portal` a quelli dell'elenco esplicito `PortalGrants`
 * (lo stesso che leggono i controlli di `saas:db:revoke-group`): solo i verbi previsti, tabella per tabella. Idempotente;
 * vale solo per il ruolo del portale; se il ruolo non esiste non fa nulla. La migrazione precedente (già eseguita) non si
 * tocca: i suoi permessi più larghi vengono ristretti qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = OrgSchema::portalRole();
        if ($role === null) {
            return;
        }
        PortalGrants::apply(DB::connection(), $role);
    }

    public function down(): void
    {
        // I permessi non si allargano di nuovo: il ruolo del portale ha già ciò che gli serve.
    }
};
