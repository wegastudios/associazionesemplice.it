<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Ledger;

use App\Modules\Contabilita\Contracts\AccountType;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Rules\CollectionFee;
use Illuminate\Database\QueryException;

/** Conti (cassa, banca, POS…). Un conto con saldo diverso da zero non si chiude: prima un giroconto. */
final readonly class AccountService
{
    public function __construct(private LedgerService $ledger) {}

    public function create(string $name, AccountType $type, int $openingCents): Account
    {
        $account = new Account([
            'name' => self::name($name),
            'type' => $type->value,
            'opening_cents' => $openingCents,
            'sort_order' => Account::query()->count(),
        ]);
        $account->save();

        return $account;
    }

    public function update(string $id, string $name, AccountType $type, int $openingCents): Account
    {
        $account = Account::query()->findOrFail($id);
        $account->fill(['name' => self::name($name), 'type' => $type->value, 'opening_cents' => $openingCents]);
        try {
            $account->save();
        } catch (QueryException $e) {
            // Il conto ha movimenti in un esercizio chiuso: il database rifiuta e dice cosa fare.
            if (($e->errorInfo[0] ?? null) === 'P0001' && is_string($e->errorInfo[2] ?? null)) {
                throw new LedgerError(trim((string) preg_replace('/^ERROR:\s*|\s*CONTEXT:.*$/s', '', $e->errorInfo[2])), 0, $e);
            }
            throw $e;
        }

        return $account;
    }

    /** Imposta la commissione di incasso del conto: quota fissa in centesimi (al massimo 20 €) e percentuale in punti base (al massimo 25%). */
    public function setFee(string $id, int $fixedCents, int $bp): Account
    {
        $errors = CollectionFee::validate($fixedCents, $bp);
        if ($errors !== []) {
            throw new LedgerError(implode(' ', $errors));
        }
        $account = Account::query()->find($id) ?? throw new LedgerError('Conto non trovato.');
        $account->fill(['fee_fixed_cents' => $fixedCents, 'fee_bp' => $bp])->save();

        return $account;
    }

    public function close(string $id): void
    {
        $account = Account::query()->whereNull('closed_at')->find($id) ?? throw new LedgerError('Conto non trovato o già chiuso.');
        $balance = $this->ledger->balances()[$account->id] ?? 0;
        if ($balance !== 0) {
            throw new LedgerError('Il conto ha ancora '.Money::format($balance).': spostali con un giroconto prima di chiuderlo.');
        }
        $account->forceFill(['closed_at' => now()])->save();
    }

    public function reopen(string $id): void
    {
        $account = Account::query()->whereNotNull('closed_at')->find($id) ?? throw new LedgerError('Conto non trovato o già aperto.');
        $account->forceFill(['closed_at' => null])->save();
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new LedgerError('Il nome del conto è obbligatorio (al massimo 120 caratteri).');
        }

        return $name;
    }
}
