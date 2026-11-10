<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use App\Modules\Piattaforma\Admin\PlatformAudit;
use App\Modules\Piattaforma\Models\PlatformStaff;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Azzera la verifica in due passaggi di una persona dello staff (telefono perso): la riattiva al prossimo accesso. */
final class ResetStaffTwoFactorCommand extends Command
{
    protected $signature = 'saas:admin:reset-2fa {email}';

    protected $description = 'Azzera la verifica in due passaggi di una persona dello staff';

    public function handle(PlatformAudit $audit): int
    {
        $staff = PlatformStaff::query()->where('email', Str::lower((string) $this->argument('email')))->first();
        if ($staff === null) {
            $this->error('Nessuna persona con questa email.');

            return self::FAILURE;
        }

        $staff->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $audit->record('staff.two_factor_reset', null, $staff->id);
        $this->info('Verifica in due passaggi azzerata: si riattiva al prossimo accesso.');

        return self::SUCCESS;
    }
}
