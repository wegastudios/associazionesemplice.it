<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Contabilita\Contracts\FiscalYearClosed;
use App\Modules\Contabilita\Contracts\FiscalYearReopened;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Fotografia del libro soci alla chiusura di un esercizio. Un esercizio chiuso si legge SOLO dalla fotografia, mai
 * dai dati vivi: chi la persona è oggi (modificata, cancellata, anonimizzata) non cambia il libro di quell'anno.
 * Alla riapertura la fotografia vigente diventa una versione precedente (si conserva); alla nuova chiusura ne nasce
 * un'altra. Gli esercizi chiusi prima di questa funzione non hanno fotografia: si leggono dai dati attuali.
 *
 * @phpstan-import-type Row from MemberBookService
 */
final readonly class MemberBookSnapshots
{
    /**
     * I campi che entrano nella fotografia: UN solo elenco. È una proposta basata sulla prassi (nessuna norma li
     * elenca) in attesa del parere del commercialista: niente email, telefono, quote. `card` è la matricola.
     */
    public const FIELDS = ['n', 'name', 'tax_code', 'birth', 'residence', 'level', 'card', 'approved_on', 'left_on', 'left_reason'];

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private MemberBookService $book,
        private Auth $auth,
    ) {}

    /** Alla chiusura: fotografa il libro dell'esercizio (nuova versione). */
    public function take(FiscalYearClosed $event): void
    {
        $rows = array_map(
            static fn (array $r): array => array_intersect_key($r, array_flip(self::FIELDS)),
            $this->book->rows('', [$event->startsOn, $event->endsOn]),
        );
        $max = $this->table()->where('fiscal_year', $event->year)->max('version');
        $version = 1 + (is_numeric($max) ? (int) $max : 0);
        $user = $this->auth->guard()->id();
        $this->table()->insert([
            'org_id' => $this->context->require()->id,
            'id' => (string) Str::uuid7(),
            'fiscal_year' => $event->year,
            'version' => $version,
            'starts_on' => $event->startsOn,
            'ends_on' => $event->endsOn,
            'rows' => json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_by' => is_string($user) ? $user : null,
        ]);
    }

    /** Alla riapertura: la fotografia vigente resta, come versione superata. */
    public function supersede(FiscalYearReopened $event): void
    {
        $this->table()->where('fiscal_year', $event->year)->whereNull('superseded_at')->update(['superseded_at' => now()]);
    }

    /**
     * Libro di un esercizio: dalla fotografia se l'esercizio è chiuso e ce l'ha; altrimenti dal vivo.
     *
     * @return array{rows: list<Row>, source: 'snapshot'|'live'}
     */
    public function forYear(int $year, string $filter = ''): array
    {
        $snap = $this->table()->where('fiscal_year', $year)->whereNull('superseded_at')->orderByDesc('version')->first(['rows']);
        if ($snap !== null && is_string($snap->rows)) {
            /** @var list<array<string, mixed>> $saved */
            $saved = json_decode($snap->rows, true, 512, JSON_THROW_ON_ERROR);
            $rows = [];
            foreach ($saved as $r) {
                $row = self::fill($r);
                if (($filter === MemberBookService::IN_FORCE && ! $row['in_force']) || ($filter === MemberBookService::LEFT && $row['in_force'])) {
                    continue;
                }
                $rows[] = $row;
            }

            return ['rows' => $rows, 'source' => 'snapshot'];
        }

        $fy = $this->connection()->table('app.fiscal_years')->where('year', $year)->first(['starts_on', 'ends_on']);
        $period = $fy !== null && is_string($fy->starts_on ?? null) && is_string($fy->ends_on ?? null) ? [$fy->starts_on, $fy->ends_on] : null;

        return ['rows' => $period === null ? [] : $this->book->rows($filter, $period), 'source' => 'live'];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return Row
     */
    private static function fill(array $r): array
    {
        $s = static fn (string $k): string => is_scalar($r[$k] ?? null) ? (string) $r[$k] : '';

        return [
            'n' => is_numeric($r['n'] ?? null) ? (int) $r['n'] : 0, 'id' => '', 'name' => $s('name'), 'tax_code' => $s('tax_code'),
            'birth' => $s('birth'), 'residence' => $s('residence'), 'email' => '', 'phone' => '', 'card' => $s('card'), 'level' => $s('level'),
            'applied_on' => $s('approved_on'), 'approved_on' => $s('approved_on'), 'joined_on' => $s('approved_on'),
            'left_on' => $s('left_on'), 'left_reason' => $s('left_reason'), 'fee' => '', 'fee_year' => '', 'guardian' => '',
            'in_force' => $s('left_on') === '',
        ];
    }

    private function table(): Builder
    {
        return $this->connection()->table('app.member_book_snapshots');
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
