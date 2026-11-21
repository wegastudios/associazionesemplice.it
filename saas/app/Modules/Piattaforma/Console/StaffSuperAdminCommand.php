<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/** Indica (o toglie) un super amministratore: l'email serve solo a trovare la persona, il riconoscimento è per identificativo. */
final class StaffSuperAdminCommand extends Command
{
    protected $signature = 'saas:staff:super-admin {email} {--remove : Togli il ruolo}';

    protected $description = 'Indica o toglie un super amministratore dell\'elenco unico';

    public function handle(DatabaseManager $db): int
    {
        $con = $db->connection('pgsql');
        $id = $con->table('core.staff_people')->where('email', mb_strtolower(trim((string) $this->argument('email'))))->value('id');
        if (! is_string($id)) {
            $this->error('Nessuna persona con questa email.');

            return self::FAILURE;
        }
        if ($this->option('remove') === true) {
            $con->table('core.staff_super_admins')->where('person_id', $id)->delete();
            $this->info('Ruolo tolto.');

            return self::SUCCESS;
        }
        $con->table('core.staff_super_admins')->insertOrIgnore(['person_id' => $id]);
        $this->info('Super amministratore indicato.');

        return self::SUCCESS;
    }
}
