<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Receipts;

use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\MemberReceipts;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

final readonly class MemberReceiptsService implements MemberReceipts
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private ReceiptPdf $pdf,
    ) {}

    public function forPeople(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $rows = $this->mine($personIds)->orderByDesc('r.issued_on')->orderByDesc('r.number')->limit(200)
            ->get(['r.id', 'r.number', 'r.year', 'r.issued_on', 'r.total_cents']);
        $descriptions = [];
        foreach ($this->connection()->table('app.transactions')->whereIn('receipt_id', $rows->pluck('id'))->whereNull('voided_at')->orderBy('created_at')->get(['receipt_id', 'description']) as $t) {
            if (is_string($t->receipt_id ?? null) && is_string($t->description ?? null)) {
                $descriptions[$t->receipt_id][] = $t->description;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            if (is_string($r->id ?? null) && is_numeric($r->number ?? null) && is_numeric($r->year ?? null) && is_string($r->issued_on ?? null)) {
                $out[] = ['id' => $r->id, 'number' => $r->number.'/'.$r->year, 'date' => date('d/m/Y', (int) strtotime($r->issued_on)),
                    'total' => is_numeric($r->total_cents ?? null) ? (int) $r->total_cents : 0, 'description' => implode(', ', array_unique($descriptions[$r->id] ?? []))];
            }
        }

        return $out;
    }

    public function pdf(string $receiptId, array $personIds): ?array
    {
        if ($personIds === [] || ! Str::isUuid($receiptId) || ! $this->mine($personIds)->where('r.id', $receiptId)->exists()) {
            return null;
        }
        try {
            $doc = $this->pdf->build($receiptId);
        } catch (LedgerError) {
            return null;
        }

        return ['pdf' => $doc['pdf'], 'filename' => $doc['filename']];
    }

    /** @param  list<string>  $personIds */
    private function mine(array $personIds): Builder
    {
        return $this->connection()->table('app.receipts as r')->whereNull('r.voided_at')
            ->where(static fn (Builder $w) => $w->whereIn('r.payer_person_id', $personIds)
                ->orWhereExists(static fn (Builder $e) => $e->selectRaw('1')->from('app.transactions as t')->whereColumn('t.receipt_id', 'r.id')->whereIn('t.person_id', $personIds)));
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
