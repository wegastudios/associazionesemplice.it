<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/** Nomina (o toglie) un amministratore della piattaforma. Solo da riga di comando, sul server. */
#[AsCommand(name: 'saas:admin:grant')]
final class PlatformAdminCommand extends Command
{
    protected $signature = 'saas:admin:grant {email : Email di un utente che esiste già} {--revoke : Toglie il ruolo}';

    protected $description = 'Nomina o toglie un amministratore della piattaforma';

    public function handle(SecurityLog $log): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('Utente inesistente: prima va invitato come operatore o creato con saas:org:create.');

            return self::FAILURE;
        }
        $grant = $this->option('revoke') !== true;
        $user->forceFill(['platform_admin' => $grant])->save();
        $log->record($grant ? 'platform_admin.granted' : 'platform_admin.revoked', $user->id);
        $this->info($grant ? 'Amministratore della piattaforma nominato: entra in /admin/piattaforma (con la verifica in due passaggi).' : 'Ruolo tolto.');

        return self::SUCCESS;
    }
}
