<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Jobs;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Contabilita\Receipts\ReceiptMail;
use App\Modules\Core\Contracts\CurrentOrg;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\OrgContacts;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\ThrottledMail;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * La ricevuta mandata dall'operatore a chi ha pagato (ReceiptsController::send). Di solito parte subito; con la posta
 * ferma o la quota dell'ora finita aspetta qui. Nel lavoro ci sono solo identificativi: PDF, indirizzo e testo si ricavano
 * al momento dell'invio. Senza email o senza i contatti della segreteria, all'invio, non parte.
 */
final class SendReceiptMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Errori veri dopo i quali il lavoro si arrende; le attese (tetti, server di posta fermo) non contano. */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $orgId,
        public readonly string $receiptId,
    ) {
        // Tra gli urgenti: la manda una persona allo sportello, una alla volta, e conta solo sul tetto totale.
        $this->onQueue(MailQueue::URGENT);
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

    public function handle(OrgContext $context): void
    {
        $context->run($this->orgId, function (CurrentOrg $org): void {
            $r = app(ReceiptFiles::class)->document($this->receiptId);
            $contacts = app(OrgContacts::class)->all();
            if ($r['email'] === null || $contacts === null) {
                return;
            }
            self::send($this->receiptId, $r['email'], $r, $contacts, self::orgName($org));
        });
    }

    /**
     * L'email con la ricevuta in PDF, e la riga nel registro azioni.
     *
     * @param  array{number: string, holder: string, pdf: string, filename: string}  $r
     * @param  array{email: string, phone: string}  $contacts
     */
    public static function send(string $receiptId, string $to, array $r, array $contacts, string $orgName): void
    {
        Mail::to($to)->send(new ReceiptMail($orgName, $r['number'], $r['holder'], $r['pdf'], $r['filename'], $contacts['email'], $contacts['phone']));
        app(AuditLog::class)->record('receipt.sent', 'app.receipts', $receiptId, ['number' => $r['number']]);
    }

    public static function orgName(CurrentOrg $org): string
    {
        $name = app(DatabaseManager::class)->connection($org->connection)->table('app.orgs')->value('name');

        return is_string($name) ? $name : '';
    }
}
