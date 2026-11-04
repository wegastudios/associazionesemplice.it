<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Import;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\AccountType;
use App\Modules\Contabilita\Contracts\Expense;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Ledger\AccountService;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\SheetReader;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Import della prima nota (dall'ImportService del plugin): anteprima, poi applicazione dello stesso file. I movimenti si
 * registrano come quelli fatti a mano (senza ricevuta: sono storici), i conti nuovi si creano, i doppioni si saltano e
 * una riga rifiutata non ferma le altre.
 *
 * @phpstan-import-type Plan from LedgerSheet
 */
final readonly class LedgerImportService
{
    public const MAX_ROWS = 10000;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private Ledger $ledger,
        private AccountService $accounts,
        private AuditLog $audit,
    ) {}

    /** @return array{error: string}|array{plan: list<Plan>, summary: array<string, mixed>} */
    public function preview(string $path, string $originalName, ?string $defaultAccountId): array
    {
        try {
            $sheets = SheetReader::read($path, $originalName);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
        $rows = [];
        $firstError = null;
        foreach ($sheets as $sheet) {
            $parsed = LedgerSheet::parseTable($sheet['rows'], $sheet['lines'], count($sheets) > 1 ? $sheet['name'] : '');
            if (isset($parsed['error'])) {
                $firstError ??= $parsed['error'];

                continue;
            }
            array_push($rows, ...$parsed['rows']);
        }
        if ($rows === []) {
            return ['error' => $firstError ?? 'Nel file non ci sono movimenti.'];
        }
        if (count($rows) > self::MAX_ROWS) {
            return ['error' => 'Il file ha più di '.self::MAX_ROWS.' movimenti: dividilo in più file.'];
        }

        $accounts = $this->accountsList();
        $dates = array_values(array_filter(array_map(static fn (array $r): ?string => $r['date'], $rows)));
        $plan = LedgerSheet::plan($rows, [
            'accounts' => $accounts,
            'categories' => $this->categories(),
            'people' => $this->people(),
            'activities' => $this->activities(),
            'existing' => $dates === [] ? [] : $this->existing(min($dates), max($dates)),
            'default_account_id' => $defaultAccountId,
        ]);
        $names = [];
        foreach ($accounts as $a) {
            $names[$a['id']] = $a['name'];
        }

        return ['plan' => $plan, 'summary' => LedgerSheet::summary($plan, $names)];
    }

    /**
     * @param  list<Plan>  $plan
     * @return array{income: int, expense: int, transfer: int, accounts: int, failed: list<string>}
     */
    public function apply(array $plan): array
    {
        $res = ['income' => 0, 'expense' => 0, 'transfer' => 0, 'accounts' => 0, 'failed' => []];

        $this->connection()->transaction(function () use ($plan, &$res): void {
            $created = [];
            $account = function (mixed $id, mixed $new, mixed $type) use (&$created, &$res): string {
                if (is_string($id)) {
                    return $id;
                }
                $name = is_string($new) ? $new : 'Conto importato';
                if (! isset($created[$name])) {
                    $created[$name] = $this->accounts->create($name, AccountType::tryFrom(is_string($type) ? $type : '') ?? AccountType::Bank, 0)->id;
                    $res['accounts']++;
                }

                return $created[$name];
            };
            $str = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

            foreach ($plan as $p) {
                $d = $p['data'];
                if ($d === null || ! in_array($p['action'], ['create', 'transfer'], true)) {
                    continue;
                }
                $where = ($p['row']['sheet'] !== '' ? $p['row']['sheet'].' ' : '').'riga '.$p['row']['line'];
                try {
                    $date = (string) $str($d['date']);
                    $cents = is_int($d['cents']) ? $d['cents'] : 0;
                    $method = PaymentMethod::tryFrom((string) $str($d['method'])) ?? PaymentMethod::Other;
                    if ($p['action'] === 'transfer' && is_array($d['from']) && is_array($d['to'])) {
                        $this->ledger->recordTransfer($date, $account($d['from']['id'] ?? null, $d['from']['new'] ?? null, $d['from']['type'] ?? null),
                            $account($d['to']['id'] ?? null, $d['to']['new'] ?? null, $d['to']['type'] ?? null), $cents, (string) $str($d['description']));
                        $res['transfer']++;

                        continue;
                    }
                    $acc = $account($d['account_id'], $d['new_account'], $d['new_account_type']);
                    if ($d['type'] === 'income') {
                        $this->ledger->recordIncome(new Income($date, $acc, [new IncomeLine((string) $str($d['category_id']), $cents, (string) $str($d['description']), $str($d['month']))],
                            $method, $str($d['person_id']), false, $str($d['ref']), $str($d['activity_id'])));
                        $res['income']++;
                    } else {
                        $this->ledger->recordExpense(new Expense($date, $acc, (string) $str($d['category_id']), $cents, $method, (string) $str($d['description']),
                            $str($d['person_id']), $str($d['ref']), null, $str($d['activity_id'])));
                        $res['expense']++;
                    }
                } catch (LedgerError $e) {
                    $res['failed'][] = $where.': '.$e->getMessage();
                }
            }
            $this->audit->record('ledger.imported', 'app.transactions', null, [
                'income' => $res['income'], 'expense' => $res['expense'], 'transfer' => $res['transfer'], 'accounts' => $res['accounts'], 'failed' => count($res['failed']),
            ]);
        });

        return $res;
    }

    /** @return list<array{id: string, name: string, type: string}> */
    public function accountsList(): array
    {
        $out = [];
        foreach ($this->connection()->table('app.accounts')->orderBy('sort_order')->get(['id', 'name', 'type', 'closed_at']) as $a) {
            if (is_string($a->id ?? null) && is_string($a->name ?? null) && is_string($a->type ?? null)) {
                $out[] = ['id' => $a->id, 'name' => $a->name, 'type' => $a->type];
            }
        }

        return $out;
    }

    /** @return list<array{id: string, name: string, kind: string}> */
    private function categories(): array
    {
        $out = [];
        foreach ($this->connection()->table('app.categories')->whereNull('deleted_at')->orderBy('sort_order')->get(['id', 'name', 'kind']) as $c) {
            if (is_string($c->id ?? null) && is_string($c->name ?? null) && is_string($c->kind ?? null)) {
                $out[] = ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind];
            }
        }

        return $out;
    }

    /** @return list<array{id: string, card: string|null, first: string, last: string}> nome e tessera sono in chiaro */
    private function people(): array
    {
        $out = [];
        foreach ($this->connection()->table('app.people')->whereNull('deleted_at')->get(['id', 'card_number', 'first_name', 'last_name']) as $p) {
            if (is_string($p->id ?? null)) {
                $out[] = ['id' => $p->id, 'card' => is_string($p->card_number ?? null) ? $p->card_number : null,
                    'first' => is_string($p->first_name ?? null) ? $p->first_name : '', 'last' => is_string($p->last_name ?? null) ? $p->last_name : ''];
            }
        }

        return $out;
    }

    /** @return list<array{id: string, name: string}> */
    private function activities(): array
    {
        $out = [];
        foreach ($this->connection()->table('app.activities')->get(['id', 'name']) as $a) {
            if (is_string($a->id ?? null) && is_string($a->name ?? null)) {
                $out[] = ['id' => $a->id, 'name' => $a->name];
            }
        }

        return $out;
    }

    /** @return array<string, int> chiave del movimento => quanti ce ne sono già (non stornati) nel periodo */
    private function existing(string $from, string $to): array
    {
        $out = [];
        $rows = $this->connection()->table('app.transactions')->whereNull('voided_at')->whereBetween('tx_date', [$from, $to])
            ->orderBy('created_at')->get(['tx_date', 'account_id', 'type', 'amount_cents', 'description', 'document_ref', 'transfer_id']);
        $transfers = [];
        foreach ($rows as $t) {
            $date = is_string($t->tx_date ?? null) ? substr($t->tx_date, 0, 10) : '';
            $cents = is_numeric($t->amount_cents ?? null) ? (int) $t->amount_cents : 0;
            $desc = is_string($t->description ?? null) ? $t->description : '';
            $acc = is_string($t->account_id ?? null) ? $t->account_id : '';
            if (is_string($t->transfer_id ?? null)) {
                $transfers[$t->transfer_id][$t->type === 'transfer_out' ? 'out' : 'in'] = $acc;
                $transfers[$t->transfer_id] += ['date' => $date, 'cents' => $cents, 'desc' => $desc];

                continue;
            }
            $key = LedgerSheet::key($date, $acc, is_string($t->type ?? null) ? $t->type : '', $cents, $desc, is_string($t->document_ref ?? null) ? $t->document_ref : '');
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        foreach ($transfers as $tr) {
            $key = LedgerSheet::key($tr['date'], ($tr['out'] ?? '').'>'.($tr['in'] ?? ''), 'transfer', $tr['cents'], $tr['desc'], '');
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
