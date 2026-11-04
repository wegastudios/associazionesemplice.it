<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Receipts\ReceiptMail;
use App\Modules\Contabilita\Receipts\ReceiptPdf;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** Ricevute: il PDF da scaricare o stampare, e l'invio per email a chi ha pagato. */
final readonly class ReceiptsController
{
    public function __construct(
        private ReceiptPdf $receipts,
        private AuditLog $audit,
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function pdf(string $receipt): Response
    {
        try {
            $r = $this->receipts->build($receipt);
        } catch (LedgerError $e) {
            abort(404, $e->getMessage());
        }
        $this->audit->record('receipt.pdf', 'app.receipts', $receipt, ['number' => $r['number']]);

        return new Response($r['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$r['filename'].'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function send(string $receipt): RedirectResponse
    {
        try {
            $r = $this->receipts->build($receipt);
        } catch (LedgerError $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
        if ($r['email'] === null) {
            throw ValidationException::withMessages(['ledger' => 'Chi ha pagato non ha un\'email in anagrafica: scarica la ricevuta e consegnala a mano.']);
        }
        $org = $this->db->connection($this->context->require()->connection)->table('app.orgs')->value('name');
        Mail::to($r['email'])->send(new ReceiptMail(is_string($org) ? $org : '', $r['number'], $r['holder'], $r['pdf'], $r['filename']));
        $this->audit->record('receipt.sent', 'app.receipts', $receipt, ['number' => $r['number']]);

        return back()->with('status', 'Ricevuta n. '.$r['number'].' mandata a '.$r['email'].'.');
    }
}
