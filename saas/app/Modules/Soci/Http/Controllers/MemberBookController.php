<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Contracts\LifecyclePolicy;
use App\Modules\Soci\Services\MemberBookService;
use App\Modules\Soci\Services\MemberBookSnapshots;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Libro soci: consultazione nel gestionale, stampa in PDF ed export CSV (con il registro). Con `?anno=` è il libro di
 * un esercizio: quello chiuso si legge dalla fotografia della chiusura.
 *
 * @phpstan-import-type Row from MemberBookService
 */
final readonly class MemberBookController
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private MemberBookService $book,
        private MemberBookSnapshots $snapshots,
        private DatabaseManager $db,
        private OrgContext $context,
        private LifecyclePolicy $policy,
        private RateLimiter $limiter,
    ) {}

    public function index(Request $request): Response
    {
        $filter = $this->filter($request);
        $year = $this->year($request);
        $result = $this->load($filter, $year);
        // A video il libro va a pagine da 100 righe (stampa e CSV restano interi); il totale è quello vero.
        $total = count($result['rows']);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) $request->integer('pagina', 1)));
        $slice = array_slice($result['rows'], ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        // Le colonne complete (codice fiscale, nascita, residenza, quote) valgono come l'esportazione: permesso, piano, limite di frequenza e registro.
        $full = $this->mayReadFull($request);
        if ($full) {
            $this->book->logView(count($slice), $page);
        }

        return Inertia::render('Soci/LibroSoci', [
            'filter' => $filter,
            'rows' => $full ? $slice : array_map(self::basic(...), $slice),
            'full' => $full,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'year' => $year,
            'source' => $result['source'],
            'years' => $this->years(),
            'versions' => $year === null ? [] : array_map(static fn (array $v): array => [
                'version' => $v['version'], 'date' => date('d/m/Y H:i', (int) strtotime($v['created_at'])), 'superseded' => $v['superseded'],
            ], $this->snapshots->versions($year)),
            'canExport' => $request->user()?->can('people.export') ?? false,
        ]);
    }

    public function pdf(Request $request): HttpResponse
    {
        $filter = $this->filter($request);
        $rows = $this->load($filter, $this->year($request))['rows'];
        $name = $this->db->connection($this->context->require()->connection)->table('app.orgs')->value('name');
        $this->book->logExport('pdf', count($rows));

        return new HttpResponse($this->book->pdf($rows, is_string($name) ? $name : '', $filter), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="libro-soci-'.now()->format('Y-m-d').'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function csv(Request $request): HttpResponse
    {
        $rows = $this->load($this->filter($request), $this->year($request))['rows'];
        $this->book->logExport('csv', count($rows));

        return new HttpResponse($this->book->csv($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="libro-soci-'.now()->format('Y-m-d').'.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function mayReadFull(Request $request): bool
    {
        $user = $request->user();
        if ($user === null || ! $user->can('people.export') || ! $this->policy->exportsAllowed($this->context->require()->id)) {
            return false;
        }
        $id = $user->getAuthIdentifier();
        $key = 'member-book-view:'.(is_string($id) || is_int($id) ? $id : (string) $request->ip());
        if ($this->limiter->tooManyAttempts($key, 10)) {
            abort(429);
        }
        $this->limiter->hit($key, 60);

        return true;
    }

    /**
     * Senza il permesso di esportare: solo numero, nome, tessera e date.
     *
     * @param  Row  $r
     * @return array<string, bool|int|string>
     */
    private static function basic(array $r): array
    {
        return [
            'n' => $r['n'], 'matricola' => $r['matricola'], 'id' => $r['id'], 'name' => $r['name'], 'card' => $r['card'], 'level' => $r['level'],
            'applied_on' => $r['applied_on'], 'approved_on' => $r['approved_on'], 'joined_on' => $r['joined_on'], 'left_on' => $r['left_on'], 'in_force' => $r['in_force'],
        ];
    }

    /** @return array{rows: list<Row>, source: string} */
    private function load(string $filter, ?int $year): array
    {
        if ($year === null) {
            return ['rows' => $this->book->rows($filter), 'source' => 'live'];
        }

        return $this->snapshots->forYear($year, $filter);
    }

    private function filter(Request $request): string
    {
        $f = $request->string('stato')->toString();

        return in_array($f, [MemberBookService::IN_FORCE, MemberBookService::LEFT], true) ? $f : '';
    }

    private function year(Request $request): ?int
    {
        $y = $request->query('anno');

        return is_string($y) && ctype_digit($y) ? (int) $y : null;
    }

    /** @return list<array{year: int, status: string}> */
    private function years(): array
    {
        return array_values($this->db->connection($this->context->require()->connection)->table('app.fiscal_years')->orderByDesc('year')->get(['year', 'status'])
            ->map(static fn (object $r): array => ['year' => is_numeric($r->year ?? null) ? (int) $r->year : 0, 'status' => is_string($r->status ?? null) ? $r->status : ''])->all());
    }
}
