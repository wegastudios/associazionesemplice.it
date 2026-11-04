<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Import;

use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Documenti\Contracts\SheetReader;
use App\Modules\Documenti\Contracts\Text;

/**
 * Import della prima nota da Excel o CSV (dal LedgerImport del plugin): lettura delle righe e piano di importazione
 * (crea, giroconto, doppione, salta, errore). Codice puro: non scrive nel database. Riconosce anche il file che esporta
 * il gestionale (Data, Tipo, Conto, … Entrata, Uscita).
 *
 * @phpstan-type Row array{line: int, sheet: string, errors: list<string>, date: string|null, type: string|null, cents: int, account: string, method: string, category: string, desc: string, ref: string, card: string, person: string, activity: string, month: string}
 * @phpstan-type Plan array{row: Row, action: string, message: string, warnings: list<string>, data: array<string, mixed>|null}
 */
final class LedgerSheet
{
    public const COLS = [
        'date' => ['data', 'datamovimento', 'datadioperazione', 'dataoperazione', 'datapagamento', 'giorno', 'date'],
        'type' => ['tipo', 'tipomovimento', 'movimento', 'tipologia', 'eu', 'entrataouscita', 'segno'],
        'account' => ['conto', 'cassa', 'banca', 'contocassa', 'contobanca'],
        'method' => ['modalita', 'modalitapagamento', 'modalitadipagamento', 'metodo', 'metodopagamento', 'pagamento', 'mezzo'],
        'category' => ['voce', 'categoria', 'voceprimanota', 'vocedibilancio', 'causale'],
        'amount' => ['importo', 'ammontare', 'totale', 'valore', 'euro', 'cifra', 'amount'],
        'income' => ['entrata', 'entrate', 'incasso', 'incassi', 'income'],
        'expense' => ['uscita', 'uscite', 'spesa', 'spese', 'esborso', 'costo', 'costi', 'expense'],
        'desc' => ['descrizione', 'descr', 'dettaglio', 'note', 'annotazioni', 'oggetto'],
        'ref' => ['riferimento', 'rif', 'ndocumento', 'numerodocumento', 'ndoc', 'nfattura', 'numfattura', 'fattura', 'documento', 'nfatturascontrino'],
        'card' => ['ntessera', 'tessera', 'numerotessera', 'numtessera', 'nrtessera'],
        'person' => ['persona', 'socio', 'nominativo', 'beneficiario', 'intestatario'],
        'activity' => ['attivita', 'corso', 'evento'],
        'month' => ['competenza', 'mesecompetenza', 'mese'],
    ];

    private const MONTH_NAMES = [
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6,
        'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
    ];

    /** Parole nel nome della voce → natura della categoria. */
    private const KEYWORDS = [
        'membership' => ['quotaassoc', 'quotasoc', 'tessera', 'iscrizione'],
        'activity_fee' => ['corso', 'attivita', 'lezione', 'quotacorso', 'contributo'],
        'donation' => ['donazion', 'erogazion', 'liberal', 'offert'],
        'member_reimbursement' => ['rimborso', 'compens', 'istrutt', 'docent', 'insegnant'],
    ];

    /** @param  list<string>  $h */
    public static function acceptsHeader(array $h): bool
    {
        $has = static fn (string $k): bool => SheetReader::col($h, self::COLS[$k]) >= 0;

        return $has('date') && ($has('amount') || $has('income') || $has('expense'));
    }

