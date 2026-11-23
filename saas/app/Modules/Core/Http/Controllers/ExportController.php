<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\YearArchives;
use App\Modules\Core\Export\ExportTooLarge;
use App\Modules\Core\Export\ExportUnavailable;
use App\Modules\Core\Export\FullExports;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Copie di sicurezza dei dati dell'associazione: titolare e amministratore. La copia si chiede qui e si prepara in coda;
 * la pagina ne mostra lo stato e, quando è pronta, il collegamento firmato per scaricarla.
 */
final readonly class ExportController
{
    public function __construct(
        private FullExports $exports,
        private CurrentOperator $current,
        private DatabaseManager $db,
        private OrgContext $context,
        private YearArchives $archives,
    ) {}

    public function show(): Response
    {
        $operator = $this->current->get() ?? abort(403);
        $years = $this->db->connection($this->context->require()->connection)->table('app.fiscal_years')->orderByDesc('year')->pluck('year')->map(static fn (mixed $y): int => is_numeric($y) ? (int) $y : 0)->all();

        return Inertia::render('Esportazione', [
            'csrf' => csrf_token(), 'canHealth' => $operator->can(Ability::ViewHealth), 'years' => $years, 'archives' => $this->archives->years(),
            'backup' => fn (): ?array => $this->latest($operator->userId),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $operator = $this->current->get() ?? abort(403);
        // I certificati medici in chiaro solo se la casella è spuntata e chi scarica può vedere i dati sanitari.
        $withCertificates = $request->boolean('certificati') && $operator->can(Ability::ViewHealth);
        $year = $request->filled('anno') ? $request->integer('anno') : null;
        try {
            $this->exports->request($operator, $withCertificates, $year);
        } catch (ExportTooLarge|ExportUnavailable $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }

        return redirect()->route('export');
    }

    public function file(string $export): HttpResponse
    {
        $operator = $this->current->get() ?? abort(403);
        try {
            $file = $this->exports->download($export, $operator);
        } catch (ExportUnavailable $e) {
            return redirect()->route('export')->withErrors(['export' => $e->getMessage()]);
        }
        $headers = ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private'];
        // Con il web server configurato (config saas.export) il file lo consegna lui: PHP risponde subito.
        $header = config()->string('saas.export.web_server_header', '');
        if ($header !== '') {
            // Il nome è già solo lettere minuscole, cifre e trattini (FullExports::download).
            $headers[$header] = rtrim(config()->string('saas.export.web_server_prefix', ''), '/').substr($file['path'], strlen(FullExports::directory()));
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file['name']);

            return response('', 200, $headers);
        }

        return response()->download($file['path'], $file['name'], $headers);
    }

    /** @return array<string, mixed>|null */
    private function latest(string $userId): ?array
    {
        $export = $this->exports->latest($userId);
        if ($export === null) {
            return null;
        }
        $export['url'] = $export['available']
            ? URL::temporarySignedRoute('export.file', Carbon::parse((string) $export['expires_at']), ['export' => $export['id']], absolute: false)
            : null;

        return $export;
    }
}
