<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Platform\RoleObjectAudit;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Copia dei permessi, in sola lettura, prima di una revoca: per ogni database scrive in FILE le istruzioni GRANT che
 * rimettono i permessi attuali di tutti i ruoli (escluso il proprietario di ciascun oggetto) su schemi, tabelle, viste,
 * sequenze, colonne, funzioni e predefiniti, negli schemi del gestionale. Si legge in una transazione READ ONLY.
 *
 * Il ritorno è il file stesso, eseguito con la connessione del proprietario (`saas:db:grants-copy --ripristina=FILE`):
 * rimette ciò che una revoca ha tolto, non toglie ciò che è stato aggiunto dopo. Nell'uscita solo conteggi; i nomi
 * stanno solo nel file (permessi 600, mai nella cartella web).
 */
#[AsCommand(name: 'saas:db:grants-copy')]
final class DatabaseGrantsCopyCommand extends Command
{
    protected $signature = 'saas:db:grants-copy
        {file : File nuovo dove scrivere la copia (oppure, con --ripristina, il file da rimettere)}
        {--ripristina : Esegue il file con la connessione del proprietario, in una transazione}';

    protected $description = 'Copia dei permessi in sola lettura (e ritorno con --ripristina)';

    /** Istruzioni per tipo: tabelle, viste e simili; sequenze; colonne; funzioni; schemi; predefiniti. */
    private const QUERIES = [
        'tabelle' => "SELECT format('GRANT %s ON TABLE %I.%I TO %I;', a.privilege_type, n.nspname, c.relname, pg_get_userbyid(a.grantee)) AS s
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace, aclexplode(c.relacl) a
            WHERE n.nspname = ANY (CAST(:list AS text[])) AND c.relkind IN ('r', 'p', 'v', 'm', 'f') AND a.grantee <> 0 AND a.grantee <> c.relowner",
        'sequenze' => "SELECT format('GRANT %s ON SEQUENCE %I.%I TO %I;', a.privilege_type, n.nspname, c.relname, pg_get_userbyid(a.grantee)) AS s
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace, aclexplode(c.relacl) a
            WHERE n.nspname = ANY (CAST(:list AS text[])) AND c.relkind = 'S' AND a.grantee <> 0 AND a.grantee <> c.relowner",
        'colonne' => "SELECT format('GRANT %s (%I) ON TABLE %I.%I TO %I;', a.privilege_type, att.attname, n.nspname, c.relname, pg_get_userbyid(a.grantee)) AS s
            FROM pg_attribute att JOIN pg_class c ON c.oid = att.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace, aclexplode(att.attacl) a
            WHERE n.nspname = ANY (CAST(:list AS text[])) AND att.attnum > 0 AND NOT att.attisdropped AND a.grantee <> 0 AND a.grantee <> c.relowner",
        'funzioni' => "SELECT format('GRANT %s ON ROUTINE %s TO %I;', a.privilege_type, CAST(CAST(p.oid AS regprocedure) AS text), pg_get_userbyid(a.grantee)) AS s
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace, aclexplode(p.proacl) a
            WHERE n.nspname = ANY (CAST(:list AS text[])) AND a.grantee <> 0 AND a.grantee <> p.proowner",
        'schemi' => "SELECT format('GRANT %s ON SCHEMA %I TO %I;', a.privilege_type, n.nspname, pg_get_userbyid(a.grantee)) AS s
            FROM pg_namespace n, aclexplode(n.nspacl) a
            WHERE n.nspname = ANY (CAST(:list AS text[])) AND a.grantee <> 0 AND a.grantee <> n.nspowner",
        'predefiniti' => "SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I%s GRANT %s ON %s TO %I;', pg_get_userbyid(d.defaclrole),
                CASE WHEN d.defaclnamespace = 0 THEN '' ELSE format(' IN SCHEMA %I', n.nspname) END, a.privilege_type,
                CASE d.defaclobjtype WHEN 'r' THEN 'TABLES' WHEN 'S' THEN 'SEQUENCES' WHEN 'f' THEN 'FUNCTIONS' WHEN 'T' THEN 'TYPES' ELSE 'SCHEMAS' END,
                pg_get_userbyid(a.grantee)) AS s
            FROM pg_default_acl d LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace, aclexplode(d.defaclacl) a
            WHERE (n.nspname IS NULL OR n.nspname = ANY (CAST(:list AS text[]))) AND a.grantee <> 0 AND a.grantee <> d.defaclrole
              AND pg_has_role(current_user, d.defaclrole, 'MEMBER')",
    ];

    public function handle(DatabaseNames $databases, DatabaseManager $db): int
    {
        $file = (string) $this->argument('file');
        if ($this->option('ripristina')) {
            return $this->restore($databases, $db, $file);
        }
        if (file_exists($file)) {
            $this->components->error('Il file esiste già: la copia si scrive solo in un file nuovo.');

            return self::FAILURE;
        }

        $out = '-- Copia dei permessi del '.gmdate('Y-m-d H:i:s')." UTC. Ritorno: php artisan saas:db:grants-copy --ripristina FILE\n";
        foreach ($databases->names() as $index => $name) {
            $label = $index === 0 ? 'database condiviso' : 'database dedicato '.$index;
            $connection = $db->connection($databases->ownerConnection($name));
            $lines = $this->read($connection);
            $out .= "-- database: {$index}\n".implode("\n", $lines)."\n";
            $this->line("{$label}: ".count($lines).' permessi copiati');
        }

        $umask = umask(0077);
        try {
            $written = file_put_contents($file, $out, LOCK_EX);
        } finally {
            umask($umask);
        }
        if ($written === false) {
            $this->components->error('Non riesco a scrivere il file.');

            return self::FAILURE;
        }
        chmod($file, 0600);
        $this->line('copia dei permessi salvata');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function read(Connection $connection): array
    {
        $schemas = [...RoleObjectAudit::EXPECTED_SCHEMAS];
        foreach ($connection->select("SELECT nspname FROM pg_namespace WHERE nspname ~ '^org_'") as $row) {
            $schemas[] = self::text($row, 'nspname');
        }
        $list = '{'.implode(',', array_map(static fn (string $v): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $schemas)).'}';

        $lines = [];
        $connection->transaction(function () use ($connection, $list, &$lines): void {
            $connection->statement('SET TRANSACTION READ ONLY');
            foreach (self::QUERIES as $sql) {
                foreach ($connection->select($sql.' ORDER BY 1', ['list' => $list]) as $row) {
                    $lines[] = self::text($row, 's');
                }
            }
        });

        return $lines;
    }

    private static function text(mixed $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function restore(DatabaseNames $databases, DatabaseManager $db, string $file): int
    {
        $text = is_file($file) ? file_get_contents($file) : false;
        if ($text === false) {
            $this->components->error('File non trovato.');

            return self::FAILURE;
        }
        // Il file ha una sezione per database («-- database: N»): ognuna si rimette sul suo, in una transazione.
        $parts = preg_split('/^-- database: (\d+)$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $names = $databases->names();
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $index = (int) $parts[$i];
            $statements = array_values(array_filter(array_map('trim', explode("\n", $parts[$i + 1])), static fn (string $l): bool => str_starts_with($l, 'GRANT ') || str_starts_with($l, 'ALTER DEFAULT PRIVILEGES ')));
            if (! isset($names[$index])) {
                $this->components->error("database {$index}: non trovato, niente rimesso");

                return self::FAILURE;
            }
            $connection = $db->connection($databases->ownerConnection($names[$index]));
            $connection->transaction(function () use ($connection, $statements): void {
                foreach ($statements as $statement) {
                    $connection->statement($statement);
                }
            });
            $label = $index === 0 ? 'database condiviso' : 'database dedicato '.$index;
            $this->line("{$label}: ".count($statements).' permessi rimessi');
        }

        return self::SUCCESS;
    }
}
