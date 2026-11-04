<?php

declare(strict_types=1);

namespace App\Modules\Soci\Models;

use App\Modules\Cifratura\Contracts\Encrypted;
use App\Modules\Core\Contracts\OrgModel;
use Carbon\CarbonImmutable;

/**
 * Domanda di adesione online. Nome e cognome in chiaro per l'elenco; tutto il resto del modulo (codice fiscale,
 * nascita, contatti, dati del genitore) cifrato in `data`.
 *
 * @property string $id
 * @property string $status
 * @property string $first_name
 * @property string $last_name
 * @property bool $minor
 * @property array<string, mixed> $data
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $decided_at
 * @property string|null $reason
 * @property string|null $person_id
 */
final class Application extends OrgModel
{
    public $timestamps = false;

    protected $table = 'app.applications';

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'minor', 'data'];

    /** @var list<string> */
    protected $hidden = ['data_enc'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => Encrypted::class.':json',
            'minor' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
