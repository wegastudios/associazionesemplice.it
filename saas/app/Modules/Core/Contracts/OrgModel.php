<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base dei modelli con i dati di un'associazione (schemi app, audit, sites).
 *
 * Seconda linea di difesa sopra la Row-Level Security:
 * - la connessione è quella dell'associazione corrente (condivisa o dedicata);
 * - ogni query filtra per `org_id` e ogni nuova riga lo riceve dall'associazione corrente;
 * - senza associazione impostata non parte nessuna query: si ha NoOrgContext, non un risultato vuoto.
 *
 * In Eloquent la chiave è `id` (UUIDv7); nel database la chiave primaria è (org_id, id).
 */
abstract class OrgModel extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['org_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('org', static function (Builder $query): void {
            $org = static::orgContext()->require();
            $query->where($query->getModel()->qualifyColumn('org_id'), $org->id);
        });

        static::creating(static function (OrgModel $model): void {
            $model->setAttribute('org_id', static::orgContext()->require()->id);
        });
    }

    public function getConnectionName(): string
    {
        return static::orgContext()->require()->connection;
    }

    protected static function orgContext(): OrgContext
    {
        return app(OrgContext::class);
    }
}
