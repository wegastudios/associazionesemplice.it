<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use App\Modules\Core\Contracts\OrgContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Campo cifrato di un modello. L'attributo `tax_code` vive nelle colonne `tax_code_enc` (bytea) e, con un indice di
 * ricerca, `tax_code_bidx` (bytea):
 *
 *   'tax_code'   => Encrypted::class.':index=taxcode',
 *   'birth_date' => Encrypted::class.':date',
 *   'address'    => Encrypted::class.':json',
 *   'diagnosis'  => Encrypted::class.':health',          // dato sanitario: anche le letture vanno nel registro
 *
 * Il testo in chiaro non tocca mai il database. Si cerca con HasEncryptedFields::scopeWhereEncrypted().
 *
 * @implements CastsAttributes<string|CarbonImmutable|array<string, mixed>, mixed>
 */
final class Encrypted implements CastsAttributes
{
    private ?Normalizer $index = null;

    private string $type = 'string';

    private bool $health = false;

    public function __construct(string ...$options)
    {
        foreach ($options as $option) {
            if (str_starts_with($option, 'index=')) {
                $this->index = Normalizer::from(substr($option, 6));
            } elseif ($option === 'health') {
                $this->health = true;
            } elseif (in_array($option, ['string', 'date', 'json'], true)) {
                $this->type = $option;
            } else {
                throw new InvalidArgumentException("Opzione di cifratura sconosciuta: {$option}");
            }
        }
    }

    public function index(): ?Normalizer
    {
        return $this->index;
    }

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): string|CarbonImmutable|array|null
    {
        $stored = $attributes[$key.'_enc'] ?? null;
        if ($stored === null) {
            return null;
        }

        $orgId = self::orgId($attributes);
        $plain = self::cipher()->decrypt($orgId, self::field($model, $key), Bytea::decode($stored));

        if ($this->health) {
            $id = $attributes['id'] ?? null;
            event(new SensitiveFieldRead($orgId, $model->getTable(), $key, is_string($id) ? $id : null));
        }

        return match ($this->type) {
            'date' => CarbonImmutable::createFromFormat('!Y-m-d', $plain) ?: throw new DecryptionFailed('Data non valida.'),
            'json' => self::decodeJson($plain),
            default => $plain,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $columns = [$key.'_enc' => null];
        if ($this->index !== null) {
            $columns[$key.'_bidx'] = null;
        }
        if ($value === null || $value === '') {
            return $columns;
        }

        $plain = match ($this->type) {
            'date' => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : CarbonImmutable::parse(self::text($value))->format('Y-m-d'),
            'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            default => self::text($value),
        };

        $orgId = self::orgId($attributes);
        $field = self::field($model, $key);
        $columns[$key.'_enc'] = Bytea::encode(self::cipher()->encrypt($orgId, $field, $plain));
        if ($this->index !== null) {
            $columns[$key.'_bidx'] = Bytea::encode(self::cipher()->blindIndex($orgId, $field, $this->index->apply($plain)));
        }

        return $columns;
    }

    public static function field(Model $model, string $key): string
    {
        return $model->getTable().'.'.$key;
    }

    /** @param  array<string, mixed>  $attributes */
    public static function orgId(array $attributes): string
    {
        $current = app(OrgContext::class)->require()->id;
        $own = $attributes['org_id'] ?? null;

        if (is_string($own) && $own !== $current) {
            throw new LogicException('Il dato appartiene a un\'altra associazione.');
        }

        return $current;
    }

    private static function cipher(): FieldCipher
    {
        return app(FieldCipher::class);
    }

    private static function text(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException('Valore non cifrabile.');
    }

    /** @return array<string, mixed> */
    private static function decodeJson(string $plain): array
    {
        $decoded = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
