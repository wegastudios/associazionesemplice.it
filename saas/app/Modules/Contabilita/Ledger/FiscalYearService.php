<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Ledger;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\FiscalYearClosed;
use App\Modules\Contabilita\Contracts\FiscalYearReopened;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

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
        private Dispatcher $events,
        private OrgSettings $settings,
    ) {}

    public const START_MONTH = 'contabilita.fiscal_year_start_month';

    /** Mese di inizio dell'anno fiscale; senza scelta è gennaio. */
    public function startMonth(): int
    {
        return $this->chosenStartMonth() ?? 1;
    }

    /** Il mese scelto nella configurazione, o null se non è ancora stato scelto. */
    public function chosenStartMonth(): ?int
    {
        $month = $this->settings->get(self::START_MONTH);

        return is_numeric($month) && (int) $month >= 1 && (int) $month <= 12 ? (int) $month : null;
    }

    /** Ci sono già movimenti (e quindi il mese di inizio non si cambia più)? */
    public function hasMovements(): bool
    {
        return $this->connection()->table('app.transactions')->exists();
    }

    /**
     * Sceglie il mese di inizio dell'anno fiscale. Finché non ci sono movimenti l'anno fiscale di partenza, preparato
     * con l'associazione, si rifà in modo che contenga oggi e cominci in quel mese.
     */
    public function chooseStartMonth(int $month): void
    {
        $this->settings->set(self::START_MONTH, $month);
        if ($this->hasMovements()) {
            return;
        }
        $today = CarbonImmutable::today();
        $start = self::startFor($today, $month);
        $current = $this->connection()->table('app.fiscal_years')->get(['year', 'starts_on', 'status']);
        if ($current->count() === 1 && $current->first()?->status === 'open' && $current->first()->starts_on === $start->toDateString()) {
            return;
        }
        $this->connection()->table('app.fiscal_years')->where('status', 'open')->delete();
        if ($this->connection()->table('app.fiscal_years')->doesntExist()) {
            $this->create($start->year, $start->toDateString(), audit: false);
            $this->audit->record('fiscal_year.realigned', 'app.fiscal_years', null, ['year' => $start->year, 'starts_on' => $start->toDateString()]);
        }
    }

    /** Inizio dell'anno fiscale che contiene la data. */
    private static function startFor(CarbonImmutable $day, int $month): CarbonImmutable
    {
        $start = $day->setDate($day->year, $month, 1);

        return $start->greaterThan($day) ? $start->subYear() : $start;
    }

    /** Anno fiscale che contiene la data; se non c'è e la data non si sovrappone ad altri, si crea quello del mese di inizio scelto (di norma l'anno solare). */
    public function yearFor(string $date): int
    {
        $day = self::date($date);
        $row = $this->connection()->table('app.fiscal_years')
            ->where('starts_on', '<=', $day->toDateString())->where('ends_on', '>=', $day->toDateString())
            ->first(['year']);
        if ($row !== null && is_numeric($row->year)) {
            return (int) $row->year;
        }
        $start = self::startFor($day, $this->startMonth());

        return $this->create($start->year, $start->toDateString());
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
            throw new LedgerError("L'anno fiscale {$year} si sovrappone a un anno fiscale esistente.");
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
        // Gli esercizi si chiudono in ordine: un esercizio precedente ancora aperto (e con movimenti) va chiuso prima.
        $starts = $this->connection()->table('app.fiscal_years')->where('year', $year)->value('starts_on');
        $earlier = is_string($starts) ? $this->connection()->table('app.fiscal_years as f')->where('f.starts_on', '<', $starts)->where('f.status', 'open')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('app.transactions as t')->whereColumn('t.fiscal_year', 'f.year'))
            ->orderBy('f.starts_on')->value('f.year') : null;
        if (is_numeric($earlier)) {
            throw new LedgerError("Prima di chiudere il {$year} chiudi il {$earlier}.");
        }

        $this->connection()->transaction(function () use ($year): void {
            $updated = $this->connection()->table('app.fiscal_years')->where('year', $year)->where('status', 'open')
                ->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->userId()]);
            if ($updated !== 1) {
                throw new LedgerError("L'anno fiscale {$year} non esiste o è già chiuso.");
            }
            // Chi tiene dati dell'anno li fotografa qui, nella stessa transazione: se fallisce, l'esercizio resta aperto.
            $fy = $this->connection()->table('app.fiscal_years')->where('year', $year)->first(['starts_on', 'ends_on']);
            $start = $fy?->starts_on;
            $end = $fy?->ends_on;
            $this->events->dispatch(new FiscalYearClosed($year, is_string($start) ? $start : '', is_string($end) ? $end : ''));
            $this->audit->record('fiscal_year.closed', 'app.fiscal_years', null, ['year' => $year]);
        });
    }

    public function reopen(int $year, string $reason): void
    {
        if (trim($reason) === '') {
            throw new LedgerError("Indica perché riapri l'anno fiscale.");
        }
        // Si riapre solo l'esercizio chiuso più recente: gli altri restano com'erano.
        $starts = $this->connection()->table('app.fiscal_years')->where('year', $year)->where('status', 'closed')->value('starts_on');
        $latest = is_string($starts) ? $this->connection()->table('app.fiscal_years')->where('status', 'closed')->where('starts_on', '>', $starts)->orderByDesc('starts_on')->value('year') : null;
        if (is_numeric($latest)) {
            throw new LedgerError("Si può riaprire solo l'ultimo anno fiscale chiuso: prima riapri il {$latest}.");
        }
        $this->connection()->transaction(function () use ($year, $reason): void {
            $updated = $this->connection()->table('app.fiscal_years')->where('year', $year)->where('status', 'closed')
                ->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
            if ($updated !== 1) {
                throw new LedgerError("L'anno fiscale {$year} non esiste o è già aperto.");
            }
            $this->events->dispatch(new FiscalYearReopened($year));
            $this->audit->record('fiscal_year.reopened', 'app.fiscal_years', null, ['year' => $year, 'reason' => mb_substr(trim($reason), 0, 200)]);
        });
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
