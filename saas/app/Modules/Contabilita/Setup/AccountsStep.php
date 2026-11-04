<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Setup;

use App\Modules\Contabilita\Contracts\AccountType;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Ledger\AccountService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\SetupStep;

/** Conti: il saldo di partenza di quelli che ci sono e, se serve, il conto corrente in banca. */
final readonly class AccountsStep implements SetupStep
{
    public function __construct(private AccountService $accounts) {}

    public function key(): string
    {
        return 'conti';
    }

    public function title(): string
    {
        return 'Conti e saldi di partenza';
    }

    public function order(): int
    {
        return 30;
    }

    public function visible(Operator $operator): bool
    {
        return $operator->can(Ability::ManageSettings) && $operator->can(Ability::EditAccounting);
    }

    public function props(): array
    {
        $accounts = [];
        foreach (Account::query()->whereNull('closed_at')->orderBy('sort_order')->get() as $a) {
            $accounts[] = ['id' => $a->id, 'name' => $a->name, 'opening' => Money::plain($a->opening_cents)];
        }

        return ['accounts' => $accounts];
    }

    public function errors(array $input): array
    {
        $errors = [];
        foreach (is_array($input['openings'] ?? null) ? $input['openings'] : [] as $value) {
            if (is_string($value) && trim($value) !== '' && Money::parse($value) === null) {
                $errors[] = 'Un saldo di partenza non è un importo valido.';
                break;
            }
        }
        $bank = is_string($input['bank_name'] ?? null) ? trim($input['bank_name']) : '';
        if (mb_strlen($bank) > 120) {
            $errors[] = 'Il nome del conto è troppo lungo.';
        }
        $opening = is_string($input['bank_opening'] ?? null) ? trim($input['bank_opening']) : '';
        if ($opening !== '' && Money::parse($opening) === null) {
            $errors[] = 'Il saldo del conto corrente non è un importo valido.';
        }

        return $errors;
    }

    public function save(array $input): void
    {
        $openings = is_array($input['openings'] ?? null) ? $input['openings'] : [];
        foreach (Account::query()->whereNull('closed_at')->get() as $a) {
            $value = $openings[$a->id] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $cents = Money::parse($value) ?? $a->opening_cents;
                if ($cents !== $a->opening_cents) {
                    $this->accounts->update($a->id, $a->name, AccountType::from($a->type), $cents);
                }
            }
        }
        $bank = is_string($input['bank_name'] ?? null) ? trim($input['bank_name']) : '';
        if ($bank !== '') {
            $opening = is_string($input['bank_opening'] ?? null) ? (Money::parse($input['bank_opening']) ?? 0) : 0;
            $this->accounts->create($bank, AccountType::Bank, $opening);
        }
    }
}
