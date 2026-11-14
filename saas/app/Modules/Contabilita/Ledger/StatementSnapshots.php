<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Ledger;

use App\Modules\Contabilita\Contracts\FiscalYearClosed;
use App\Modules\Contabilita\Contracts\FiscalYearReopened;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Fotografia del rendiconto alla chiusura di un esercizio. Un esercizio chiuso si legge SOLO dalla fotografia, mai dai
 * dati vivi: nome e raggruppamento di una voce, o il nome di un conto, com'erano alla chiusura. Alla riapertura la
 * fotografia vigente diventa una versione precedente (si conserva); alla nuova chiusura ne nasce un'altra. Gli esercizi
 * chiusi prima di questa funzione non hanno fotografia: si leggono dai dati attuali.
 *
 * @phpstan-import-type Statement from StatementService
 */
final readonly class StatementSnapshots
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private StatementService $live,
        private Auth $auth,
    ) {}

    /** Alla chiusura: fotografa il rendiconto dell'esercizio (nuova versione). */
    public function take(FiscalYearClosed $event): void
    {
        $statement = $this->live->forYear($event->year);
        $max = $this->table()->where('fiscal_year', $event->year)->max('version');
        $user = $this->auth->guard()->id();
        $this->table()->insert([
            'org_id' => $this->context->require()->id,
            'id' => (string) Str::uuid7(),
            'fiscal_year' => $event->year,
            'version' => 1 + (is_numeric($max) ? (int) $max : 0),
            'starts_on' => $event->startsOn,
            'ends_on' => $event->endsOn,
            'payload' => json_encode($statement, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_by' => is_string($user) ? $user : null,
        ]);
    }

    /** Alla riapertura: la fotografia vigente resta, come versione superata. */
    public function supersede(FiscalYearReopened $event): void
    {
        $this->table()->where('fiscal_year', $event->year)->whereNull('superseded_at')->update(['superseded_at' => now()]);
    }

    /**
     * Rendiconto di un esercizio: dalla fotografia se l'esercizio è chiuso e ce l'ha; altrimenti dal vivo.
     *
     * @return array{statement: Statement, source: 'snapshot'|'live', version: int|null}
     */
    public function forYear(int $year): array
    {
        $live = $this->live->forYear($year); // 404 se l'esercizio non esiste
        if ($live['status'] === 'closed') {
            $snap = $this->table()->where('fiscal_year', $year)->whereNull('superseded_at')->orderByDesc('version')->first(['payload', 'version']);
            if ($snap !== null && is_string($snap->payload)) {
                /** @var Statement $saved */
                $saved = json_decode($snap->payload, true, 512, JSON_THROW_ON_ERROR);

                return ['statement' => $saved, 'source' => 'snapshot', 'version' => is_numeric($snap->version) ? (int) $snap->version : null];
            }
        }

        return ['statement' => $live, 'source' => 'live', 'version' => null];
    }

    /**
     * Le versioni della fotografia di un esercizio, dalla più recente.
     *
     * @return list<array{version: int, created_at: string, superseded: bool}>
     */
    public function versions(int $year): array
    {
        $out = [];
        foreach ($this->table()->where('fiscal_year', $year)->orderByDesc('version')->get(['version', 'created_at', 'superseded_at']) as $r) {
            $out[] = [
                'version' => is_numeric($r->version ?? null) ? (int) $r->version : 0,
                'created_at' => is_string($r->created_at ?? null) ? $r->created_at : '',
                'superseded' => ($r->superseded_at ?? null) !== null,
            ];
        }

        return $out;
    }

    /**
     * Tutte le fotografie di tutti gli esercizi, per l'esportazione completa.
     *
     * @return list<array{fiscal_year: int, version: int, created_at: string, superseded: bool, statement: array<string, mixed>}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->table()->orderBy('fiscal_year')->orderBy('version')->get(['fiscal_year', 'version', 'created_at', 'superseded_at', 'payload']) as $r) {
            $payload = is_string($r->payload ?? null) ? json_decode($r->payload, true) : null;
            $out[] = [
                'fiscal_year' => is_numeric($r->fiscal_year ?? null) ? (int) $r->fiscal_year : 0,
                'version' => is_numeric($r->version ?? null) ? (int) $r->version : 0,
                'created_at' => is_string($r->created_at ?? null) ? $r->created_at : '',
                'superseded' => ($r->superseded_at ?? null) !== null,
                'statement' => is_array($payload) ? $payload : [],
            ];
        }

        return $out;
    }

    private function table(): Builder
    {
        return $this->connection()->table('app.statement_snapshots');
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
