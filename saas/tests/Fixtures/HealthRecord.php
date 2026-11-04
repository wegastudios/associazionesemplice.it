<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Cifratura\Contracts\Encrypted;
use App\Modules\Core\Contracts\OrgModel;

/**
 * Modello di prova con un campo sanitario: usa le colonne di app.people finché i certificati medici (fase 1) non
 * avranno la loro tabella.
 *
 * @property string $id
 * @property string|null $phone
 */
final class HealthRecord extends OrgModel
{
    protected $table = 'app.people';

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'phone'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['phone' => Encrypted::class.':health'];
    }
}
