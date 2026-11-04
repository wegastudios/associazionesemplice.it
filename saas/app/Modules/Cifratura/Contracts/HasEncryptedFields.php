<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Ricerca esatta su un campo cifrato con indice: Person::whereEncrypted('tax_code', 'RSSMRA80A01H501U').
 * I campi cifrati non compaiono negli array e nel JSON del modello finché non si chiedono esplicitamente.
 */
trait HasEncryptedFields
{
    /** @param  Builder<static>  $query */
    public function scopeWhereEncrypted(Builder $query, string $attribute, string $value): void
    {
        $cast = $this->getCasts()[$attribute] ?? null;
        $options = is_string($cast) && str_starts_with($cast, Encrypted::class.':')
            ? explode(',', substr($cast, strlen(Encrypted::class) + 1))
            : [];
        $normalizer = (new Encrypted(...$options))->index();

        if ($normalizer === null) {
            throw new InvalidArgumentException("Il campo {$attribute} non ha un indice di ricerca.");
        }

        $orgId = Encrypted::orgId([]);
        $index = app(FieldCipher::class)->blindIndex($orgId, Encrypted::field($this, $attribute), $normalizer->apply($value));

        $query->where($this->qualifyColumn($attribute.'_bidx'), Bytea::encode($index));
    }
}
