<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Modello di un'associazione che annota nel registro creazione, modifica, cancellazione e ripristino, con i nomi dei
 * campi toccati (mai i valori). Le colonne cifrate tax_code_enc e tax_code_bidx compaiono come tax_code.
 */
trait Audited
{
    /**
     * Esegue $callback senza annotare le modifiche dei modelli Audited: per le preparazioni in blocco, che scrivono
     * una sola riga riassuntiva.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutAudit(callable $callback): mixed
    {
        $previous = AuditMute::$muted;
        AuditMute::$muted = true;
        try {
            return $callback();
        } finally {
            AuditMute::$muted = $previous;
        }
    }

    public static function bootAudited(): void
    {
        foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, static function (Model $model) use ($event): void {
                if (AuditMute::$muted) {
                    return;
                }
                $fields = $event === 'created' ? array_keys($model->getAttributes()) : array_keys($model->getChanges());
                $fields = array_values(array_unique(array_map(
                    static fn (string $f): string => (string) preg_replace('/_(enc|bidx)$/', '', $f),
                    array_diff($fields, ['org_id', 'id', 'created_at', 'updated_at']),
                )));
                sort($fields);

                if ($event === 'updated' && $fields === []) {
                    return;
                }

                $key = $model->getKey();
                app(AuditLog::class)->record(
                    Str::snake(class_basename($model)).'.'.$event,
                    $model->getTable(),
                    is_string($key) ? $key : null,
                    $event === 'deleted' || $event === 'restored' ? [] : ['fields' => $fields],
                );
            });
        }
    }
}
