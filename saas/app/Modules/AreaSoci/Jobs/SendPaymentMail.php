<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Jobs;

use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\ThrottledMail;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Email di un pagamento online (posta massiva, MailQueue::BULK): la ricevuta della donazione pubblica o l'avviso di
 * rimborso. Indirizzo, PDF e testo si ricavano al momento dell'invio; il lavoro è cifrato (il motivo del rimborso è testo
 * libero). Se alla fine non parte lo dice il registro azioni (*_mail_skipped), come prima.
 */
final class SendPaymentMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Errori veri dopo i quali il lavoro si arrende; le attese (tetti, server di posta fermo) non contano. */
    public int $maxExceptions = 3;

    /**
     * @param  'receipt'|'refund'  $kind
     * @param  list<string>  $numbers  ricevute «numero/anno» (donazione)
     * @param  array{paid_on: string, reason: string, org: string, notes: list<string>}|null  $refund
     */
    private function __construct(
        public readonly string $orgId,
        public readonly string $paymentId,
        public readonly string $personId,
        public readonly int $cents,
        public readonly string $kind,
        public readonly array $numbers = [],
        public readonly ?array $refund = null,
    ) {
        $this->onQueue(MailQueue::BULK);
    }

    /** @param  list<string>  $numbers */
    public static function receipts(string $orgId, string $paymentId, string $personId, int $cents, array $numbers): self
    {
        return new self($orgId, $paymentId, $personId, $cents, 'receipt', numbers: $numbers);
    }

    /** @param  list<string>  $notes */
    public static function refund(string $orgId, string $paymentId, string $personId, int $cents, string $paidOn, string $reason, string $orgName, array $notes): self
    {
        return new self($orgId, $paymentId, $personId, $cents, 'refund', refund: ['paid_on' => $paidOn, 'reason' => $reason, 'org' => $orgName, 'notes' => $notes]);
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
        $context->run($this->orgId, function (): void {
            $payments = app(OnlinePayments::class);
            if ($this->refund === null) {
                $payments->deliverDonationReceipts($this->paymentId, $this->personId, $this->numbers, $this->cents);
            } else {
                $payments->deliverRefundNotice($this->paymentId, $this->personId, $this->cents, $this->refund['paid_on'], $this->refund['reason'], $this->refund['org'], $this->refund['notes']);
            }
        });
    }

    /** Non è partita: resta nel registro azioni, senza il testo dell'errore (può contenere l'indirizzo). */
    public function failed(Throwable $e): void
    {
        app(OrgContext::class)->run($this->orgId, fn () => app(AuditLog::class)->record('payment.'.$this->kind.'_mail_skipped', 'app.online_payments', $this->paymentId, ['why' => 'send_failed']));
    }
}
