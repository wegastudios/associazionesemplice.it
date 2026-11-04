<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Import\LedgerExport;
use App\Modules\Contabilita\Import\LedgerImportService;
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
    ) {}

    public function form(): Response
    {
        return $this->page(null, null);
    }

    public function import(Request $request): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx'],
            'default_account_id' => ['nullable', 'uuid'],
            'apply' => ['boolean'],
        ]);
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
            return $this->page(null, $this->import->apply($preview['plan']));
        }

        $labels = ['create' => 'nuovo', 'transfer' => 'giroconto', 'paired' => 'giroconto', 'duplicate' => 'doppione', 'skip' => 'saltato', 'error' => 'errore'];

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
        ], null);
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

    /**
     * @param  array<string, mixed>|null  $preview
     * @param  array<string, mixed>|null  $result
     */
    private function page(?array $preview, ?array $result): Response
    {
        return Inertia::render('Contabilita/Importa', [
            'accounts' => $this->import->accountsList(),
            'preview' => $preview,
            'result' => $result,
        ]);
    }
}
