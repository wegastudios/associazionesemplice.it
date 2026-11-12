<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Portale\Entitlements\DomainDns;
use App\Modules\Portale\Entitlements\PortalOrgServices;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Controlla il DNS dei domini personalizzati in attesa o in errore: se il CNAME punta all'indirizzo dell'associazione il
 * dominio passa a «attivo», altrimenti resta (o diventa) in errore con il motivo. Il certificato lo emette il server.
 */
final class VerifyDomainsCommand extends Command
{
    protected $signature = 'saas:portal:verify-domains';

    protected $description = 'Controlla il DNS dei domini personalizzati in attesa';

    public function handle(DatabaseManager $db, DomainDns $dns, OrgDirectory $orgs, PortalOrgServices $services): int
    {
        $rows = $db->connection('pgsql')->table('core.org_domains')->whereIn('state', ['pending', 'error'])->get(['org_id', 'domain', 'requested_at']);
        $changed = 0;
        foreach ($rows as $row) {
            if (! is_string($row->org_id) || ! is_string($row->domain)) {
                continue;
            }
            $target = ($orgs->slug($row->org_id) ?? '').'.'.$services->rootDomain();
            $ok = in_array($target, $dns->cnames($row->domain), true);
            $db->connection('pgsql')->table('core.org_domains')->where('org_id', $row->org_id)->update([
                'state' => $ok ? 'active' : 'error',
                'message' => $ok ? null : "Il dominio {$row->domain} deve avere un record CNAME verso {$target}.",
                'checked_at' => now(),
            ]);
            $changed += $ok ? 1 : 0;
        }
        $this->info("Domini controllati: {$rows->count()}, attivati: {$changed}.");

        return self::SUCCESS;
    }
}
