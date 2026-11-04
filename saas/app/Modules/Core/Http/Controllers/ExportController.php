<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Export\FullExport;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Esportazione completa dei dati dell'associazione: titolare e amministratore. */
final readonly class ExportController
{
    public function __construct(
        private FullExport $export,
        private CurrentOperator $current,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Esportazione');
    }

    public function download(): BinaryFileResponse
    {
        $operator = $this->current->get() ?? abort(403);
        $file = $this->export->build($operator, $operator->orgName);
        $name = 'dati-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($operator->orgName)).'-'.now()->format('Y-m-d').'.zip';

        return response()->download($file['path'], $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private'])->deleteFileAfterSend();
    }
}
