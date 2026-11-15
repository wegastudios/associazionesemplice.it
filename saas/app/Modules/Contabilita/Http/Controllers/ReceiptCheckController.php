<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Receipts\ReceiptFileProblem;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Verifica delle ricevute: per esercizio, quali PDF sono a posto, da fissare, mancanti o alterati. Solo amministratori. */
final readonly class ReceiptCheckController
{
    private const LABELS = ['ok' => 'A posto', 'unfixed' => 'Da fissare', 'missing' => 'Mancante', 'altered' => 'Alterato', 'voided' => 'Annullata', 'archived' => 'In archivio'];

    public function __construct(
        private ReceiptFiles $files,
        private FiscalYearService $years,
    ) {}

    public function show(Request $request): Response
    {
        $years = $this->years->all();
        $year = $request->integer('anno', $years[0]['year'] ?? (int) now()->format('Y'));
        $rows = array_map(fn (array $r): array => $r + [
            'label' => self::LABELS[$r['status']] ?? $r['status'],
            'date' => date('d/m/Y', (int) strtotime($r['issued_on'])),
        ], $this->files->overview($year));

        return Inertia::render('Contabilita/VerificaRicevute', [
            'year' => $year,
            'years' => $years,
            'rows' => $rows,
            'unfixed' => $this->files->unfixedCount(),
        ]);
    }

    public function fix(): RedirectResponse
    {
        $r = $this->files->fixNext(100);

        return back()->with('status', $r['fixed'].' ricevute fissate.'.($r['left'] > 0 ? ' Ne restano '.$r['left'].': premi ancora «Fissa i prossimi PDF».' : ''));
    }

    public function rebuild(string $receipt): RedirectResponse
    {
        try {
            $identical = $this->files->rebuild($receipt);
        } catch (ReceiptFileProblem $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', $identical
            ? 'PDF rifatto: risulta identico a quello emesso. L\'operazione è scritta nel registro delle azioni.'
            : 'PDF rifatto con i dati dell\'emissione. Non è identico al file emesso perché nel frattempo sono cambiati i testi o la grafica del programma. L\'operazione è scritta nel registro delle azioni.');
    }
}
