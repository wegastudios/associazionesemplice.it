<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\OrgPurger;
use App\Modules\Core\Org\OrgProvisioner;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Prova della cancellazione sul server vero: crea un'associazione di prova (mai un'esistente), la sospende, la
 * cancella con lo stesso servizio della cancellazione notturna e controlla che sia sparita e che le altre non siano
 * state toccate. Alla fine toglie anche la lapide: non resta niente.
 */
#[AsCommand(name: 'saas:selftest:purge')]
final class SelfTestPurgeCommand extends Command
{
    protected $signature = 'saas:selftest:purge';

    protected $description = 'Prova la cancellazione dei dati su un\'associazione di prova creata e tolta qui';

    public function handle(OrgProvisioner $provisioner, OrgDirectory $directory, OrgPurger $purger, DatabaseManager $db): int
    {
        $owner = $db->connection('pgsql_owner');
        $othersBefore = $this->snapshot($db);

        $org = $provisioner->create('Prova ciclo di vita', 'prova-'.Str::lower(Str::random(8)), null);
        $id = (string) $org->id;
        $failures = [];

        try {
            Storage::disk('org_files')->put("{$id}/prova.txt", 'prova');
            $directory->setStatus($id, 'suspended');
            $done = $purger->purge($id);
            $this->line("Cancellata: righe {$done['rows']}, utenti {$done['users']}.");

            $row = $owner->table('core.orgs')->where('id', $id)->first(['status', 'deleted_at']);
            if ($row === null || $row->deleted_at === null || $row->status !== 'closed') {
                $failures[] = 'la lapide non è chiusa e datata';
            }
            if ($owner->table('core.org_keys')->where('org_id', $id)->exists()) {
                $failures[] = 'la chiave dati c\'è ancora';
            }
            if (Storage::disk('org_files')->exists("{$id}/prova.txt")) {
                $failures[] = 'il file di prova c\'è ancora';
            }
            if ($this->snapshot($db) !== $othersBefore) {
                $failures[] = 'le altre associazioni sono cambiate';
            }
        } catch (Throwable $e) {
            $failures[] = 'errore: '.$e->getMessage();
        } finally {
            Storage::disk('org_files')->deleteDirectory($id);
            // Si toglie anche la lapide, se si può: il registro di sicurezza è solo in aggiunta e, se ha righe, la lapide resta.
            try {
                foreach (['core.org_notices', 'core.org_roles', 'core.org_entitlements', 'core.promo_redemptions', 'core.org_domains'] as $table) {
                    $owner->table($table)->where('org_id', $id)->delete();
                }
                $owner->table('core.orgs')->where('id', $id)->delete();
            } catch (Throwable) {
                $this->line('Nota: la lapide di prova (prova-…) è rimasta, il registro di sicurezza non si cancella.');
            }
        }

        if ($failures !== []) {
            $this->error('PROVA FALLITA: '.implode('; ', $failures));

            return self::FAILURE;
        }
        $this->info('Prova riuscita: l\'associazione di prova è stata cancellata e le altre non sono state toccate.');

        return self::SUCCESS;
    }

    /** Chiavi e stato delle altre associazioni: devono restare identici. */
    private function snapshot(DatabaseManager $db): string
    {
        $rows = $db->connection('pgsql_owner')->table('core.orgs as o')
            ->leftJoin('core.org_keys as k', 'k.org_id', '=', 'o.id')
            ->where('o.slug', 'not like', 'prova-%')
            ->orderBy('o.id')->get(['o.id', 'o.status', 'o.deleted_at', 'k.org_id as key_org']);

        return md5((string) json_encode($rows));
    }
}
