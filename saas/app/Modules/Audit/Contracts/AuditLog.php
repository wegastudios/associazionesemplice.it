<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

/**
 * Registro delle azioni dell'associazione corrente (OrgContext). Si scrive nella stessa transazione della modifica:
 * se la modifica si annulla, si annulla anche la riga del registro.
 *
 *   $audit->record('person.updated', 'app.people', $person->id, ['fields' => ['phone']]);
 *
 * In $details mai valori personali: nomi dei campi, numeri, identificativi. Solo testi brevi, interi, booleani e
 * liste di questi (i decimali cambierebbero forma nel database e romperebbero il sigillo).
 */
interface AuditLog
{
    /** @param  array<string, scalar|list<scalar>|null>  $details */
    public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $details = []): void;
}
