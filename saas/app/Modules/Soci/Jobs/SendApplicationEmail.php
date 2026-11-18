<?php

declare(strict_types=1);

namespace App\Modules\Soci\Jobs;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Services\ApplicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * L'email a chi ha fatto domanda di adesione: ricevuta, accolta o non accolta. Nel lavoro ci sono solo identificativi:
 * indirizzo e testo si ricavano al momento dell'invio, così nella coda (e tra i lavori falliti) non restano dati personali.
 */
final class SendApplicationEmail implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $orgId,
        public readonly string $applicationId,
        public readonly string $kind,
        public readonly string $orgName,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900];
    }

    public function handle(OrgContext $context): void
    {
        $context->run($this->orgId, fn () => app(ApplicationService::class)->sendMail($this->applicationId, $this->kind, $this->orgName));
    }
}
