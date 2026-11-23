<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Import\PeopleSheet;
use App\Modules\Soci\Jobs\RunPeopleImport;
use App\Modules\Soci\Services\PeopleExportService;
use App\Modules\Soci\Services\PeopleImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Import ed export dell'anagrafica. */
final readonly class PeopleTransferController
{
    /** Tipi che si possono scegliere come predefiniti (gli ospiti vogliono la colonna «Ospite di»). */
    private const DEFAULT_TYPES = [MemberType::Ordinary, MemberType::Volunteer, MemberType::Founder, MemberType::Guardian];

    public function __construct(
        private PeopleImportService $import,
        private PeopleExportService $export,
        private OrgContext $context,
    ) {}

    public function form(): Response
    {
        return $this->page(null);
    }

    /** Anteprima (apply = false), oppure import dello stesso file in coda (H10): la pagina ne mostra l'avanzamento. */
    public function import(Request $request): Response|RedirectResponse
    {
        if (DemoScale::uploadsOff()) {
            throw ValidationException::withMessages(['file' => DemoScale::UPLOADS_OFF]);
        }
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx'],
            'default_type' => ['required', Rule::in(array_map(static fn (MemberType $t): string => $t->value, self::DEFAULT_TYPES))],
            'mark_current' => ['boolean'],
            'confirm_years' => ['boolean'],
            'apply' => ['boolean'],
        ], ['file.max' => 'Il file non può superare 5 MB.']);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Scegli il file.']);
        }

        $preview = $this->import->preview((string) $file->getRealPath(), $file->getClientOriginalName(), MemberType::from($request->string('default_type')->toString()));
        if (isset($preview['error'])) {
            throw ValidationException::withMessages(['file' => $preview['error']]);
        }

        $years = $this->import->yearsNote($preview['plan']);
        if (! $request->boolean('apply')) {
            return $this->page([
                'create' => count(array_filter($preview['plan'], static fn (array $p): bool => $p['action'] === 'create')),
                'update' => count(array_filter($preview['plan'], static fn (array $p): bool => $p['action'] === 'update')),
                'error' => count(array_filter($preview['plan'], static fn (array $p): bool => $p['action'] === 'error')),
                'sheets' => $preview['sheets'],
                'rows' => array_map(static fn (array $p): array => [
                    'where' => ($p['row']['sheet'] !== '' ? $p['row']['sheet'].' ' : '').$p['row']['line'],
                    'name' => trim($p['row']['last'].' '.$p['row']['first']),
                    'type' => $p['type'] !== null ? ($p['row']['entity'] ? 'Ente · ' : '').MemberType::from($p['type'])->label() : '',
                    'action' => $p['action'],
                    'message' => $p['message'],
                ], $preview['plan']),
                'years' => $years['count'] > 0 ? $years : null,
            ]);
        }

        if ($years['count'] > 0 && ! $request->boolean('confirm_years')) {
            throw ValidationException::withMessages(['file' => "Conferma cosa succede alle tessere con un anno diverso dall'anno fiscale, oppure togli quelle righe."]);
        }
        $id = $this->import->queue($preview['plan'], $request->boolean('mark_current'), self::userId($request));
        if ($id === null) {
            throw ValidationException::withMessages(['file' => 'C\'è già un\'importazione di soci in corso: aspetta che finisca.']);
        }
        RunPeopleImport::dispatch($this->context->require()->id, $id);

        return redirect()->route('people.import');
    }

    /** Annulla un import ancora in coda: nessuna riga importata. Quando è partito si porta a termine. */
    public function cancel(string $import): RedirectResponse
    {
        if (! $this->import->cancel($import)) {
            throw ValidationException::withMessages(['file' => 'L\'importazione è già partita: non si può più annullare, si porta a termine.']);
        }

        return redirect()->route('people.import');
    }

    /** Riprende un import fermo dal punto in cui si era fermato: le righe già importate non si ripetono. */
    public function resume(string $import): RedirectResponse
    {
        if ($this->import->resume($import)) {
            RunPeopleImport::dispatch($this->context->require()->id, $import);
        }

        return redirect()->route('people.import');
    }

    public function template(): HttpResponse
    {
        return new HttpResponse("\xEF\xBB\xBF".PeopleSheet::TEMPLATE, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modello-soci.csv"',
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $type = MemberType::tryFrom($request->string('tipo')->toString());
        $file = $this->export->csv($request->string('q')->toString(), $type);

        return new HttpResponse($file['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="soci-'.now()->format('Y-m-d').'.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    /** @param  array<string, mixed>|null  $preview */
    private function page(?array $preview): Response
    {
        return Inertia::render('Soci/Importa', [
            'types' => array_map(static fn (MemberType $t): array => ['value' => $t->value, 'label' => $t->label()], self::DEFAULT_TYPES),
            'columns' => ['Numero tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Cellulare', 'Codice fiscale', 'Data di nascita', 'Luogo di nascita', 'Indirizzo, CAP, Comune, Provincia', 'Data di ingresso', 'Ospite di', 'Tutore', 'Tipo soggetto (persona o ente)', 'Denominazione (enti)', 'Stessa sezione RUNTS (enti)', 'Note', '2025, 2026… (una crocetta per ogni anno con la tessera)'],
            'preview' => $preview,
            'job' => $this->import->current(),
        ]);
    }
}
