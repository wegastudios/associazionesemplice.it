<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Payments;

use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\PayableDues;
use InvalidArgumentException;

/**
 * La donazione aggiunta al pagamento online (chiave d:{persona}). Non è mai una voce dovuta: la propone il pagamento.
 * All'incasso va in prima nota nella voce «Erogazione liberale», con una ricevuta a parte (stessa numerazione delle
 * altre, tipo «Erogazione liberale»), senza IVA e senza commissione a carico di chi dona.
 */
final readonly class DonationDues implements PayableDues
{
    public function __construct(private Ledger $ledger) {}

    public function dues(array $personIds): array
    {
        return [];
    }

    public function owns(string $key): bool
    {
        return str_starts_with($key, 'd:');
    }

    public function settle(string $key, int $cents, string $accountId, string $date, string $method, bool $anyway = false): ?string
    {
        if (preg_match('#^d:([0-9a-f-]{36})$#', $key, $m) !== 1 || $cents <= 0) {
            throw new InvalidArgumentException('Voce non valida.');
        }
        $category = $this->ledger->categoryFor(CategoryKind::Donation) ?? throw new InvalidArgumentException('Manca la voce «Erogazione liberale» in prima nota.');

        return $this->ledger->recordIncome(new Income($date, $accountId, [new IncomeLine($category, $cents, 'Erogazione liberale')], PaymentMethod::from($method), $m[1]))['receipt'];
    }
}
