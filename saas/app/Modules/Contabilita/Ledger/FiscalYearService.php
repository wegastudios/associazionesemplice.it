<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Ledger;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Core\Contracts\OrgContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Esercizi dell'associazione. Di norma coincidono con l'anno solare; un esercizio diverso (per esempio da settembre ad
 * agosto) si crea indicando la data di inizio. Un esercizio chiuso non accetta movimenti né storni (lo impone il
 * database); si può riaprire, e chiusura e riapertura finiscono nel registro delle azioni.
 */
final readonly class FiscalYearService
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private AuditLog $audit,
        private Auth $auth,
    ) {}

    /** Esercizio che contiene la data; se non c'è e la data non si sovrappone ad altri, si crea l'anno solare. */
    public function yearFor(string $date): int
    {
        $day = self::date($date);
        $row = $this->connection()->table('app.fiscal_years')
            ->where('starts_on', '<=', $day->toDateString())->where('ends_on', '>=', $day->toDateString())
            ->first(['year']);
        if ($row !== null && is_numeric($row->year)) {
            return (int) $row->year;
        }

        return $this->create($day->year, $day->startOfYear()->toDateString());
    }

    public function create(int $year, string $startsOn, bool $audit = true): int
    {
        $start = self::date($startsOn);
        $end = $start->addYear()->subDay();
        $overlap = $this->connection()->table('app.fiscal_years')
            ->where('starts_on', '<=', $end->toDateString())->where('ends_on', '>=', $start->toDateString())
            ->orWhere('year', $year)
            ->exists();
        if ($overlap) {
            throw new LedgerError("L'esercizio {$year} si sovrappone a un esercizio esistente.");
        }

        $this->connection()->table('app.fiscal_years')->insert([
            'org_id' => $this->context->require()->id,
            'year' => $year,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
        ]);
        if ($audit) {
            $this->audit->record('fiscal_year.created', 'app.fiscal_years', null, ['year' => $year, 'starts_on' => $start->toDateString()]);
        }

        return $year;
    }

    public function close(int $year): void
    {
        $updated = $this->connection()->table('app.fiscal_years')->where('year', $year)->where('status', 'open')
            ->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->userId()]);
        if ($updated !== 1) {
            throw new LedgerError("L'esercizio {$year} non esiste o è già chiuso.");
        }
        $this->audit->record('fiscal_year.closed', 'app.fiscal_years', null, ['year' => $year]);
    }

    public function reopen(int $year, string $reason): void
    {
        if (trim($reason) === '') {
            throw new LedgerError('Indica perché riapri l\'esercizio.');
        }
        $updated = $this->connection()->table('app.fiscal_years')->where('year', $year)->where('status', 'closed')
            ->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
        if ($updated !== 1) {
            throw new LedgerError("L'esercizio {$year} non esiste o è già aperto.");
        }
        $this->audit->record('fiscal_year.reopened', 'app.fiscal_years', null, ['year' => $year, 'reason' => mb_substr(trim($reason), 0, 200)]);
    }

    /** @return list<array{year: int, starts_on: string, ends_on: string, status: string}> */
    public function all(): array
    {
        return array_values($this->connection()->table('app.fiscal_years')->orderByDesc('year')
            ->get(['year', 'starts_on', 'ends_on', 'status'])
            ->map(static fn (object $r): array => [
                'year' => is_numeric($r->year ?? null) ? (int) $r->year : 0,
                'starts_on' => is_string($r->starts_on ?? null) ? $r->starts_on : '',
                'ends_on' => is_string($r->ends_on ?? null) ? $r->ends_on : '',
                'status' => is_string($r->status ?? null) ? $r->status : '',
            ])->all());
    }

    public static function date(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === null || $day->format('Y-m-d') !== $date) {
            throw new LedgerError('Data non valida.');
        }

        return $day;
    }

    private function userId(): ?string
    {
        $id = $this->auth->guard()->id();

        return is_string($id) ? $id : null;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
