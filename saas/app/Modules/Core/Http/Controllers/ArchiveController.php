<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\ArchiveError;
use App\Modules\Core\Contracts\YearArchives;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/** Archivio degli anni fiscali chiusi: titolare e amministratore. */
final readonly class ArchiveController
{
    public function __construct(private YearArchives $archives) {}

    public function archive(int $year): RedirectResponse
    {
        try {
            $n = $this->archives->archive($year);
        } catch (ArchiveError $e) {
            throw ValidationException::withMessages(['archive' => $e->getMessage()]);
        }

        return back()->with('status', 'Anno fiscale '.$year.' archiviato: '.$n.' ricevute in un archivio controllato.');
    }

    public function restore(int $year): RedirectResponse
    {
        try {
            $n = $this->archives->restore($year);
        } catch (ArchiveError $e) {
            // Archivio che non si apre (codice 1): la pagina offre di ricostruire le ricevute dalle fotografie dei dati.
            throw ValidationException::withMessages($e->getCode() === 1 ? ['archive' => $e->getMessage(), 'archive_recover' => (string) $year] : ['archive' => $e->getMessage()]);
        }

        return back()->with('status', 'File dell\'anno fiscale '.$year.' rimessi: '.$n.' ricevute.');
    }

    public function rebuild(int $year): RedirectResponse
    {
        try {
            $n = $this->archives->rebuild($year);
        } catch (ArchiveError $e) {
            throw ValidationException::withMessages(['archive' => $e->getMessage()]);
        }

        return back()->with('status', 'Anno fiscale '.$year.': '.$n.' ricevute ricostruite dalle fotografie dei dati. L\'operazione è scritta nel registro delle azioni.');
    }
}
