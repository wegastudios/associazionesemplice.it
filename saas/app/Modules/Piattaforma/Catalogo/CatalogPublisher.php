<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

use App\Modules\Piattaforma\Admin\PlatformAudit;
use App\Modules\Portale\Contracts\PortalNotifier;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Bozza e pubblicazione del catalogo (ARCHITETTURA_DOMINI §4.2). Le modifiche non sono visibili finché non si preme
 * «Pubblica», che crea la revisione successiva. Regole che impediscono errori costosi: tutto il catalogo passa dal
 * validatore e una versione di un piano già pubblicata non si cambia mai (una modifica è una `plan_version` nuova).
 */
final readonly class CatalogPublisher
{
    public function __construct(
        private DatabaseManager $db,
        private DbCatalog $catalog,
        private PlatformAudit $audit,
        private PortalNotifier $notifier,
    ) {}

    /**
     * La bozza in lavorazione, o il catalogo in vigore se non c'è.
     *
     * @return array<string, mixed>
     */
    public function draft(): array
    {
        $json = $this->db->connection('pgsql')->table('core.catalog_draft')->where('id', 1)->value('document');
        $decoded = is_string($json) ? json_decode($json, true, 64) : null;
        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        return CatalogValidator::input($this->catalog->published()->document);
    }

    public function hasDraft(): bool
    {
        return $this->db->connection('pgsql')->table('core.catalog_draft')->where('id', 1)->exists();
    }

    /**
     * Controlla e salva la bozza.
     *
     * @param  array<mixed>  $input
     *
     * @throws InvalidArgumentException se non è valida (la bozza non si salva)
     */
    public function saveDraft(array $input, string $staffId): void
    {
        $this->validated($input);

        $this->db->connection('pgsql')->table('core.catalog_draft')->upsert([[
            'id' => 1,
            'document' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_by' => $staffId,
            'updated_at' => now(),
        ]], ['id'], ['document', 'updated_by', 'updated_at']);
        $this->audit->record('catalog.draft_saved', $staffId);
    }

    public function discardDraft(string $staffId): void
    {
        $this->db->connection('pgsql')->table('core.catalog_draft')->where('id', 1)->delete();
        $this->audit->record('catalog.draft_discarded', $staffId);
    }

    /**
     * Pubblica la bozza come revisione successiva e avvisa il portale. Restituisce la nuova `rev`.
     *
     * @throws InvalidArgumentException
     */
    public function publish(string $staffId, ?string $note = null): int
    {
        $rev = $this->db->connection('pgsql')->transaction(function () use ($staffId, $note): int {
            $connection = $this->db->connection('pgsql');
            if (! $this->hasDraft()) {
                throw new InvalidArgumentException('Non c\'è nessuna bozza da pubblicare.');
            }
            // Una pubblicazione alla volta: la riga della bozza e l'ultima revisione fanno da blocco.
            $connection->table('core.catalog_draft')->where('id', 1)->lockForUpdate()->first();
            $current = (int) $connection->table('core.catalog_revisions')->max('rev');
            $currentRev = max($current, $this->catalog->published()->rev);

            $next = $currentRev + 1;
            $input = $this->draft();
            $input['rev'] = $next;
            $input['published_at'] = now()->toDateString();
            $document = $this->validated($input);

            $this->assertVersionsImmutable($document);

            $connection->table('core.catalog_revisions')->insert([
                'rev' => $next,
                'document' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'published_by' => $staffId,
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 300),
                'published_at' => now(),
            ]);
            $connection->table('core.catalog_draft')->where('id', 1)->delete();
            $this->audit->record('catalog.published', $staffId, (string) $next, ['rev' => $next]);

            return $next;
        });

        $this->catalog->refresh();
        // Se il portale non risponde la pubblicazione resta valida: ricarica da sé ogni 10 minuti.
        $delivered = $this->notifier->catalogChanged($rev);
        $this->audit->record($delivered ? 'catalog.portal_notified' : 'catalog.portal_not_notified', $staffId, (string) $rev);

        return $rev;
    }

    /**
     * @param  array<mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input): array
    {
        // `rev` e `published_at` li decide la pubblicazione; nella bozza basta che siano ben formati.
        $input['rev'] = is_int($input['rev'] ?? null) && $input['rev'] > 0 ? $input['rev'] : 1;
        $input['published_at'] = is_string($input['published_at'] ?? null) ? $input['published_at'] : now()->toDateString();

        return CatalogValidator::validate($input);
    }

    /**
     * Ogni versione di un piano già pubblicata deve restare identica: cambiarla di nascosto toccherebbe i clienti che
     * l'hanno già comprata.
     *
     * @param  array<string, mixed>  $document
     */
    private function assertVersionsImmutable(array $document): void
    {
        foreach (is_array($document['plans'] ?? null) ? $document['plans'] : [] as $plan) {
            if (! is_array($plan) || ! is_string($plan['code'] ?? null) || ! is_int($plan['plan_version'] ?? null)) {
                continue;
            }
            $existing = $this->catalog->plan($plan['code'], $plan['plan_version']);
            if ($existing !== null && self::canonical($existing) !== self::canonical($plan)) {
                throw new InvalidArgumentException("La versione {$plan['plan_version']} del piano {$plan['code']} è già pubblicata e non si modifica: crea la versione successiva.");
            }
        }
    }

    private static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::sorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
