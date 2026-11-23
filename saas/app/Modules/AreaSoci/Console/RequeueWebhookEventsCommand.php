<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Console;

use App\Modules\AreaSoci\Contracts\PaymentWebhookHealth;
use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Ogni 5 minuti (H14): in ogni associazione gli eventi dei webhook dei pagamenti rimasti in coda senza lavoro tornano in
 * coda, così l'incasso si registra anche se il socio ha chiuso la pagina di ritorno. Il conteggio di quelli fermi da più
 * di mezz'ora va a saas:health.
 */
#[AsCommand(name: 'saas:payments:requeue-webhooks')]
final class RequeueWebhookEventsCommand extends Command
{
    protected $signature = 'saas:payments:requeue-webhooks';

    protected $description = 'Rimette in coda gli eventi dei pagamenti online rimasti senza lavoro';

    public function handle(OrgContext $context, DatabaseManager $db): int
    {
        $ids = $db->connection('pgsql')->table('core.orgs')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
        $stale = 0;
        $failed = 0;
        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }
            try {
                /** @var array{requeued: int, stale: int} $r */
                $r =$context->run($id, static fn (): array => app(OnlinePayments::class)->requeueStaleEvents(PaymentWebhookHealth::REQUEUE_MINUTES, PaymentWebhookHealth::STALE_MINUTES));
                $stale += $r['stale'];
                if ($r['requeued'] > 0) {
                    $this->line("{$id}: {$r['requeued']} eventi rimessi in coda");
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$id}: {$e->getMessage()}");
            }
        }
        PaymentWebhookHealth::record($stale);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
