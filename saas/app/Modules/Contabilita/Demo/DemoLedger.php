<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Demo;

use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Expense;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Core\Contracts\ArchiveError;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\DemoSeeder;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\YearArchives;
use Illuminate\Database\DatabaseManager;

/**
 * Prima nota di contorno e chiusure per la demo: erogazioni liberali e costi generali (affitto, utenze, assicurazione) in
 * ogni anno fiscale, scelti perché gli anni passati chiudano in avanzo e l'anno appena finito (non chiuso) resti in
 * DISAVANZO apposta, per far vedere il controllo. La cassa non va mai sotto zero. Poi si chiudono i due anni più
 * vecchi e il primo si archivia, per far vedere gli anni chiusi e l'archivio.
 */
final readonly class DemoLedger implements DemoSeeder
{
    /** Quanto il disavanzo voluto (centesimi). */
    private const DEFICIT = 300000;

    /** @var list<array{string, int}> descrizione, parte dei costi generali (per cento) */
    private const COSTS = [['Affitto sede', 40], ['Utenze', 20], ['Assicurazione', 15], ['Materiale e cancelleria', 15], ['Manutenzione', 10]];

    public function __construct(
        private Ledger $ledger,
        private FiscalYearService $years,
        private YearArchives $archives,
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function order(): int
    {
        return 90;
    }

    public function seed(string $orgId): void
    {
        $accounts = array_column($this->ledger->openAccounts(), 'id');
        $cash = $accounts[0] ?? null;
        $bank = $accounts[1] ?? $cash;
        $donation = $this->ledger->categoryFor(CategoryKind::Donation);
        $general = $this->ledger->categoryFor(CategoryKind::GeneralCost);
        if ($cash === null || $bank === null || $donation === null || $general === null) {
            return;
        }
        $current = DemoScale::currentYear();

        foreach ([$current - 3 => 600000, $current - 2 => 400000, $current - 1 => 100000, $current => 50000] as $year => $cents) {
            $this->donation($bank, $donation, $year, $cents, 'Erogazione liberale di un sostenitore (dato di esempio)');
        }

        foreach (range($current - 3, $current) as $year) {
            $income = $this->sum($year, 'income');
            $target = match ($year) {
                $current - 1 => $income + self::DEFICIT,
                $current => (int) round($income * 0.6),
                default => (int) round($income * 0.7),
            };
            $gap = $target - $this->sum($year, 'expense');
            if ($gap <= 0) {
                continue;
            }
            foreach (self::COSTS as $i => [$description, $share]) {
                $cents = (int) round($gap * $share / 100);
                if ($cents > 0) {
                    $byCash = $i === 3;
                    $this->ledger->recordExpense(new Expense(
                        DemoScale::day($year, 20 + $i * 70), $byCash ? $cash : $bank, $general, $cents,
                        $byCash ? PaymentMethod::Cash : PaymentMethod::BankTransfer, $description.' '.$year.'/'.($year + 1),
                    ));
                }
            }
        }

        // La cassa non va mai sotto zero: un conto in rosso si rimette in pari con un'erogazione nel primo anno.
        foreach ($this->ledger->balances() as $account => $balance) {
            if ($balance < 0) {
                $this->donation($account, $donation, $current - 3, -$balance + 50000, 'Erogazione liberale a copertura (dato di esempio)');
            }
        }

        // Anni chiusi: i due più vecchi; il più vecchio si archivia (l'ultimo chiuso no: resta com'è).
        $this->years->close($current - 3);
        $this->years->close($current - 2);
        try {
            $this->archives->archive($current - 3);
        } catch (ArchiveError) {
            // La demo funziona anche senza archivio: nessun file da archiviare.
        }
    }

    private function donation(string $account, string $category, int $year, int $cents, string $description): void
    {
        $this->ledger->recordIncome(new Income(
            DemoScale::day($year, 45), $account, [new IncomeLine($category, $cents, $description)], PaymentMethod::BankTransfer, null, false,
        ));
    }

    private function sum(int $year, string $type): int
    {
        $total = $this->db->connection($this->context->require()->connection)->table('app.transactions')
            ->where('fiscal_year', $year)->where('type', $type)->whereNull('voided_at')->sum('amount_cents');

        return is_numeric($total) ? (int) $total : 0;
    }
}
