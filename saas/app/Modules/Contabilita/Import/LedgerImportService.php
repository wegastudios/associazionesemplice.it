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
use App\Modules\Core\Contracts\ImportJobs;
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
 * L'applicazione è un lavoro in coda (H11): a blocchi, una transazione per blocco, conti, voci, persone e attività letti
 * una volta per blocco, un solo inserimento per blocco e una sola riga di registro per tutto l'import. apply() fa lo
 * stesso subito, nella richiesta: stesso esito.
 *
 * @phpstan-import-type Plan from LedgerSheet
 *
 * @phpstan-type Result array{income: int, expense: int, transfer: int, accounts: int, failed: list<string>}
 */
final readonly class LedgerImportService
{
    public const MAX_ROWS = 10000;

    public const KIND = 'ledger';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private Ledger $ledger,
        private AccountService $accounts,
        private AuditLog $audit,
        private ImportJobs $jobs,
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
     * Mette in coda l'import dello stesso piano dell'anteprima (H11). null se ce n'è già uno della prima nota in corso.
     *
     * @param  list<Plan>  $plan
     * @param  bool  $canIncome  chi importa può registrare entrate
     * @param  bool  $canExpense  chi importa può registrare spese
     */
    public function queue(array $plan, bool $canIncome, bool $canExpense, ?string $userId): ?string
    {
        return $this->jobs->create(self::KIND, self::items($plan), ['can_income' => $canIncome, 'can_expense' => $canExpense], self::emptyState(), $userId);
    }

    /**
     * L'import della prima nota da mostrare a schermo (in coda, in corso, fermo, o finito da poco), o null.
     *
     * @return array{id: string, status: string, total: int, done: int, stalled: bool, active: bool, result: Result}|null
     */
    public function current(): ?array
    {
        $job = $this->jobs->current(self::KIND);

        return $job === null ? null : ['id' => $job['id'], 'status' => $job['status'], 'total' => $job['total'], 'done' => $job['done'],
            'stalled' => $job['stalled'], 'active' => $job['active'], 'result' => self::result($job['state'])];
    }

    public function cancel(string $id): bool
    {
        return $this->jobs->cancel($id, self::KIND);
    }

    public function resume(string $id): bool
    {
        return $this->jobs->resume($id, self::KIND);
    }

    /** Un giro del lavoro in coda: false se resta da fare (chi chiama lo rimette in coda). */
    public function runQueued(string $id, int $blockSize, int $seconds): bool
    {
        return $this->jobs->run(
            $id,
            $blockSize,
            $seconds,
            fn (array $items, array $state, array $options): array => $this->block($items, $state, ($options['can_income'] ?? false) === true, ($options['can_expense'] ?? false) === true),
            fn (array $state): array => $this->finish($state),
        );
    }

    /**
     * Applica subito il piano, a blocchi, come il lavoro in coda.
     *
     * @param  list<Plan>  $plan
     * @param  bool  $canIncome  chi importa può registrare entrate
     * @param  bool  $canExpense  chi importa può registrare spese
     * @return Result
     */
    public function apply(array $plan, bool $canIncome = true, bool $canExpense = true, int $blockSize = 100): array
    {
        $state = self::emptyState();
        foreach (array_chunk(self::items($plan), max(1, $blockSize)) as $chunk) {
            $state = $this->connection()->transaction(fn (): array => $this->block($chunk, $state, $canIncome, $canExpense));
        }

        return self::result($this->connection()->transaction(fn (): array => $this->finish($state)));
    }

    /**
     * L'esito salvato nello stato del lavoro.
     *
     * @param  array<string, mixed>  $state
     * @return Result
     */
    public static function result(array $state): array
    {
        $r = is_array($state['result'] ?? null) ? $state['result'] : [];
        $int = static fn (string $k): int => is_int($r[$k] ?? null) ? $r[$k] : 0;

        return [
            'income' => $int('income'), 'expense' => $int('expense'), 'transfer' => $int('transfer'), 'accounts' => $int('accounts'),
            'failed' => is_array($r['failed'] ?? null) ? array_values(array_filter($r['failed'], 'is_string')) : [],
        ];
    }

    /**
     * Le righe da registrare, nell'ordine del file.
     *
     * @param  list<Plan>  $plan
     * @return list<Plan>
     */
    private static function items(array $plan): array
    {
        return array_values(array_filter($plan, static fn (array $p): bool => $p['data'] !== null && in_array($p['action'], ['create', 'transfer'], true)));
    }

    /** @return array{result: Result, accounts: array<string, string>} */
    private static function emptyState(): array
    {
        return ['result' => ['income' => 0, 'expense' => 0, 'transfer' => 0, 'accounts' => 0, 'failed' => []], 'accounts' => []];
    }

    /**
     * Un blocco, dentro la sua transazione: incassi e spese con un solo inserimento (Ledger::recordImported), i giroconti
     * uno per uno. I conti nuovi creati nei blocchi precedenti si ritrovano nello stato.
     *
     * @param  list<mixed>  $items
     * @param  array<string, mixed>  $state
     * @return array{result: Result, accounts: array<string, string>}
     */
    private function block(array $items, array $state, bool $canIncome, bool $canExpense): array
    {
        /** @var list<Plan> $items */
        $res = self::result($state);
        /** @var array<string, string> $created */
        $created = is_array($state['accounts'] ?? null) ? $state['accounts'] : [];
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

        $failed = [];
        $entries = [];
        foreach ($items as $i => $p) {
            $d = (array) $p['data'];
            $where = ($p['row']['sheet'] !== '' ? $p['row']['sheet'].' ' : '').'riga '.$p['row']['line'];
            // Le righe di un tipo per cui chi importa non ha il permesso non si registrano (e non creano nemmeno conti).
            if ($p['action'] === 'create' && ! ($d['type'] === 'income' ? $canIncome : $canExpense)) {
                $failed[$i] = $where.': non hai il permesso di registrare '.($d['type'] === 'income' ? 'entrate' : 'spese').'.';

                continue;
            }
            try {
                $date = (string) $str($d['date'] ?? null);
                $cents = is_int($d['cents'] ?? null) ? $d['cents'] : 0;
                $method = PaymentMethod::tryFrom((string) $str($d['method'] ?? null)) ?? PaymentMethod::Other;
                $from = $d['from'] ?? null;
                $to = $d['to'] ?? null;
                if ($p['action'] === 'transfer' && is_array($from) && is_array($to)) {
                    $this->ledger->recordTransfer($date, $account($from['id'] ?? null, $from['new'] ?? null, $from['type'] ?? null),
                        $account($to['id'] ?? null, $to['new'] ?? null, $to['type'] ?? null), $cents, (string) $str($d['description'] ?? null));
                    $res['transfer']++;

                    continue;
                }
                $acc = $account($d['account_id'] ?? null, $d['new_account'] ?? null, $d['new_account_type'] ?? null);
                $entries[$i] = [$where, ($d['type'] ?? null) === 'income'
                    ? new Income($date, $acc, [new IncomeLine((string) $str($d['category_id'] ?? null), $cents, (string) $str($d['description'] ?? null), $str($d['month'] ?? null))],
                        $method, $str($d['person_id'] ?? null), false, $str($d['ref'] ?? null), $str($d['activity_id'] ?? null), accountFee: false)
                    : new Expense($date, $acc, (string) $str($d['category_id'] ?? null), $cents, $method, (string) $str($d['description'] ?? null),
                        $str($d['person_id'] ?? null), $str($d['ref'] ?? null), null, $str($d['activity_id'] ?? null))];
            } catch (LedgerError $e) {
                $failed[$i] = $where.': '.$e->getMessage();
            }
        }

        $outcomes = $this->ledger->recordImported(array_values(array_map(static fn (array $e): Income|Expense => $e[1], $entries)));
        foreach (array_keys($entries) as $n => $i) {
            $outcome = $outcomes[$n] ?? null;
            if ($outcome instanceof LedgerError) {
                $failed[$i] = $entries[$i][0].': '.$outcome->getMessage();
            } else {
                $res[$entries[$i][1] instanceof Income ? 'income' : 'expense']++;
            }
        }
        // Gli errori nell'ordine delle righe del file, come quando si registrava una riga alla volta.
        ksort($failed);
        array_push($res['failed'], ...array_values($failed));

        return ['result' => $res, 'accounts' => $created];
    }

    /**
     * Alla fine, nella transazione dell'ultimo blocco: la sola riga di registro dell'import.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function finish(array $state): array
    {
        $res = self::result($state);
        $this->audit->record('ledger.imported', 'app.transactions', null, [
            'income' => $res['income'], 'expense' => $res['expense'], 'transfer' => $res['transfer'], 'accounts' => $res['accounts'], 'failed' => count($res['failed']),
        ]);

        return $state;
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