    /**
     * @param  list<list<string>>  $table
     * @param  list<int>|null  $lines
     * @return array{error: string}|array{rows: list<Row>}
     */
    public static function parseTable(array $table, ?array $lines = null, string $sheet = ''): array
    {
        $hi = SheetReader::findHeader($table, self::acceptsHeader(...));
        if ($hi === null) {
            return ['error' => 'Nella prima riga servono le colonne «Data» e «Importo» (oppure «Entrata» e «Uscita»).'];
        }
        $h = SheetReader::normalizeHeader($table[$hi]);
        $col = array_map(static fn (array $names): int => SheetReader::col($h, $names), self::COLS);
        $rows = [];
        foreach ($table as $idx => $cells) {
            if ($idx <= $hi) {
                continue;
            }
            $cell = static fn (string $k): string => $col[$k] >= 0 && isset($cells[$col[$k]]) ? trim($cells[$col[$k]]) : '';
            $r = [
                'line' => $lines !== null ? ($lines[$idx] ?? $idx + 1) : $idx + 1, 'sheet' => $sheet, 'errors' => [],
                'date' => null, 'type' => null, 'cents' => 0,
                'account' => $cell('account'), 'method' => $cell('method'), 'category' => $cell('category'),
                'desc' => $cell('desc'), 'ref' => $cell('ref'), 'card' => $cell('card'),
                'person' => $cell('person'), 'activity' => $cell('activity'), 'month' => $cell('month'),
            ];
            $r['date'] = self::parseDate($cell('date'));
            if ($r['date'] === null) {
                $r['errors'][] = $cell('date') === '' ? 'Data mancante' : 'Data non valida: '.$cell('date');
            }
            $typeText = Text::normalize($cell('type'));
            $type = $typeText === '' ? null : self::typeFromText($typeText);
            $in = $cell('income') !== '' ? self::parseAmount($cell('income')) : null;
            $out = $cell('expense') !== '' ? self::parseAmount($cell('expense')) : null;
            $amount = $cell('amount') !== '' ? self::parseAmount($cell('amount')) : null;
            foreach (['income' => $in, 'expense' => $out, 'amount' => $amount] as $k => $v) {
                if ($cell($k) !== '' && $v === null) {
                    $r['errors'][] = 'Importo non valido: '.$cell($k);
                }
            }
            if (($in ?? 0) !== 0 && ($out ?? 0) !== 0) {
                $r['errors'][] = 'Ci sono sia un\'entrata sia un\'uscita nella stessa riga';
            } elseif (($in ?? 0) !== 0 || ($out ?? 0) !== 0) {
                $signed = ($in ?? 0) !== 0 ? abs((int) $in) : -abs((int) $out);
                $r['cents'] = abs($signed);
                $r['type'] = $type !== null && in_array($type, ['transfer_in', 'transfer_out'], true) ? $type : ($signed > 0 ? 'income' : 'expense');
            } elseif ($amount !== null) {
                $r['cents'] = abs($amount);
                $r['type'] = $type ?? ($amount < 0 ? 'expense' : 'income');
            } elseif ($r['errors'] === [] || $r['date'] !== null) {
                $r['errors'][] = 'Importo mancante';
            }
            if ($r['cents'] === 0 && array_filter($r['errors'], static fn (string $e): bool => str_starts_with($e, 'Importo')) === []) {
                $r['errors'][] = 'Importo uguale a zero';
            }
            // Una riga senza data e senza importi (per esempio «Totale») non è un movimento: si ignora.
            if ($cell('date') === '' && $cell('amount') === '' && $cell('income') === '' && $cell('expense') === '') {
                continue;
            }
            $rows[] = $r;
        }

        return ['rows' => $rows];
    }

    /** Testo normalizzato di una colonna «Tipo» → income, expense, transfer_in, transfer_out o null. */
    public static function typeFromText(string $n): ?string
    {
        if (str_contains($n, 'giro') || str_contains($n, 'trasfer')) {
            if (str_contains($n, 'usc')) {
                return 'transfer_out';
            }

            return str_contains($n, 'entr') ? 'transfer_in' : 'transfer_out';
        }
        foreach (['entrat', 'incass', 'ricav', 'income', 'avere'] as $k) {
            if (str_starts_with($n, $k)) {
                return 'income';
            }
        }
        foreach (['uscit', 'spes', 'pagament', 'cost', 'esbors', 'expense', 'dare'] as $k) {
            if (str_starts_with($n, $k)) {
                return 'expense';
            }
        }
        if (in_array($n, ['e', 'in', 'i'], true)) {
            return 'income';
        }

        return in_array($n, ['u', 'out', 's'], true) ? 'expense' : null;
    }

