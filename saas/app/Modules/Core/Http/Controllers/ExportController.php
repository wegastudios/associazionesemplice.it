<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\YearArchives;
use App\Modules\Core\Export\ExportTooLarge;
use App\Modules\Core\Export\FullExport;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Copie di sicurezza dei dati dell'associazione: titolare e amministratore. */
final readonly class ExportController
{
    public function __construct(
        private FullExport $export,
        private CurrentOperator $current,
        private DatabaseManager $db,
        private OrgContext $context,
        private YearArchives $archives,
    ) {}

    public function show(): Response
    {
        $operator = $this->current->get() ?? abort(403);
        $years = $this->db->connection($this->context->require()->connection)->table('app.fiscal_years')->orderByDesc('year')->pluck('year')->map(static fn (mixed $y): int => is_numeric($y) ? (int) $y : 0)->all();

        return Inertia::render('Esportazione', ['canHealth' => $operator->can(Ability::ViewHealth), 'years' => $years, 'archives' => $this->archives->years()]);
    }

    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        $operator = $this->current->get() ?? abort(403);
        // I certificati medici in chiaro solo se la casella è spuntata e chi scarica può vedere i dati sanitari.
        $withCertificates = $request->boolean('certificati') && $operator->can(Ability::ViewHealth);
        $year = $request->filled('anno') ? $request->integer('anno') : null;
        try {
            $file = $this->export->build($operator, $operator->orgName, $withCertificates, $year);
        } catch (ExportTooLarge $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }
        $name = 'copia-di-sicurezza-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($operator->orgName)).($year !== null ? '-'.$year : '').'-'.now()->format('Y-m-d').'.zip';

        return response()->download($file['path'], $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private'])->deleteFileAfterSend();
    }
}
