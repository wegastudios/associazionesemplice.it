<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Http\Controllers;

use App\Modules\Piattaforma\Catalogo\CatalogPublisher;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Models\PlatformStaff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;
use JsonException;

/**
 * Editor del catalogo: bozza in JSON (validata a ogni salvataggio) e «Pubblica». Le letture le vede anche il «solo
 * lettura»; salvare, scartare e pubblicare solo l'amministratore (middleware `platform.staff:write` nelle rotte).
 */
final readonly class AdminCatalogController
{
    public function __construct(private CatalogPublisher $publisher, private CatalogDraftEditor $editor, private Catalog $catalog) {}

    public function show(): View
    {
        $published = $this->catalog->published();

        return view('admin.catalog', [
            'rev' => $published->rev,
            'publishedAt' => $published->document['published_at'] ?? null,
            'plans' => $published->planVersions(),
            'hasDraft' => $this->publisher->hasDraft(),
            'changes' => $this->editor->changes(),
            'json' => json_encode($published->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function draft(): View
    {
        return view('admin.catalog-draft', [
            'json' => json_encode($this->publisher->draft(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'rev' => $this->catalog->published()->rev,
        ]);
    }

    public function save(Request $request): RedirectResponse|View
    {
        $request->validate(['document' => ['required', 'string', 'max:500000']]);
        $text = $request->string('document')->toString();

        try {
            $input = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($input) || array_is_list($input)) {
                throw new InvalidArgumentException('Il catalogo deve essere un oggetto JSON.');
            }
            $this->publisher->saveDraft($input, $this->staffId());
        } catch (JsonException) {
            return $this->invalid($text, 'JSON non valido.');
        } catch (InvalidArgumentException $e) {
            return $this->invalid($text, $e->getMessage());
        }

        return redirect(AdminLoginController::url('catalogo'))->with('status', 'Bozza salvata. Per renderla visibile al portale premi «Pubblica».');
    }

    public function discard(): RedirectResponse
    {
        $this->publisher->discardDraft($this->staffId());

        return redirect(AdminLoginController::url('catalogo'))->with('status', 'Bozza scartata.');
    }

    public function publish(Request $request): RedirectResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        try {
            $rev = $this->publisher->publish($this->staffId(), $request->string('note')->toString());
        } catch (InvalidArgumentException $e) {
            return redirect(AdminLoginController::url('catalogo'))->withErrors(['publish' => $e->getMessage()]);
        }

        return redirect(AdminLoginController::url('catalogo'))->with('status', "Pubblicata la revisione {$rev}. Il portale la legge entro pochi minuti.");
    }

    private function invalid(string $text, string $message): View
    {
        return view('admin.catalog-draft', ['json' => $text, 'rev' => $this->catalog->published()->rev, 'problem' => $message]);
    }

    private function staffId(): string
    {
        $staff = Auth::guard('platform')->user();

        return $staff instanceof PlatformStaff ? $staff->id : '';
    }
}
