<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Database\DatabaseManager;

/**
 * Impostazioni dell'associazione corrente (app.settings). Ogni modulo usa chiavi con il suo prefisso
 * («soci.social_year_start_month»). I valori sono semplici (numeri, testi, booleani).
 */
final readonly class OrgSettings
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->db->connection($this->context->require()->connection)->table('app.settings')->where('key', $key)->value('value');

        return is_string($value) ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key, $default);

        return is_int($value) ? $value : $default;
    }

    public function set(string $key, int|string|bool|null $value): void
    {
        $org = $this->context->require();
        $this->db->connection($org->connection)->table('app.settings')->upsert(
            [['org_id' => $org->id, 'key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now()]],
            ['org_id', 'key'],
            ['value', 'updated_at'],
        );
    }
}
