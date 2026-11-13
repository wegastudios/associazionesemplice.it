<?php

declare(strict_types=1);

namespace App\Modules\Soci\Models;

use App\Modules\Audit\Contracts\Audited;
use App\Modules\Cifratura\Contracts\Encrypted;
use App\Modules\Cifratura\Contracts\HasEncryptedFields;
use App\Modules\Core\Contracts\OrgModel;
use App\Modules\Soci\Contracts\MemberType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Scheda in anagrafica. Nome, cognome, email e tessera in chiaro; i dati sensibili cifrati con la chiave
 * dell'associazione (DATABASE.md §5).
 *
 * @property string $org_id
 * @property string $id
 * @property string|null $user_id
 * @property string $first_name
 * @property string $last_name
 * @property bool $is_entity
 * @property bool|null $entity_same_section
 * @property string|null $email
 * @property string|null $card_number
 * @property string|null $tax_code
 * @property CarbonImmutable|null $birth_date
 * @property string|null $birth_place
 * @property array<string, mixed>|null $address
 * @property string|null $phone
 * @property string|null $iban
 * @property string|null $notes
 * @property MemberType $type
 * @property string|null $level_id
 * @property string|null $host_person_id
 * @property CarbonImmutable|null $joined_on
 * @property CarbonImmutable|null $left_on
 * @property string|null $left_reason
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $anonymized_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> whereEncrypted(string $attribute, string $value)
 */
final class Person extends OrgModel
{
    use Audited;
    use HasEncryptedFields;
    use SoftDeletes;

    protected $table = 'app.people';

    /** @var list<string> */
    protected $fillable = [
        'first_name', 'last_name', 'email', 'card_number', 'user_id',
        'tax_code', 'birth_date', 'birth_place', 'address', 'phone', 'iban', 'notes',
        'is_entity', 'entity_same_section', 'type', 'level_id', 'host_person_id', 'joined_on', 'left_on', 'left_reason',
    ];

    /** @var list<string> */
    protected $hidden = [
        'tax_code_enc', 'tax_code_bidx', 'birth_date_enc', 'birth_place_enc', 'address_enc', 'phone_enc', 'iban_enc', 'notes_enc',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_code' => Encrypted::class.':index=taxcode',
            'birth_date' => Encrypted::class.':date',
            'birth_place' => Encrypted::class,
            'address' => Encrypted::class.':json',
            'phone' => Encrypted::class,
            'iban' => Encrypted::class,
            'notes' => Encrypted::class,
            'type' => MemberType::class,
            'is_entity' => 'boolean',
            'entity_same_section' => 'boolean',
            'joined_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'suspended_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
