<?php

declare(strict_types=1);

namespace App\Modules\Portale\Jobs;

use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\ThrottledMail;
use App\Modules\Portale\Notifications\OrgLifecycleNotification;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Un avviso del ciclo di vita (SendLifecycleNoticesCommand) ai titolari e agli amministratori di un'associazione, posta
 * massiva. La prenotazione (core.org_notices) si prende qui, al momento dell'invio, e si toglie se l'invio non riesce: un
 * errore o un'attesa per i tetti non la consumano, così il lavoro (o il giro di domani) ci riprova. Gli indirizzi si
 * leggono all'invio: nella coda non ce n'è nessuno.
 */
final class SendLifecycleNotice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Errori veri dopo i quali il lavoro si arrende; le attese (tetti, server di posta fermo) non contano. */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $orgId,
        public readonly string $until,
        public readonly OrgLifecycleNotification $notification,
    ) {
        $this->onQueue(MailQueue::BULK);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottledMail];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900, 3600];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(MailQueue::BULK_RETRY_HOURS);
    }

    public function handle(DatabaseManager $db): void
    {
        $pgsql = $db->connection('pgsql');
        $claim = ['org_id' => $this->orgId, 'kind' => $this->notification->kind, 'until' => $this->until];
        if ($pgsql->table('core.org_notices')->insertOrIgnore($claim) === 0) {
            return; // già mandato (da un altro lavoro per la stessa scadenza)
        }
        try {
            $emails = $pgsql->table('core.users')->whereIn('id', $pgsql->table('core.org_users')
                ->where('org_id', $this->orgId)->whereIn('role', ['owner', 'admin'])->select('user_id'))->pluck('email')->all();
            foreach ($emails as $email) {
                if (is_string($email)) {
                    Notification::route('mail', $email)->notifyNow($this->notification);
                }
            }
        } catch (Throwable $e) {
            $pgsql->table('core.org_notices')->where($claim)->delete();

            throw $e;
        }
    }
}
