<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Database\DatabaseManager;

/**
 * Impostazioni dell'associazione corrente (app.settings). Ogni modulo usa chiavi con il suo prefisso
 * («soci.social_year_start_month»). I valori sono semplici (numeri, testi, booleani).
 *
 * Tutte le impostazioni dell'associazione si leggono con una sola query per richiesta (o per lavoro in coda):
 * il servizio è registrato «scoped» e tiene l'elenco in memoria, per associazione; `set()` lo aggiorna.
 */
final class OrgSettings
{
    /** @var array<string, array<string, string>> valori grezzi (JSON) per associazione */
    private array $loaded = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OrgContext $context,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

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
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        $this->db->connection($org->connection)->table('app.settings')->upsert(
            [['org_id' => $org->id, 'key' => $key, 'value' => $json, 'updated_at' => now()]],
            ['org_id', 'key'],
            ['value', 'updated_at'],
        );

        if (isset($this->loaded[$org->id])) {
            $this->loaded[$org->id][$key] = $json;
        }
    }

    /** @return array<string, string> */
    private function all(): array
    {
        $org = $this->context->require();

        if (! isset($this->loaded[$org->id])) {
            $rows = [];
            foreach ($this->db->connection($org->connection)->table('app.settings')->get(['key', 'value']) as $row) {
                if (is_string($row->key) && is_string($row->value)) {
                    $rows[$row->key] = $row->value;
                }
            }
            $this->loaded[$org->id] = $rows;
        }

        return $this->loaded[$org->id];
    }
}
