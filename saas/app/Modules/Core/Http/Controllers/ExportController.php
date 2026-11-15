<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Export\FullExport;
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
    ) {}

    public function show(): Response
    {
        $operator = $this->current->get() ?? abort(403);

        return Inertia::render('Esportazione', ['canHealth' => $operator->can(Ability::ViewHealth)]);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $operator = $this->current->get() ?? abort(403);
        // I certificati medici in chiaro solo se la casella è spuntata e chi scarica può vedere i dati sanitari.
        $withCertificates = $request->boolean('certificati') && $operator->can(Ability::ViewHealth);
        $file = $this->export->build($operator, $operator->orgName, $withCertificates);
        $name = 'copia-di-sicurezza-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($operator->orgName)).'-'.now()->format('Y-m-d').'.zip';

        return response()->download($file['path'], $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private'])->deleteFileAfterSend();
    }
}
