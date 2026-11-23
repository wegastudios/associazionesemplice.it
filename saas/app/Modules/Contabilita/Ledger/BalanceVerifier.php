<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Ledger;

use App\Modules\Cifratura\Contracts\OrgBatch;
use App\Modules\Contabilita\Contracts\BalanceCheck;
use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Saldi dei conti contro i movimenti, per ogni associazione attiva: `app.account_balances` deve essere il saldo di
 * partenza del conto più la somma dei movimenti non stornati (entrate e giroconti in entrata in più, il resto in meno),
 * la stessa regola del rendiconto. Si legge con il ruolo dei backup (sola lettura), un'associazione alla volta. Nulla si
 * corregge: le differenze (associazione e conto) vanno solo in un file con permessi 600 nella cartella condivisa del
 * server; nel log e nella cache solo i conteggi.
 */
final readonly class BalanceVerifier implements BalanceCheck
{
    public const CACHE_KEY = 'saas:balances:last';

    /** File delle differenze conservati, come per le azioni di manutenzione. */
    private const KEEP_FILES = 5;

    private const MISMATCHES = <<<'SQL'
        SELECT a.id AS account_id, b.balance_cents AS balance, a.opening_cents + coalesce(m.total, 0) AS expected
        FROM app.accounts a
        LEFT JOIN app.account_balances b ON b.org_id = a.org_id AND b.account_id = a.id
        LEFT JOIN (
            SELECT account_id, sum(CASE WHEN type IN ('income', 'transfer_in') THEN amount_cents ELSE -amount_cents END) AS total
            FROM app.transactions
            WHERE org_id = ? AND voided_at IS NULL
            GROUP BY account_id
        ) m ON m.account_id = a.id
        WHERE a.org_id = ? AND b.balance_cents IS DISTINCT FROM a.opening_cents + coalesce(m.total, 0)
        ORDER BY a.id
        SQL;

    public function __construct(
        private DatabaseManager $db,
        private DatabaseNames $databases,
        private OrgBatch $pace,
        private Cache $cache,
    ) {}

    /**
     * Controlla tutte le associazioni attive e salva l'esito.
     *
     * @return array{at: int, orgs: int, mismatches: int, failed: int, file: string|null}
     */
    public function verify(): array
    {
        $orgs = $this->db->connection('pgsql')->table('core.orgs')->where('status', 'active')->whereNull('deleted_at')
            ->orderBy('id')->pluck('database', 'id')->all();
        $lines = [];
        $checked = 0;
        $failed = 0;
        foreach ($this->pace->paced(array_keys($orgs)) as $orgId) {
            $database = $orgs[$orgId] ?? null;
            try {
                $rows = $this->db->connection($this->databases->backupConnection(is_string($database) ? $database : null))
                    ->select(self::MISMATCHES, [$orgId, $orgId]);
                $checked++;
                foreach ($rows as $r) {
                    /** @var object{account_id: string, balance: int|string|null, expected: int|string} $r */
                    $lines[] = "associazione {$orgId} conto {$r->account_id} saldo ".($r->balance ?? 'mancante')." atteso {$r->expected}";
                }
            } catch (Throwable $e) {
                // Il testo dell'errore conterrebbe l'associazione: nel file sì, nel log solo il tipo e il conteggio.
                $failed++;
                $lines[] = "associazione {$orgId} controllo non riuscito (".$e::class.')';
            }
        }

        $mismatches = count($lines) - $failed;
        $file = $lines === [] ? null : $this->writeFile($lines);
        if ($mismatches > 0) {
            Log::error('Saldi dei conti diversi dai movimenti', ['conti' => $mismatches]);
        }
        if ($failed > 0) {
            Log::warning('Controllo dei saldi non riuscito', ['associazioni' => $failed]);
        }
        $result = ['at' => now()->getTimestamp(), 'orgs' => $checked, 'mismatches' => $mismatches, 'failed' => $failed];
        $this->cache->forever(self::CACHE_KEY, $result);

        return $result + ['file' => $file];
    }

    public function lastResult(): ?array
    {
        $r = $this->cache->get(self::CACHE_KEY);
        if (! is_array($r) || ! is_int($r['at'] ?? null) || ! is_int($r['orgs'] ?? null) || ! is_int($r['mismatches'] ?? null) || ! is_int($r['failed'] ?? null)) {
            return null;
        }

        return ['at' => $r['at'], 'orgs' => $r['orgs'], 'mismatches' => $r['mismatches'], 'failed' => $r['failed']];
    }

    /** Cartella condivisa del server (~/saas/shared): quella che contiene storage, se non è impostata. */
    public static function directory(): string
    {
        $dir = config('saas.ops_dir');
        if (is_string($dir) && $dir !== '') {
            return rtrim($dir, '/');
        }
        $storage = realpath(storage_path());

        return dirname($storage === false ? storage_path() : $storage);
    }

    /** @param  list<string>  $lines */
    private function writeFile(array $lines): string
    {
        $dir = self::directory();
        $path = $dir.'/saldi-'.now()->utc()->format('Ymd\THis').'.txt';
        $old = umask(0077);
        try {
            if (file_put_contents($path, implode("\n", $lines)."\n") === false) {
                throw new RuntimeException('File delle differenze dei saldi non scritto.');
            }
        } finally {
            umask($old);
        }
        chmod($path, 0600);
        $files = glob($dir.'/saldi-*.txt') ?: [];
        rsort($files);
        foreach (array_slice($files, self::KEEP_FILES) as $stale) {
            @unlink($stale);
        }

        return $path;
    }
}
