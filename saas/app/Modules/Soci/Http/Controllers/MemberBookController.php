<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Services\MemberBookService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Libro soci: consultazione nel gestionale, stampa in PDF ed export CSV (con il registro). */
final readonly class MemberBookController
{
    public function __construct(
        private MemberBookService $book,
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $filter = $this->filter($request);

        return Inertia::render('Soci/LibroSoci', [
            'filter' => $filter,
            'rows' => $this->book->rows($filter),
            'canExport' => $request->user()?->can('people.export') ?? false,
        ]);
    }

    public function pdf(Request $request): HttpResponse
    {
        $filter = $this->filter($request);
        $rows = $this->book->rows($filter);
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
        $rows = $this->book->rows($this->filter($request));
        $this->book->logExport('csv', count($rows));

        return new HttpResponse($this->book->csv($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="libro-soci-'.now()->format('Y-m-d').'.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function filter(Request $request): string
    {
        $f = $request->string('stato')->toString();

        return in_array($f, [MemberBookService::IN_FORCE, MemberBookService::LEFT], true) ? $f : '';
    }
}
