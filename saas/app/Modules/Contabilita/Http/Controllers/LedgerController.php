<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Attivita\Contracts\Activities;
use App\Modules\Contabilita\Contracts\Expense;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Contabilita\Models\Receipt;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Contabilita\Rules\Vat;
use App\Modules\Core\Contracts\Input;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Prima nota: elenco per esercizio, incassi, spese, giroconti, storni. */
final readonly class LedgerController
{
    public function __construct(
        private Ledger $ledger,
        private FiscalYearService $years,
        private Activities $activities,
    ) {}

    public function index(Request $request): Response
    {
        $years = $this->years->all();
        $year = $request->integer('anno', $years[0]['year'] ?? (int) now()->format('Y'));
        $account = $request->string('conto')->toString();

        $query = Transaction::query()->where('fiscal_year', $year)
            ->orderByDesc('tx_date')->orderByDesc('created_at')->orderByDesc('id');
        if ($account !== '' && Str::isUuid($account)) {
            $query->where('account_id', $account);
        }
        $activity = $request->string('attivita')->toString();
        if ($activity !== '' && Str::isUuid($activity)) {
            $query->where('activity_id', $activity);
        }
        $page = $query->cursorPaginate(50)->withQueryString();

        $accounts = Account::query()->orderBy('sort_order')->get(['id', 'name', 'type', 'closed_at']);
        $categories = Category::query()->orderBy('sort_order')->get(['id', 'name', 'kind']);
        $receipts = Receipt::query()
            ->whereIn('id', collect($page->items())->pluck('receipt_id')->filter()->unique()->values())
            ->get(['id', 'number', 'year'])->keyBy('id');
        $activityNames = $this->activities->names(array_values(array_filter(collect($page->items())->pluck('activity_id')->unique()->all(), is_string(...))));

        return Inertia::render('Contabilita/PrimaNota', [
            'year' => $year,
            'years' => $years,
            'account' => $account,
            'activity' => $activity,
            'activities' => $this->activities->options(),
            'accounts' => $accounts->map(fn (Account $a): array => ['id' => $a->id, 'name' => $a->name, 'open' => $a->closed_at === null])->values(),
            'categories' => $categories->map(fn (Category $c): array => [
                'id' => $c->id, 'name' => $c->name, 'income' => $c->kind->isIncome(), 'expense' => $c->kind->isExpense(), 'adjustment' => $c->kind->value === 'adjustment',
            ])->values(),
            'methods' => array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
            'rows' => collect($page->items())->map(fn (Transaction $t): array => [
                'id' => $t->id,
                'date' => $t->tx_date->format('d/m/Y'),
                'type' => $t->type->value,
                'type_label' => $t->type->label(),
                'amount' => Money::format($t->type->sign() * $t->amount_cents),
                'account' => $accounts->firstWhere('id', $t->account_id)?->name,
                'category' => $categories->firstWhere('id', $t->category_id)?->name,
                'method' => $t->method->label(),
                'description' => $t->description,
                'receipt' => $t->receipt_id !== null && isset($receipts[$t->receipt_id]) ? $receipts[$t->receipt_id]->label() : null,
                'voided' => $t->voided_at !== null,
                'void_reason' => $t->void_reason,
                'activity_id' => $t->activity_id,
                'activity' => $t->activity_id !== null ? ($activityNames[$t->activity_id] ?? null) : null,
            ])->values(),
            'next' => $page->nextPageUrl(),
            'prev' => $page->previousPageUrl(),
            'balances' => collect($this->ledger->balances())->map(fn (int $c): string => Money::format($c)),
            'canEdit' => $request->user()?->can('accounting.edit') ?? false,
        ]);
    }

    public function income(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'uuid'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
            'receipt' => ['boolean'],
            'person_id' => ['nullable', 'uuid'],
            'document_ref' => ['nullable', 'string', 'max:80'],
            'activity_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.category_id' => ['required', 'uuid'],
            'lines.*.amount' => ['required', 'string', 'max:20'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.competence_month' => ['nullable', 'date_format:Y-m'],
            'lines.*.vat_rate' => ['nullable'],
        ]));

        return $this->attempt(function () use ($data): string {
            $lines = [];
            foreach (Input::rows($data, 'lines') as $i => $line) {
                $lines[] = new IncomeLine(
                    Input::string($line, 'category_id'),
                    self::cents(Input::raw($line, 'amount'), "lines.{$i}.amount"),
                    Input::optionalString($line, 'description') ?? '',
                    Input::optionalString($line, 'competence_month'),
                    Vat::cleanRate(Input::raw($line, 'vat_rate')),
                );
            }
            $method = Input::optionalString($data, 'method');
            $result = $this->ledger->recordIncome(new Income(
                Input::string($data, 'date'),
                Input::string($data, 'account_id'),
                $lines,
                $method === null ? null : PaymentMethod::from($method),
                Input::optionalString($data, 'person_id'),
                Input::bool($data, 'receipt', true),
                Input::optionalString($data, 'document_ref'),
                Input::optionalString($data, 'activity_id'),
            ));

            return $result['receipt'] === null ? 'Incasso registrato.' : "Incasso registrato: ricevuta n. {$result['receipt']}.";
        });
    }

    public function expense(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'uuid'],
            'category_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
            'description' => ['nullable', 'string', 'max:255'],
            'document_ref' => ['nullable', 'string', 'max:80'],
            'vat_rate' => ['nullable'],
            'activity_id' => ['nullable', 'uuid'],
        ]));

        return $this->attempt(function () use ($data): string {
            $method = Input::optionalString($data, 'method');
            $this->ledger->recordExpense(new Expense(
                Input::string($data, 'date'),
                Input::string($data, 'account_id'),
                Input::string($data, 'category_id'),
                self::cents(Input::raw($data, 'amount'), 'amount'),
                $method === null ? null : PaymentMethod::from($method),
                Input::optionalString($data, 'description') ?? '',
                null,
                Input::optionalString($data, 'document_ref'),
                Vat::cleanRate(Input::raw($data, 'vat_rate')),
                Input::optionalString($data, 'activity_id'),
            ));

            return 'Spesa registrata.';
        });
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'from_account_id' => ['required', 'uuid'],
            'to_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ]));

        return $this->attempt(function () use ($data): string {
            $this->ledger->recordTransfer(
                Input::string($data, 'date'),
                Input::string($data, 'from_account_id'),
                Input::string($data, 'to_account_id'),
                self::cents(Input::raw($data, 'amount'), 'amount'),
                Input::optionalString($data, 'description') ?? '',
            );

            return 'Giroconto registrato.';
        });
    }

    public function void(Request $request, string $transaction): RedirectResponse
    {
        $data = Input::of($request->validate(['reason' => ['required', 'string', 'max:255']]));

        return $this->attempt(fn (): string => $this->ledger->void($transaction, Input::string($data, 'reason')) > 1
            ? 'Movimento stornato insieme alle righe collegate.'
            : 'Movimento stornato.');
    }

    /** Cambia l'attività (centro di costo) di un movimento. */
    public function activity(Request $request, string $transaction): RedirectResponse
    {
        $data = Input::of($request->validate(['activity_id' => ['nullable', 'uuid']]));

        return $this->attempt(function () use ($transaction, $data): string {
            $this->ledger->assignActivity($transaction, Input::optionalString($data, 'activity_id'));

            return 'Attività del movimento aggiornata.';
        });
    }

    /** @param  Closure(): string  $action */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return back()->with('status', $action());
        } catch (LedgerError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
    }

    private static function cents(mixed $value, string $field): int
    {
        $cents = Money::parse(is_string($value) ? $value : null);
        if ($cents === null || $cents <= 0) {
            throw ValidationException::withMessages([$field => 'Importo non valido.']);
        }

        return $cents;
    }
}
