<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use Illuminate\Database\Connection;

/**
 * Righe del registro scritte dentro una transazione e non ancora legate alla catena, per connessione. Si legano tutte
 * insieme come ultima cosa prima della conferma (ChainedAuditLog::beforeCommit): la testa della catena si blocca una
 * volta sola, sempre per ultima, e solo per il tempo di un inserimento. Le righe seguono la transazione: un ritorno a
 * un punto di salvataggio toglie quelle scritte dopo, l'annullamento le toglie tutte.
 *
 * Il livello di base è quello della connessione prima della prima transazione vista: 0 in produzione, 1 nei test, dove
 * la transazione che avvolge ogni test si apre senza eventi. Fuori da una transazione (o solo dentro quella del test)
 * le righe si scrivono subito, come prima.
 */
final class PendingEntries
{
    /** @var array<string, int> */
    private array $base = [];

    /** @var array<string, list<array{level: int, draft: Draft}>> */
    private array $items = [];

    public function defers(Connection $connection): bool
    {
        $base = $this->base[self::name($connection)] ?? null;

        return $base !== null && $connection->transactionLevel() > $base;
    }

    public function add(Connection $connection, Draft $draft): void
    {
        $this->items[self::name($connection)][] = ['level' => $connection->transactionLevel(), 'draft' => $draft];
    }

    public function began(Connection $connection): void
    {
        $name = self::name($connection);
        $level = $connection->transactionLevel();
        $this->base[$name] ??= $level - 1;
        // Righe rimaste a questo livello: di un punto di salvataggio annullato senza evento (stallo nella transazione interna).
        $this->keep($name, $level - 1);
    }

    /**
     * Conferma vera (dal livello 1 al livello 0): le righe da legare adesso, ancora dentro la transazione.
     *
     * @return list<Draft>
     */
    public function committing(Connection $connection): array
    {
        $name = self::name($connection);

        return ($this->base[$name] ?? null) === 0 ? $this->take($name, 1) : [];
    }

    /**
     * Dopo una conferma: le righe del livello confermato passano a quello sotto. Tornati al livello di base si legano
     * quelle rimaste (nei test: dentro la transazione del test; in produzione sono già state legate prima della conferma).
     *
     * @return list<Draft>
     */
    public function committed(Connection $connection): array
    {
        $name = self::name($connection);
        $base = $this->base[$name] ?? null;
        if ($base === null) {
            return [];
        }

        $level = $connection->transactionLevel();
        $items = [];
        foreach ($this->items[$name] ?? [] as $item) {
            if ($item['level'] <= $level + 1) {
                $items[] = ['level' => min($item['level'], $level), 'draft' => $item['draft']];
            }
        }
        $this->items[$name] = $items;

        if ($level > $base) {
            return [];
        }

        $drafts = $this->take($name, $level);
        $this->forget($connection);

        return $drafts;
    }

    public function rolledBack(Connection $connection): void
    {
        $name = self::name($connection);
        $base = $this->base[$name] ?? null;
        if ($base === null) {
            return;
        }

        $level = $connection->transactionLevel();
        $level <= $base ? $this->forget($connection) : $this->keep($name, $level);
    }

    public function forget(Connection $connection): void
    {
        $name = self::name($connection);
        unset($this->base[$name], $this->items[$name]);
    }

    /** Tiene solo le righe fino al livello indicato. */
    private function keep(string $name, int $level): void
    {
        $items = [];
        foreach ($this->items[$name] ?? [] as $item) {
            if ($item['level'] <= $level) {
                $items[] = $item;
            }
        }
        $this->items[$name] = $items;
    }

    /** @return list<Draft> le righe fino al livello indicato, nell'ordine in cui sono state scritte; le altre si scartano */
    private function take(string $name, int $level): array
    {
        $drafts = [];
        foreach ($this->items[$name] ?? [] as $item) {
            if ($item['level'] <= $level) {
                $drafts[] = $item['draft'];
            }
        }
        unset($this->items[$name]);

        return $drafts;
    }

    private static function name(Connection $connection): string
    {
        return (string) $connection->getName();
    }
}
