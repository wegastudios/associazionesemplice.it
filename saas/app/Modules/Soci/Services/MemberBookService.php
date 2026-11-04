<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\Pdf;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Libro soci (dal MemberBook del plugin): l'elenco progressivo di tutti i soci in ordine di ingresso, con domanda,
 * ammissione, cessazione e l'ultima quota versata. Ospiti e genitori non soci non ci sono.
 *
 * @phpstan-type Row array{n: int, id: string, name: string, tax_code: string, birth: string, residence: string, email: string, phone: string, card: string, level: string, applied_on: string, approved_on: string, joined_on: string, left_on: string, left_reason: string, fee: string, fee_year: string, guardian: string, in_force: bool}
 */
final readonly class MemberBookService
{
    public const IN_FORCE = 'in_force';

    public const LEFT = 'left';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private AuditLog $audit,
    ) {}

    /** @return list<Row> */
    public function rows(string $filter = ''): array
    {
        $people = Person::query()->whereIn('type', ['founder', 'ordinary', 'volunteer'])
            ->orderByRaw('coalesce(joined_on, created_at::date)')->orderBy('id')->get();
        $applications = $this->applications();
        $fees = $this->lastFees();
        $guardians = $this->guardians();
        $levels = MemberLevel::query()->get(['id', 'name'])->mapWithKeys(static fn (MemberLevel $l): array => [$l->id => $l->name])->all();

        $out = [];
        $n = 0;
        foreach ($people as $p) {
            $n++;
            $gone = $p->left_on !== null;
            if (($filter === self::IN_FORCE && $gone) || ($filter === self::LEFT && ! $gone)) {
                continue;
            }
            $anon = $p->anonymized_at !== null;
            $joined = $p->joined_on?->format('Y-m-d') ?? $p->created_at?->format('Y-m-d') ?? '';
            $app = $applications[$p->id] ?? null;
            $birth = $anon ? '' : trim(($p->birth_date?->format('d/m/Y') ?? '').($p->birth_place !== null && $p->birth_place !== '' ? ' '.$p->birth_place : ''));
            $out[] = [
                'n' => $n,
                'id' => $p->id,
                'name' => $anon ? '(dati anonimizzati)' : trim($p->last_name.' '.$p->first_name),
                'tax_code' => $anon ? '' : (string) $p->tax_code,
                'birth' => $birth,
                'residence' => $anon || ! is_array($p->address) || ! is_string($p->address['text'] ?? null) ? '' : $p->address['text'],
                'email' => $anon ? '' : (string) $p->email,
                'phone' => $anon ? '' : (string) $p->phone,
                'card' => (string) $p->card_number,
                'level' => $p->level_id !== null ? ($levels[$p->level_id] ?? '') : '',
                'applied_on' => self::date($app['submitted'] ?? $joined),
                'approved_on' => self::date($app['decided'] ?? $joined),
                'joined_on' => self::date($joined),
                'left_on' => $gone ? self::date($p->left_on?->format('Y-m-d') ?? '') : '',
                'left_reason' => (string) $p->left_reason,
                'fee' => isset($fees[$p->id]) ? Money::format($fees[$p->id]['cents']) : '',
                'fee_year' => $fees[$p->id]['year'] ?? '',
                'guardian' => $anon ? '' : ($guardians[$p->id] ?? ''),
                'in_force' => ! $gone,
            ];
        }

        return $out;
    }

    /** @param  list<Row>  $rows */
    public function pdf(array $rows, string $orgName, string $filter): string
    {
        $pdf = new Pdf(landscape: true);
        $cols = [
            ['N.', 30, 'R'], ['Socio e codice fiscale', 166, 'L'], ['Nascita', 104, 'L'], ['Residenza', 150, 'L'],
            ['Tessera', 52, 'L'], ['Ammissione', 80, 'L'], ['Cessazione', 100, 'L'], ['Ultima quota', 70, 'R'],
        ];
        $title = 'Libro soci'.match ($filter) {
            self::IN_FORCE => ' — soci in carica',
            self::LEFT => ' — soci cessati',
            default => '',
        };
        $page = 1;
        $head = function () use ($pdf, $cols, $title, $orgName, &$page): float {
            $pdf->text(40, 40, $orgName, 13, true);
            $pdf->text(40, 56, $title, 11);
            $pdf->text($pdf->width - 40, 40, 'Stampato il '.now()->format('d/m/Y').' · pagina '.$page, 9, false, 'R');
            $x = 40;
            $pdf->rect(36, 66, $pdf->width - 72, 18);
            foreach ($cols as [$label, $w, $align]) {
                $pdf->text($align === 'R' ? $x + $w - 4 : $x, 78, $label, 8, true, $align === 'R' ? 'R' : 'L');
                $x += $w;
            }

            return 98;
        };
        $y = $head();
        foreach ($rows as $r) {
            $cells = [
                [(string) $r['n'], ''],
                [$r['name'], $r['tax_code']],
                [$r['birth'], ''],
                [$r['residence'], $r['guardian'] !== '' ? 'Tutore: '.$r['guardian'] : ''],
                [$r['card'], $r['level']],
                [$r['approved_on'], $r['approved_on'] !== $r['applied_on'] ? 'domanda '.$r['applied_on'] : ''],
                [$r['left_on'], $r['left_reason']],
                [$r['fee'], $r['fee_year']],
            ];
            if ($y > $pdf->height - 50) {
                $pdf->addPage();
                $page++;
                $y = $head();
            }
            $x = 40;
            foreach ($cols as $i => [, $w, $align]) {
                [$top, $bottom] = $cells[$i];
                $tx = $align === 'R' ? $x + $w - 4 : $x;
                $a = $align === 'R' ? 'R' : 'L';
                $pdf->text($tx, $y, Pdf::fit($top, $w - 6, 8.5), 8.5, $i === 1, $a);
                if ($bottom !== '') {
                    $pdf->text($tx, $y + 10, Pdf::fit($bottom, $w - 6, 7.5), 7.5, false, $a);
                }
                $x += $w;
            }
            $pdf->line(36, $y + 15, $pdf->width - 36, $y + 15, 0.3);
            $y += 24;
        }
        if ($rows === []) {
            $pdf->text(40, $y, 'Nessun socio.', 10);
        }

        return $pdf->output();
    }

    /** @param  list<Row>  $rows */
    public function csv(array $rows): string
    {
        $out = fopen('php://temp', 'w+b');
        if ($out === false) {
            return '';
        }
        fwrite($out, "\xEF\xBB\xBF");
        self::line($out, ['N.', 'Cognome e nome', 'Codice fiscale', 'Nascita', 'Residenza', 'Email', 'Cellulare', 'Tessera', 'Livello', 'Domanda', 'Ammissione', 'Ingresso', 'Cessazione', 'Motivo', 'Ultima quota', 'Anno', 'Tutore']);
        foreach ($rows as $r) {
            self::line($out, [(string) $r['n'], $r['name'], $r['tax_code'], $r['birth'], $r['residence'], $r['email'], $r['phone'], $r['card'], $r['level'],
                $r['applied_on'], $r['approved_on'], $r['joined_on'], $r['left_on'], $r['left_reason'], $r['fee'], $r['fee_year'], $r['guardian']]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param  resource  $out
     * @param  list<string>  $cells
     */
    private static function line($out, array $cells): void
    {
        fputcsv($out, array_map(static fn (string $c): string => PeopleExportService::cell($c), $cells), ';', '"', '', "\r\n");
    }

    public function logExport(string $format, int $rows): void
    {
        $this->audit->record('member_book.exported', 'app.people', null, ['format' => $format, 'rows' => $rows]);
    }

    /** @return array<string, array{submitted: string, decided: string}> domande accolte, per persona */
    private function applications(): array
    {
        $out = [];
        foreach ($this->connection()->table('app.applications')->where('status', 'approved')->whereNotNull('person_id')->get(['person_id', 'submitted_at', 'decided_at']) as $a) {
            if (is_string($a->person_id ?? null) && is_string($a->submitted_at ?? null) && is_string($a->decided_at ?? null)) {
                $out[$a->person_id] = ['submitted' => substr($a->submitted_at, 0, 10), 'decided' => substr($a->decided_at, 0, 10)];
            }
        }

        return $out;
    }

    /** @return array<string, array{year: string, cents: int}> l'ultima quota incassata (non stornata), per persona */
    private function lastFees(): array
    {
        $out = [];
        $rows = $this->connection()->table('app.valid_memberships as m')->join('app.transactions as t', 't.id', '=', 'm.transaction_id')
            ->where('m.source', 'payment')->orderBy('m.valid_from')->get(['m.person_id', 'm.social_year', 't.amount_cents']);
        foreach ($rows as $r) {
            if (is_string($r->person_id ?? null) && is_string($r->social_year ?? null) && is_numeric($r->amount_cents ?? null)) {
                $out[$r->person_id] = ['year' => $r->social_year, 'cents' => (int) $r->amount_cents];
            }
        }

        return $out;
    }

    /** @return array<string, string> minore => nome del tutore operativo */
    private function guardians(): array
    {
        $out = [];
        $rows = $this->connection()->table('app.guardians as g')->join('app.people as p', 'p.id', '=', 'g.guardian_person_id')
            ->where('g.is_operative', true)->get(['g.person_id', 'p.first_name', 'p.last_name']);
        foreach ($rows as $r) {
            if (is_string($r->person_id ?? null)) {
                $out[$r->person_id] = trim((is_string($r->last_name ?? null) ? $r->last_name : '').' '.(is_string($r->first_name ?? null) ? $r->first_name : ''));
            }
        }

        return $out;
    }

    private static function date(string $ymd): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) === 1 ? "{$m[3]}/{$m[2]}/{$m[1]}" : '';
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
