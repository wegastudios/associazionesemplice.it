<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Services\MemberBookService;
use App\Modules\Soci\Services\MemberBookSnapshots;
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
    public function __construct(
        private MemberBookService $book,
        private MemberBookSnapshots $snapshots,
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $filter = $this->filter($request);
        $year = $this->year($request);
        $result = $this->load($filter, $year);

        return Inertia::render('Soci/LibroSoci', [
            'filter' => $filter,
            'rows' => $result['rows'],
            'year' => $year,
            'source' => $result['source'],
            'years' => $this->years(),
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
