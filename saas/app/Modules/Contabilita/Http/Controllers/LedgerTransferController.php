<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Import\LedgerExport;
use App\Modules\Contabilita\Import\LedgerImportService;
use App\Modules\Contabilita\Jobs\RunLedgerImport;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Import della prima nota da Excel o CSV ed export in CSV. */
final readonly class LedgerTransferController
{
    public function __construct(
        private LedgerImportService $import,
        private LedgerExport $export,
        private AuditLog $audit,
        private OrgContext $context,
    ) {}

    public function form(): Response
    {
        return $this->page(null);
    }

    /** Anteprima (apply = false), oppure import dello stesso file in coda (H11): la pagina ne mostra l'avanzamento. */
    public function import(Request $request): Response|RedirectResponse
    {
        if (DemoScale::uploadsOff()) {
            throw ValidationException::withMessages(['file' => DemoScale::UPLOADS_OFF]);
        }
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx'],
            'default_account_id' => ['nullable', 'uuid'],
            'apply' => ['boolean'],
        ], ['file.max' => 'Il file non può superare 5 MB.']);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Scegli il file.']);
        }
        $default = $request->string('default_account_id')->toString();
        $preview = $this->import->preview((string) $file->getRealPath(), $file->getClientOriginalName(), $default !== '' ? $default : null);
        if (isset($preview['error'])) {
            throw ValidationException::withMessages(['file' => $preview['error']]);
        }
        if ($request->boolean('apply')) {
            $user = $request->user();
            $userId = $user?->getAuthIdentifier();
            $id = $this->import->queue($preview['plan'], $user?->can('accounting.income') ?? false, $user?->can('accounting.expense') ?? false, is_string($userId) ? $userId : null, $file->getClientOriginalName());
            if ($id === null) {
                throw ValidationException::withMessages(['file' => 'C\'è già un\'importazione della prima nota in corso: aspetta che finisca.']);
            }
            RunLedgerImport::dispatch($this->context->require()->id, $id);

            return redirect()->route('ledger.import');
        }

        $labels = ['create' => 'Nuovo', 'transfer' => 'Giroconto', 'paired' => 'Giroconto', 'duplicate' => 'Già presente', 'skip' => 'Saltato', 'error' => 'Errore'];

        return $this->page([
            'summary' => $preview['summary'],
            'rows' => array_map(static fn (array $p): array => [
                'where' => ($p['row']['sheet'] !== '' ? $p['row']['sheet'].' ' : '').$p['row']['line'],
                'date' => $p['row']['date'] === null ? '' : date('d/m/Y', (int) strtotime($p['row']['date'])),
                'description' => $p['row']['desc'] !== '' ? $p['row']['desc'] : $p['row']['category'],
                'amount' => ($p['row']['type'] === 'expense' || $p['row']['type'] === 'transfer_out' ? '-' : '').number_format($p['row']['cents'] / 100, 2, ',', '.'),
                'action' => $p['action'],
                'label' => $labels[$p['action']] ?? $p['action'],
                'message' => trim($p['message'].($p['warnings'] !== [] ? ' '.implode('; ', $p['warnings']) : '')),
            ], $preview['plan']),
        ]);
    }

    /** Annulla un import ancora in coda: nessun movimento registrato. Quando è partito si porta a termine. */
    public function cancel(string $import): RedirectResponse
    {
        if (! $this->import->cancel($import)) {
            throw ValidationException::withMessages(['file' => 'L\'importazione è già partita: non si può più annullare, si porta a termine.']);
        }

        return redirect()->route('ledger.import');
    }

    /**
     * Riprende un import fermo dal punto in cui si era fermato: i movimenti già registrati non si ripetono. Entrate e
     * spese restano quelle permesse a chi l'ha avviato.
     */
    public function resume(string $import): RedirectResponse
    {
        if ($this->import->resume($import)) {
            RunLedgerImport::dispatch($this->context->require()->id, $import);
        }

        return redirect()->route('ledger.import');
    }

    public function export(Request $request): HttpResponse
    {
        $year = $request->integer('anno') ?: null;
        $file = $this->export->csv($year);
        $this->audit->record('export.done', 'app.transactions', null, ['rows' => $file['rows'], 'format' => 'csv']);

        return new HttpResponse($file['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="prima-nota'.($year !== null ? '-'.$year : '').'.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @param  array<string, mixed>|null  $preview */
    private function page(?array $preview): Response
    {
        return Inertia::render('Contabilita/Importa', [
            'accounts' => fn (): array => $this->import->accountsList(),
            'preview' => $preview,
            'job' => $this->import->current(),
        ]);
    }
}
