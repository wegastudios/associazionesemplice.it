<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use App\Modules\Piattaforma\Admin\PlatformAudit;
use App\Modules\Piattaforma\Models\PlatformStaff;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Crea una persona dello staff. Non c'è registrazione: lo staff nasce solo da qui. La password iniziale casuale si
 * stampa una sola volta (il cambio password arriva con la gestione dello staff); la verifica in due passaggi si
 * attiva al primo accesso.
 */
final class CreateStaffCommand extends Command
{
    protected $signature = 'saas:admin:create {email} {name} {--role=admin : admin o readonly}';

    protected $description = 'Crea una persona dello staff dell\'amministrazione della piattaforma';

    public function handle(PlatformAudit $audit): int
    {
        $role = (string) $this->option('role');
        $email = Str::lower((string) $this->argument('email'));
        if (! in_array($role, ['admin', 'readonly'], true) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Email o ruolo non validi (admin o readonly).');

            return self::FAILURE;
        }
        if (PlatformStaff::query()->where('email', $email)->exists()) {
            $this->error('Esiste già una persona con questa email.');

            return self::FAILURE;
        }

        $password = Str::password(20, symbols: false);
        $staff = new PlatformStaff;
        $staff->forceFill(['email' => $email, 'name' => (string) $this->argument('name'), 'role' => $role, 'password' => $password])->save();
        $audit->record('staff.created', null, $staff->id, ['role' => $role]);

        $this->info("Creata: {$email} ({$role}). Password iniziale (mostrata una sola volta):");
        $this->line($password);

        return self::SUCCESS;
    }
}
