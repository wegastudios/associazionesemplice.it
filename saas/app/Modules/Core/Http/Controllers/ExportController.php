<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Contracts\Operator;
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
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Copie di sicurezza dei dati dell'associazione: titolare e amministratore. La copia si chiede qui e si prepara in coda;
 * la pagina ne mostra lo stato e, quando è pronta, il collegamento firmato per scaricarla. Chiedere la copia vuole la
 * password confermata da poco: se manca, la richiesta resta in sessione e parte da sola tornando qui dopo la conferma.
 */
final readonly class ExportController
{
    private const PENDING = 'export.pending';

    public function __construct(
        private FullExports $exports,
        private CurrentOperator $current,
        private DatabaseManager $db,
        private OrgContext $context,
        private YearArchives $archives,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $operator = $this->current->get() ?? abort(403);
        // Ritorno dalla conferma della password: la copia chiesta prima parte adesso.
        if ($request->session()->has(self::PENDING) && self::passwordConfirmed($request)) {
            $pending = $request->session()->pull(self::PENDING);
            $pending = is_array($pending) ? $pending : [];

            return $this->begin($operator, ($pending['certificates'] ?? false) === true, is_int($pending['year'] ?? null) ? $pending['year'] : null);
        }
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
        if (! self::passwordConfirmed($request)) {
            $request->session()->put(self::PENDING, ['certificates' => $withCertificates, 'year' => $year]);
            $request->session()->put('url.intended', route('export'));

            return redirect()->route('password.confirm');
        }

        return $this->begin($operator, $withCertificates, $year);
    }

    public function file(string $export): HttpResponse
    {
        $operator = $this->current->get() ?? abort(403);
        try {
            return $this->exports->download($export, $operator);
        } catch (ExportUnavailable $e) {
            return redirect()->route('export')->withErrors(['export' => $e->getMessage()]);
        }
    }

    private function begin(Operator $operator, bool $withCertificates, ?int $year): RedirectResponse
    {
        try {
            $this->exports->request($operator, $withCertificates && $operator->can(Ability::ViewHealth), $year);
        } catch (ExportTooLarge|ExportUnavailable $e) {
            return redirect()->route('export')->withErrors(['export' => $e->getMessage()]);
        }

        return redirect()->route('export');
    }

    /** Come il middleware «password.confirm»: password confermata entro auth.password_timeout. */
    private static function passwordConfirmed(Request $request): bool
    {
        $at = $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = config('auth.password_timeout', 900);

        return time() - (is_numeric($at) ? (int) $at : 0) <= (is_numeric($timeout) ? (int) $timeout : 900);
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