    /** «2025-03-07», «7/3/2025», «07-03-25», «07.03.2025» → «2025-03-07»; null se non valida. */
    public static function parseDate(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $s, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})(?:[ T].*)?$#', $s, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($y < 100) {
                $y += $y < 70 ? 2000 : 1900;
            }
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Importo in centesimi con segno: 1.234,56 · 1,234.56 · 12,5 · (12,50) · 12,50- · «12,50 €». */
    public static function parseAmount(string $s): ?int
    {
        $s = trim(str_replace(['€', 'EUR', 'eur', "\xC2\xA0", ' '], '', $s));
        if ($s === '') {
            return null;
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m) === 1) {
            $neg = true;
            $s = $m[1];
        }
        if (str_ends_with($s, '-')) {
            $neg = true;
            $s = substr($s, 0, -1);
        }
        $s = ltrim($s, '+');
        if (str_contains($s, '.') && str_contains($s, ',') && strrpos($s, '.') > strrpos($s, ',')) {
            $s = str_replace(',', '', $s); // stile inglese: 1,234.56
        }
        $cents = Money::parse($s);
        if ($cents === null) {
            return null;
        }

        return $neg ? -abs($cents) : $cents;
    }

    /** «2025-03», «03/2025», «marzo 2025», «2025-03-15» → «2025-03»; null se non riconosciuto. */
    public static function parseMonth(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})(-\d{1,2})?$/', $s, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return sprintf('%04d-%02d', $m[1], $m[2]);
        }
        if (preg_match('#^(\d{1,2})[/.\-](\d{4})$#', $s, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return sprintf('%04d-%02d', $m[2], $m[1]);
        }
        $d = self::parseDate($s);
        if ($d !== null) {
            return substr($d, 0, 7);
        }
        if (preg_match('/^(\p{L}+)\s+(\d{4})$/u', $s, $m) === 1) {
            $mo = self::MONTH_NAMES[Text::normalize($m[1])] ?? 0;

            return $mo > 0 ? sprintf('%04d-%02d', $m[2], $mo) : null;
        }

        return null;
    }

    /** Chiave per riconoscere i doppioni: stessa data, conto, tipo, importo, descrizione e riferimento. */
    public static function key(string $date, string $account, string $type, int $cents, string $desc, string $ref): string
    {
        return $date.'|'.$account.'|'.$type.'|'.$cents.'|'.Text::normalize($desc).'|'.Text::normalize($ref);
    }

    /**
     * @param  list<Row>  $rows
     * @param  array{accounts?: list<array{id: string, name: string, type: string}>, categories?: list<array{id: string, name: string, kind: string}>, people?: list<array{id: string, card: string|null, first: string, last: string}>, activities?: list<array{id: string, name: string}>, existing?: array<string, int>, default_account_id?: string|null}  $ctx
     * @return list<Plan>
     */
    public static function plan(array $rows, array $ctx): array
    {
        $accounts = $byId = [];
        foreach ($ctx['accounts'] ?? [] as $a) {
            $accounts[Text::normalize($a['name'])] = $a;
            $byId[$a['id']] = $a;
        }
        $newAccounts = $catsByName = $catsByKind = [];
        foreach ($ctx['categories'] ?? [] as $c) {
            if ($c['kind'] === CategoryKind::Adjustment->value) {
                continue;
            }
            $catsByName[Text::normalize($c['name'])][] = $c;
            $catsByKind[$c['kind']][] = $c;
        }
        $peopleCard = $peopleName = [];
        foreach ($ctx['people'] ?? [] as $p) {
            if (($p['card'] ?? '') !== '' && $p['card'] !== null) {
                $peopleCard[Text::lower($p['card'])] = $p;
            }
            $peopleName[Text::normalize($p['first'].$p['last'])][] = $p['id'];
            $peopleName[Text::normalize($p['last'].$p['first'])][] = $p['id'];
        }
        $acts = [];
        foreach ($ctx['activities'] ?? [] as $a) {
            $acts[Text::normalize($a['name'])][] = $a['id'];
        }
        $existing = $ctx['existing'] ?? [];
        $default = $ctx['default_account_id'] ?? null;
        $seen = [];
        $plans = [];
        $legs = [];

        foreach ($rows as $r) {
            $warn = [];
            $fail = static fn (string $m): array => ['row' => $r, 'action' => 'error', 'message' => $m, 'warnings' => [], 'data' => null];
            if ($r['errors'] !== []) {
                $plans[] = $fail(implode('; ', $r['errors']));

                continue;
            }
            $accId = null;
            $newName = null;
            $accKey = Text::normalize($r['account']);
            if ($accKey === '') {
                if ($default === null || ! isset($byId[$default])) {
                    $plans[] = $fail('Conto mancante: indica un conto nella riga o scegli un conto predefinito');

                    continue;
                }
                $accId = $default;
                $accType = $byId[$default]['type'];
            } elseif (isset($accounts[$accKey])) {
                $accId = $accounts[$accKey]['id'];
                $accType = $accounts[$accKey]['type'];
            } else {
                $newAccounts[$accKey] ??= ['name' => $r['account'], 'type' => self::guessAccountType($accKey)];
                $newName = $newAccounts[$accKey]['name'];
                $accType = $newAccounts[$accKey]['type'];
            }
            $accRef = $accId ?? 'new:'.$accKey;

            $method = self::methodFromText(Text::normalize($r['method']));
            if ($method === null) {
                if ($r['method'] !== '') {
                    $warn[] = 'Modalità «'.$r['method'].'» non riconosciuta: usata «Altro»';
                    $method = 'other';
                } else {
                    $method = ['cash' => 'cash', 'bank' => 'bank_transfer', 'pos' => 'pos'][$accType] ?? 'other';
                }
            }

            if (in_array($r['type'], ['transfer_in', 'transfer_out'], true)) {
                $legs[count($plans)] = [
                    'date' => (string) $r['date'], 'type' => $r['type'], 'cents' => $r['cents'], 'id' => $accId, 'new' => $newName,
                    'new_type' => $newName !== null ? $accType : null, 'ref' => $accRef, 'method' => $method, 'desc' => $r['desc'], 'line' => $r['line'],
                ];
                $plans[] = ['row' => $r, 'action' => 'transfer_leg', 'message' => '', 'warnings' => $warn, 'data' => null];

                continue;
            }

            $income = $r['type'] === 'income';
            $cat = self::resolveCategory($r['category'], $income, $catsByName, $catsByKind);
            $desc = $r['desc'];
            if ($cat === null) {
                $fallback = $income ? 'other_income' : 'general_cost';
                $catRow = $catsByKind[$fallback][0] ?? null;
                if ($catRow === null) {
                    $plans[] = $fail('Voce «'.$r['category'].'» non trovata e manca una voce generica');

                    continue;
                }
                $cat = ['id' => $catRow['id'], 'kind' => $fallback, 'name' => $catRow['name']];
                if ($r['category'] !== '') {
                    $warn[] = 'Voce «'.$r['category'].'» non trovata: usata «'.$catRow['name'].'»';
                    $desc = '['.$r['category'].']'.($desc !== '' ? ' '.$desc : '');
                } else {
                    $warn[] = 'Senza voce: usata «'.$catRow['name'].'»';
                }
            }

            $personId = null;
            if ($r['card'] !== '') {
                $p = $peopleCard[Text::lower(self::cleanCard($r['card']))] ?? null;
                if ($p !== null) {
                    $personId = $p['id'];
                } else {
                    $warn[] = 'Tessera '.$r['card'].' non trovata: importato senza persona';
                }
            }
            if ($personId === null && $r['person'] !== '') {
                $m = array_values(array_unique($peopleName[Text::normalize($r['person'])] ?? []));
                if (count($m) === 1) {
                    $personId = $m[0];
                } else {
                    $warn[] = ($m !== [] ? 'Più persone chiamate' : 'Persona non trovata:').' «'.$r['person'].'»: importato senza persona';
                }
            }
            $activityId = null;
            if ($r['activity'] !== '') {
                $m = $acts[Text::normalize($r['activity'])] ?? [];
                if (count($m) === 1) {
                    $activityId = $m[0];
                } else {
                    $warn[] = ($m !== [] ? 'Attività con lo stesso nome in più anni' : 'Attività non trovata').' «'.$r['activity'].'»: importato senza attività';
                }
            }
            $month = null;
            if ($r['month'] !== '') {
                $month = self::parseMonth($r['month']);
                if ($month === null) {
                    $warn[] = 'Competenza «'.$r['month'].'» non riconosciuta: ignorata';
                }
            }

            $data = [
                'date' => $r['date'], 'type' => $r['type'], 'cents' => $r['cents'], 'account_id' => $accId, 'new_account' => $newName,
                'new_account_type' => $newName !== null ? $accType : null, 'account_ref' => $accRef, 'method' => $method, 'category_id' => $cat['id'],
                'category_kind' => $cat['kind'], 'person_id' => $personId, 'activity_id' => $activityId, 'description' => $desc, 'ref' => $r['ref'], 'month' => $month,
            ];
            $key = self::key((string) $r['date'], $accRef, (string) $r['type'], $r['cents'], $desc, $r['ref']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $plans[] = $seen[$key] <= ($existing[$key] ?? 0)
                ? ['row' => $r, 'action' => 'duplicate', 'message' => 'Già in prima nota: saltato', 'warnings' => $warn, 'data' => $data]
                : ['row' => $r, 'action' => 'create', 'message' => '', 'warnings' => $warn, 'data' => $data];
        }

        return self::pairTransfers($plans, $legs, $existing);
    }

    public static function methodFromText(string $n): ?string
    {
        if ($n === '') {
            return null;
        }
        $map = [
            'stripe' => ['stripe', 'cartaonline'], 'paypal' => ['paypal'], 'sumup' => ['sumup'], 'satispay' => ['satispay'],
            'cash' => ['contant', 'cash', 'cassa'], 'bank_transfer' => ['bonific', 'sepa', 'bancari', 'banca', 'rid', 'addebito'],
            'pos' => ['pos', 'carta', 'bancomat', 'visa', 'mastercard'], 'check' => ['assegn'],
        ];
        foreach ($map as $m => $words) {
            foreach ($words as $w) {
                if (str_starts_with($n, $w) || (strlen($w) > 4 && str_contains($n, $w))) {
                    return $m;
                }
            }
        }

        return $n === 'altro' || $n === 'other' ? 'other' : null;
    }

    public static function guessAccountType(string $n): string
    {
        if (str_contains($n, 'cassa') || str_contains($n, 'contant')) {
            return 'cash';
        }
        if (str_contains($n, 'pos')) {
            return 'pos';
        }

        return str_contains($n, 'paypal') || str_contains($n, 'stripe') ? 'other' : 'bank';
    }

    /**
     * Riepilogo per l'anteprima: quante righe per esito, entrate, uscite, variazione per conto, conti nuovi, periodo.
     *
     * @param  list<Plan>  $plans
     * @param  array<string, string>  $accountNames
     * @return array{counts: array<string, int>, income: int, expense: int, per_account: array<string, int>, new_accounts: list<string>, from: string|null, to: string|null}
     */
    public static function summary(array $plans, array $accountNames = []): array
    {
        $s = ['counts' => ['create' => 0, 'transfer' => 0, 'duplicate' => 0, 'skip' => 0, 'error' => 0, 'warn' => 0], 'income' => 0, 'expense' => 0, 'per_account' => [], 'new_accounts' => [], 'from' => null, 'to' => null];
        $new = [];
        $add = static function (?string $newName, mixed $id, int $delta) use (&$s, &$new, $accountNames): void {
            $name = $newName ?? ($accountNames[is_string($id) ? $id : ''] ?? '?');
            $s['per_account'][$name] = ($s['per_account'][$name] ?? 0) + $delta;
            if ($newName !== null) {
                $new[$newName] = true;
            }
        };
        foreach ($plans as $p) {
            $a = $p['action'];
            if (isset($s['counts'][$a])) {
                $s['counts'][$a]++;
            }
            if ($p['warnings'] !== []) {
                $s['counts']['warn']++;
            }
            $d = $p['data'];
            if ($a === 'create' && $d !== null) {
                $cents = is_int($d['cents']) ? $d['cents'] : 0;
                $s[$d['type'] === 'income' ? 'income' : 'expense'] += $cents;
                $add(is_string($d['new_account']) ? $d['new_account'] : null, $d['account_id'], $d['type'] === 'income' ? $cents : -$cents);
            } elseif ($a === 'transfer' && $d !== null && is_array($d['from']) && is_array($d['to'])) {
                $cents = is_int($d['cents']) ? $d['cents'] : 0;
                $add(is_string($d['from']['new']) ? $d['from']['new'] : null, $d['from']['id'], -$cents);
                $add(is_string($d['to']['new']) ? $d['to']['new'] : null, $d['to']['id'], $cents);
            }
            if (in_array($a, ['create', 'transfer'], true) && $d !== null && is_string($d['date'])) {
                $s['from'] = $s['from'] === null || $d['date'] < $s['from'] ? $d['date'] : $s['from'];
                $s['to'] = $s['to'] === null || $d['date'] > $s['to'] ? $d['date'] : $s['to'];
            }
        }
        $s['new_accounts'] = array_keys($new);

        return $s;
    }

    /**
     * Unisce le due righe di un giroconto (in uscita da un conto, in entrata su un altro, stessa data e importo).
     *
     * @param  list<Plan>  $plans
     * @param  array<int, array{date: string, type: string, cents: int, id: string|null, new: string|null, new_type: string|null, ref: string, method: string, desc: string, line: int}>  $legs
     * @param  array<string, int>  $existing
     * @return list<Plan>
     */
    private static function pairTransfers(array $plans, array $legs, array $existing): array
    {
        $open = $seen = [];
        foreach ($legs as $i => $d) {
            $opp = $d['type'] === 'transfer_out' ? 'transfer_in' : 'transfer_out';
            $k = $d['date'].'|'.$d['cents'];
            $match = null;
            foreach ($open[$opp][$k] ?? [] as $j) {
                if ($legs[$j]['ref'] !== $d['ref']) {
                    $match = $j;
                    break;
                }
            }
            if ($match === null) {
                $open[$d['type']][$k][] = $i;

                continue;
            }
            $open[$opp][$k] = array_values(array_diff($open[$opp][$k], [$match]));
            [$outI, $inI] = $d['type'] === 'transfer_out' ? [$i, $match] : [$match, $i];
            $out = $legs[$outI];
            $in = $legs[$inI];
            $description = $out['desc'] !== '' ? $out['desc'] : $in['desc'];
            $key = self::key($out['date'], $out['ref'].'>'.$in['ref'], 'transfer', $out['cents'], $description, '');
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $duplicate = $seen[$key] <= ($existing[$key] ?? 0);
            $plans[$outI] = [
                'row' => $plans[$outI]['row'], 'warnings' => $plans[$outI]['warnings'],
                'action' => $duplicate ? 'duplicate' : 'transfer',
                'message' => $duplicate ? 'Giroconto già in prima nota: saltato' : 'Giroconto (accoppiato alla riga '.$in['line'].')',
                'data' => [
                    'date' => $out['date'], 'cents' => $out['cents'], 'method' => $out['method'], 'description' => $description,
                    'from' => ['id' => $out['id'], 'new' => $out['new'], 'type' => $out['new_type'], 'ref' => $out['ref']],
                    'to' => ['id' => $in['id'], 'new' => $in['new'], 'type' => $in['new_type'], 'ref' => $in['ref']],
                ],
            ];
            $plans[$inI] = ['row' => $plans[$inI]['row'], 'warnings' => $plans[$inI]['warnings'], 'action' => 'paired', 'message' => 'Parte del giroconto della riga '.$out['line'], 'data' => null];
        }
        foreach ($plans as $i => $p) {
            if ($p['action'] === 'transfer_leg') {
                $plans[$i] = ['row' => $p['row'], 'warnings' => $p['warnings'], 'action' => 'skip', 'data' => null,
                    'message' => 'Giroconto senza la riga corrispondente (stessa data e importo su un altro conto): non importato, registralo da «Giroconto»'];
            }
        }

        return $plans;
    }

    /**
     * @param  array<string, list<array{id: string, name: string, kind: string}>>  $byName
     * @param  array<string, list<array{id: string, name: string, kind: string}>>  $byKind
     * @return array{id: string, kind: string, name: string}|null
     */
    private static function resolveCategory(string $text, bool $income, array $byName, array $byKind): ?array
    {
        $n = Text::normalize($text);
        if ($n === '') {
            return null;
        }
        $ok = static function (string $kind) use ($income): bool {
            $k = CategoryKind::tryFrom($kind);

            return $k !== null && ($income ? $k->isIncome() : $k->isExpense());
        };
        foreach ($byName[$n] ?? [] as $c) {
            if ($ok($c['kind'])) {
                return ['id' => $c['id'], 'kind' => $c['kind'], 'name' => $c['name']];
            }
        }
        foreach (self::KEYWORDS as $kind => $words) {
            if (! $ok($kind) || ! isset($byKind[$kind][0])) {
                continue;
            }
            foreach ($words as $w) {
                if (str_contains($n, $w)) {
                    $c = $byKind[$kind][0];

                    return ['id' => $c['id'], 'kind' => $kind, 'name' => $c['name']];
                }
            }
        }

        return null;
    }

    private static function cleanCard(string $raw): string
    {
        $raw = trim($raw);

        return preg_match('/^(\d+)[.,]0+$/', $raw, $m) === 1 ? $m[1] : $raw;
    }
}
