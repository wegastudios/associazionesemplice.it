<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Ledger\StatementService;
use App\Modules\Core\Contracts\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Esercizi (chiusura e riapertura) e rendiconto per cassa. */
final readonly class YearsController
{
    public function __construct(
        private FiscalYearService $years,
        private StatementService $statements,
    ) {}

    public function statement(Request $request, ?int $year = null): Response
    {
        $years = $this->years->all();
        $year ??= $years[0]['year'] ?? (int) now()->format('Y');

        try {
            $s = $this->statements->forYear($year);
        } catch (LedgerError) {
            abort(404);
        }
        $money = static fn (array $lines): array => array_map(
            static fn (array $l): array => $l + ['amount' => Money::format(is_int($l['cents'] ?? null) ? $l['cents'] : 0)],
            array_filter($lines, is_array(...)),
        );

        return Inertia::render('Contabilita/Rendiconto', [
            'years' => $years,
            'statement' => [
                'year' => $s['year'],
                'starts_on' => date('d/m/Y', (int) strtotime($s['starts_on'])),
                'ends_on' => date('d/m/Y', (int) strtotime($s['ends_on'])),
                'status' => $s['status'],
                'income' => $money($s['income']),
                'expense' => $money($s['expense']),
                'totals' => array_map(Money::format(...), $s['totals']),
                'accounts' => array_map(static fn (array $a): array => [
                    'name' => $a['name'], 'opening' => Money::format($a['opening']), 'closing' => Money::format($a['closing']),
                ], $s['accounts']),
            ],
            'canClose' => $request->user()?->can('accounting.close') ?? false,
        ]);
    }

    public function close(int $year): RedirectResponse
    {
        return $this->attempt(fn () => $this->years->close($year), "Esercizio {$year} chiuso.");
    }

    public function reopen(Request $request, int $year): RedirectResponse
    {
        // Conferma esplicita: chi riapre scrive l'anno dell'esercizio.
        $data = Input::of($request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'confirm_year' => ['required', 'integer', 'in:'.$year],
        ], ['confirm_year.in' => 'Per confermare scrivi l\'anno dell\'esercizio che riapri.', 'confirm_year.required' => 'Per confermare scrivi l\'anno dell\'esercizio che riapri.']));

        return $this->attempt(fn () => $this->years->reopen($year, Input::string($data, 'reason')), "Esercizio {$year} riaperto.");
    }

    /** @param  callable(): mixed  $action */
    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (LedgerError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }
}
