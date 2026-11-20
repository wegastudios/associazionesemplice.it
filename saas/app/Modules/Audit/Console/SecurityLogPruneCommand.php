<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Toglie i registri di sicurezza più vecchi di 18 mesi: gli eventi di accesso degli operatori (core.security_events) e
 * il registro degli accessi dello staff (core.staff_audit). Non hanno scadenza di legge e contengono indirizzi IP e
 * qualche indirizzo email: non si tengono per sempre. Il registro degli eventi è «solo in aggiunta» (un trigger vieta
 * di cancellare): il trigger si toglie solo dentro questa transazione, per questa sola operazione.
 */
#[AsCommand(name: 'saas:audit:prune-security')]
final class SecurityLogPruneCommand extends Command
{
    /** Mesi di conservazione. Non si scende sotto i 12. */
    public const DEFAULT_MONTHS = 18;

    protected $signature = 'saas:audit:prune-security {--months=18 : Mesi da tenere (almeno 12)} {--dry-run : Conta soltanto}';

    protected $description = 'Toglie i registri di sicurezza più vecchi di 18 mesi';

    public function handle(DatabaseNames $databases, DatabaseManager $db): int
    {
        $months = (int) $this->option('months');
        if ($months < 12) {
            $this->error('Si tengono almeno 12 mesi.');

            return self::FAILURE;
        }
        $limit = now()->subMonths($months);
        $owner = $db->connection($databases->ownerConnection(null));

        $events = $owner->table('core.security_events')->where('occurred_at', '<', $limit)->count();
        $staff = $owner->table('core.staff_audit')->where('created_at', '<', $limit)->count();
        $this->info("Più vecchi di {$months} mesi: {$events} eventi di accesso, {$staff} accessi dello staff.");
        if ($this->option('dry-run') || ($events === 0 && $staff === 0)) {
            return self::SUCCESS;
        }

        $owner->transaction(function () use ($owner, $limit): void {
            $owner->statement('ALTER TABLE core.security_events DISABLE TRIGGER security_events_append_only');
            $owner->table('core.security_events')->where('occurred_at', '<', $limit)->delete();
            $owner->statement('ALTER TABLE core.security_events ENABLE TRIGGER security_events_append_only');
            $owner->table('core.staff_audit')->where('created_at', '<', $limit)->delete();
        });
        $this->line('Tolti.');

        return self::SUCCESS;
    }
}
