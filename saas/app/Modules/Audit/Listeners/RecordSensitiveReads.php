<?php

declare(strict_types=1);

namespace App\Modules\Audit\Listeners;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\SensitiveFieldRead;

/**
 * Letture dei dati sanitari: una riga per scheda e campo in ogni richiesta (una pagina che legge lo stesso campo
 * dieci volte non scrive dieci righe). L'istanza vive quanto la richiesta (scoped).
 */
final class RecordSensitiveReads
{
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(private readonly AuditLog $audit) {}

    public function handle(SensitiveFieldRead $event): void
    {
        $key = "{$event->orgId}|{$event->table}|{$event->field}|{$event->subjectId}";
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;

        $this->audit->record('health.read', $event->table, $event->subjectId, ['fields' => [$event->field]]);
    }
}
