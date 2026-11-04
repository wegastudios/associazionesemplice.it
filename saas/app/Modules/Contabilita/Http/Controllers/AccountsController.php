<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Contabilita\Contracts\AccountType;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Ledger\AccountService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final readonly class AccountsController
{
    public function __construct(
        private AccountService $accounts,
        private Ledger $ledger,
    ) {}

    public function index(Request $request): Response
    {
        $balances = $this->ledger->balances();

        return Inertia::render('Contabilita/Conti', [
            'accounts' => Account::query()->orderBy('sort_order')->get()->map(fn (Account $a): array => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => AccountType::from($a->type)->label(),
                'opening' => Money::format($a->opening_cents),
                'balance' => Money::format($balances[$a->id] ?? 0),
                'open' => $a->closed_at === null,
            ])->values(),
            'types' => array_map(static fn (AccountType $t): array => ['value' => $t->value, 'label' => $t->label()], AccountType::cases()),
            'canEdit' => $request->user()?->can('accounting.edit') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = Input::of($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'opening' => ['nullable', 'string', 'max:20'],
        ]));
        $raw = Input::optionalString($data, 'opening');
        $opening = $raw === null ? 0 : Money::parse($raw);
        if ($opening === null) {
            throw ValidationException::withMessages(['opening' => 'Importo non valido.']);
        }

        return $this->attempt(fn () => $this->accounts->create(Input::string($data, 'name'), AccountType::from(Input::string($data, 'type')), $opening), 'Conto creato.');
    }

    public function close(string $account): RedirectResponse
    {
        return $this->attempt(fn () => $this->accounts->close($account), 'Conto chiuso.');
    }

    public function reopen(string $account): RedirectResponse
    {
        return $this->attempt(fn () => $this->accounts->reopen($account), 'Conto riaperto.');
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
