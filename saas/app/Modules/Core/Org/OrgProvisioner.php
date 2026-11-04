<?php

declare(strict_types=1);

namespace App\Modules\Core\Org;

use App\Modules\Cifratura\Contracts\OrgKeys;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgCreated;
use App\Modules\Core\Database\DatabaseRegistry;
use App\Modules\Core\Models\Org;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Crea un'associazione: la riga in core.orgs e la sua copia in app.orgs, nel database condiviso o nel suo
 * database dedicato, con la sua chiave dati. Le scritture vanno insieme: se una fallisce, si annulla tutto.
 */
final readonly class OrgProvisioner
{
    public function __construct(
        private DatabaseManager $db,
        private DatabaseRegistry $registry,
        private OrgContext $context,
        private OrgKeys $keys,
    ) {}

    public function create(string $name, string $slug, ?string $database = null): Org
    {
        if ($database !== null && ($database === DatabaseRegistry::SHARED || ! $this->registry->has($database))) {
            throw new InvalidArgumentException("Database dedicato sconosciuto: {$database}");
        }

        return $this->db->connection($this->registry->sharedAppConnection())->transaction(function () use ($name, $slug, $database): Org {
            $org = new Org(['name' => $name, 'slug' => $slug]);
            $org->database = $database;
            $org->save();

            // Chiave dati dell'associazione, cifrata con la chiave principale.
            $this->keys->create($org->id);

            $this->context->run($org->id, function () use ($org): void {
                $now = now();
                $this->db->connection($this->context->require()->connection)->table('app.orgs')->insert([
                    'id' => $org->id,
                    'name' => $org->name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                event(new OrgCreated($org->id));
            });

            return $org;
        });
    }
}
