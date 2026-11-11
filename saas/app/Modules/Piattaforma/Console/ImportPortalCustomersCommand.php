<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Porta i CLIENTI del portale nell'elenco unico delle persone (docs/LOGIN_UNICO_E_DATABASE.md, passo 4).
 *
 * Legge una COPIA del database SQLite del portale (mai l'originale) e non scrive nulla nel portale. Solo identità: email,
 * nome e l'impronta della password; ordini e licenze restano nel portale finché non si importano a parte. Ripetibile
 * (stesso risultato), con prova a vuoto (--dry-run), con verifica (--verify: stesso numero e stessa impronta degli
 * identificativi) e annullabile (--rollback: toglie solo ciò che l'importazione ha creato).
 *
 * Chi ha già un accesso con la stessa email (staff) resta com'è: si aggiunge solo il collegamento al cliente, la sua
 * password non si tocca. I clienti senza password (entravano col link) la sceglieranno dal recupero password.
 */
final class ImportPortalCustomersCommand extends Command
{
    protected $signature = 'saas:import:portal-customers {source : copia del file SQLite del portale} {--dry-run : non scrive nulla, mostra i conteggi} {--verify : confronta il portale con l\'elenco, senza scrivere} {--rollback : annulla l\'importazione}';

    protected $description = 'Importa i clienti del portale nell\'elenco unico delle persone (solo identità)';

    public function handle(DatabaseManager $db): int
    {
        $con = $db->connection('pgsql');

        if ($this->option('rollback') === true) {
            // Le persone non si cancellano con l'accesso dell'applicazione (non ha il permesso, di proposito): l'annullamento usa il ruolo proprietario.
            return $this->rollback($db->connection('pgsql_owner'));
        }

        $source = (string) $this->argument('source');
        if (! is_file($source) || ! extension_loaded('pdo_sqlite')) {
            $this->error('File non trovato o estensione pdo_sqlite mancante.');

            return self::FAILURE;
        }

        try {
            $customers = $this->read($source);
        } catch (Throwable $e) {
            $this->error('Lettura non riuscita: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('verify') === true) {
            return $this->verify($con, $customers);
        }

        $stats = ['source' => count($customers), 'created' => 0, 'linked' => 0, 'unchanged' => 0, 'skipped' => 0, 'without_password' => 0];
        $dry = $this->option('dry-run') === true;

        $run = function () use ($con, $customers, &$stats, $dry): void {
            foreach ($customers as $c) {
                $existing = $con->table('core.staff_people')->where('portal_customer_id', $c['id'])->orWhere('email', $c['email'])->first(['id', 'portal_customer_id', 'password', 'legacy_hash']);
                if ($existing !== null) {
                    $linked = $existing->portal_customer_id === $c['id'];
                    $stats[$linked ? 'unchanged' : 'linked']++;
                    if (! $linked && ! $dry) {
                        // Già una persona con questa email (staff): si aggiunge solo il collegamento.
                        $con->table('core.staff_people')->where('id', $existing->id)->update(['portal_customer_id' => $c['id']]);
                    }

                    continue;
                }
                if ($c['hash'] === null) {
                    $stats['without_password']++;
                }
                $stats['created']++;
                if (! $dry) {
                    $con->table('core.staff_people')->insert([
                        'id' => (string) Str::uuid7(),
                        'email' => $c['email'],
                        'name' => $c['name'],
                        'password' => null,
                        'legacy_hash' => $c['hash'],
                        'portal_customer_id' => $c['id'],
                        'imported_at' => now(),
                        'active' => true,
                    ]);
                }
            }
        };
        $dry ? $run() : $con->transaction($run);

        $stats['skipped'] = $this->skipped;
        foreach ($stats as $label => $n) {
            $this->line(str_pad($label, 18).$n);
        }
        $this->info($dry ? 'Prova a vuoto: nulla è stato scritto.' : 'Importazione finita. Controlla con --verify.');

        return self::SUCCESS;
    }

    private int $skipped = 0;

    /** @return list<array{id: string, email: string, name: string, hash: string|null}> */
    private function read(string $source): array
    {
        $pdo = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = $pdo->query('SELECT id, email, name, password_hash FROM customers ORDER BY id');
        /** @var list<array<string, mixed>> $all */
        $all = $rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($all as $row) {
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            $email = mb_strtolower(trim(is_string($row['email'] ?? null) ? $row['email'] : ''));
            if ($id === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                $this->skipped++;

                continue;
            }
            $name = trim(is_string($row['name'] ?? null) ? $row['name'] : '');
            $hash = is_string($row['password_hash'] ?? null) && $row['password_hash'] !== '' ? $row['password_hash'] : null;
            $out[] = ['id' => $id, 'email' => $email, 'name' => $name !== '' ? mb_substr($name, 0, 200) : Str::before($email, '@'), 'hash' => $hash];
        }

        return $out;
    }

    /** @param  list<array{id: string, email: string, name: string, hash: string|null}>  $customers */
    private function verify(ConnectionInterface $con, array $customers): int
    {
        $ids = array_map(static fn (array $c): string => $c['id'], $customers);
        sort($ids);
        $inDb = $con->table('core.staff_people')->whereNotNull('portal_customer_id')->pluck('portal_customer_id')->map(static fn (mixed $v): string => is_string($v) ? $v : '')->all();
        sort($inDb);

        $same = $ids === $inDb;
        $withHash = count(array_filter($customers, static fn (array $c): bool => $c['hash'] !== null));
        $dbWithHash = $con->table('core.staff_people')->whereNotNull('portal_customer_id')->where(static function (Builder $q): void {
            $q->whereNotNull('legacy_hash')->orWhere(static function (Builder $q2): void {
                $q2->whereNotNull('password')->where('password', '!=', '');
            });
        })->count();

        $this->line('clienti nel portale       '.count($ids));
        $this->line('collegati nell\'elenco     '.count($inDb));
        $this->line('impronta degli id         '.(hash('sha256', implode("\n", $ids)) === hash('sha256', implode("\n", $inDb)) ? 'uguale' : 'DIVERSA'));
        $this->line('con password (portale)   '.$withHash);
        $this->line('con password (elenco)     '.$dbWithHash);

        if (! $same || $dbWithHash < $withHash) {
            $this->error('Verifica NON riuscita.');

            return self::FAILURE;
        }
        $this->info('Verifica riuscita.');

        return self::SUCCESS;
    }

    private function rollback(ConnectionInterface $con): int
    {
        $created = $con->table('core.staff_people')->whereNotNull('imported_at')->whereNotExists(static function (Builder $q): void {
            $q->selectRaw('1')->from('core.staff_roles')->whereColumn('core.staff_roles.person_id', 'core.staff_people.id');
        })->delete();
        // Le persone che c'erano già (staff) perdono solo il collegamento.
        $unlinked = $con->table('core.staff_people')->whereNotNull('portal_customer_id')->update(['portal_customer_id' => null]);
        $this->info("Annullata: {$created} persone tolte, {$unlinked} collegamenti rimossi.");

        return self::SUCCESS;
    }
}
