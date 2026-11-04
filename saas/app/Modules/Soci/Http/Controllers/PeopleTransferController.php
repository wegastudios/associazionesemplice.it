<?php

declare(strict_types=1);

namespace App\Modules\Soci\Http\Controllers;

use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Import\PeopleSheet;
use App\Modules\Soci\Services\PeopleExportService;
use App\Modules\Soci\Services\PeopleImportService;
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
    ) {}

    public function form(): Response
    {
        return $this->page(null, null);
    }

    /** Anteprima (apply = false) o import vero dello stesso file. */
    public function import(Request $request): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx'],
            'default_type' => ['required', Rule::in(array_map(static fn (MemberType $t): string => $t->value, self::DEFAULT_TYPES))],
            'mark_current' => ['boolean'],
            'apply' => ['boolean'],
        ]);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Scegli il file.']);
        }

        $preview = $this->import->preview((string) $file->getRealPath(), $file->getClientOriginalName(), MemberType::from($request->string('default_type')->toString()));
        if (isset($preview['error'])) {
            throw ValidationException::withMessages(['file' => $preview['error']]);
        }

        $summary = [
            'create' => count(array_filter($preview['plan'], static fn (array $p): bool => $p['action'] === 'create')),
            'update' => count(array_filter($preview['plan'], static fn (array $p): bool => $p['action'] === 'update')),
            'error' => count(array_filter($preview['plan'], static fn (array $p): bool => $p['action'] === 'error')),
            'sheets' => $preview['sheets'],
            'rows' => array_map(static fn (array $p): array => [
                'where' => ($p['row']['sheet'] !== '' ? $p['row']['sheet'].' ' : '').$p['row']['line'],
                'name' => trim($p['row']['last'].' '.$p['row']['first']),
                'type' => $p['type'] !== null ? MemberType::from($p['type'])->label() : '',
                'action' => $p['action'],
                'message' => $p['message'],
            ], $preview['plan']),
        ];

        if (! $request->boolean('apply')) {
            return $this->page($summary, null);
        }

        return $this->page(null, $this->import->apply($preview['plan'], $request->boolean('mark_current')));
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

    /**
     * @param  array<string, mixed>|null  $preview
     * @param  array<string, mixed>|null  $result
     */
    private function page(?array $preview, ?array $result): Response
    {
        return Inertia::render('Soci/Importa', [
            'types' => array_map(static fn (MemberType $t): array => ['value' => $t->value, 'label' => $t->label()], self::DEFAULT_TYPES),
            'columns' => ['Numero tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Cellulare', 'Codice fiscale', 'Data di nascita', 'Luogo di nascita', 'Indirizzo, CAP, Comune, Provincia', 'Data di ingresso', 'Ospite di', 'Tutore', 'Note', '2025, 2026… (una crocetta per ogni anno di iscrizione)'],
            'preview' => $preview,
            'result' => $result,
        ]);
    }
}
