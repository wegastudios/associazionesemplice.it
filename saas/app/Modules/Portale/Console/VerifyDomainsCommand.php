<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use App\Modules\Portale\Entitlements\DomainDns;
use App\Modules\Portale\PortalConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Controlla i domini personalizzati in attesa o in errore. Il cliente delega il dominio ai nostri nameserver; poi il
 * dominio si associa al sottodominio dell'associazione in cPanel e AutoSSL emette il certificato. Qui si vede dall'esterno:
 * delega non ancora visibile → in attesa (errore dopo le ore di tolleranza); delega ok ma senza certificato → in attesa
 * del certificato; delega ok e HTTPS valido → attivo.
 */
final class VerifyDomainsCommand extends Command
{
    protected $signature = 'saas:portal:verify-domains';

    protected $description = 'Controlla delega DNS e certificato dei domini personalizzati in attesa';

    public function handle(DatabaseManager $db, DomainDns $dns, PortalConfig $config): int
    {
        $expected = $config->nameservers();
        $rows = $db->connection('pgsql')->table('core.org_domains')->whereIn('state', ['pending', 'error'])->get(['org_id', 'domain', 'requested_at']);
        $active = 0;
        foreach ($rows as $row) {
            if (! is_string($row->org_id) || ! is_string($row->domain)) {
                continue;
            }
            $delegated = $expected !== [] && array_diff($expected, $dns->nameservers($row->domain)) === [];
            $hours = $config->delegationHours();
            $late = is_string($row->requested_at) && CarbonImmutable::parse($row->requested_at)->addHours($hours)->isPast();

            if ($delegated && $dns->hasCertificate($row->domain)) {
                [$state, $message] = ['active', null];
                $active++;
            } elseif ($delegated) {
                [$state, $message] = ['pending', 'Nameserver corretti: il certificato è in emissione.'];
            } else {
                $list = implode(', ', $expected);
                [$state, $message] = [$late ? 'error' : 'pending', "Il dominio {$row->domain} deve essere delegato ai nameserver {$list}."];
            }
            $db->connection('pgsql')->table('core.org_domains')->where('org_id', $row->org_id)->update(['state' => $state, 'message' => $message, 'checked_at' => now()]);
        }
        $this->info("Domini controllati: {$rows->count()}, attivi: {$active}.");

        return self::SUCCESS;
    }
}
