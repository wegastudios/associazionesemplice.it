<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Jobs\SendReceiptMail;
use App\Modules\Contabilita\Receipts\ReceiptFileProblem;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\OrgContacts;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Ricevute: il PDF da scaricare o stampare, e l'invio per email a chi ha pagato. */
final readonly class ReceiptsController
{
    public function __construct(
        private ReceiptFiles $receipts,
        private AuditLog $audit,
        private OrgContext $context,
        private OrgContacts $contacts,
    ) {}

    public function pdf(string $receipt): Response
    {
        try {
            $r = $this->receipts->document($receipt);
        } catch (LedgerError $e) {
            abort(404, $e->getMessage());
        } catch (ReceiptFileProblem $e) {
            return new Response($e->getMessage(), 409, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store, private']);
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
            $r = $this->receipts->document($receipt);
        } catch (LedgerError|ReceiptFileProblem $e) {
            throw ValidationException::withMessages(['ledger' => $e->getMessage()]);
        }
        if ($r['email'] === null) {
            throw ValidationException::withMessages(['ledger' => 'Chi ha pagato non ha un\'email in anagrafica: scarica la ricevuta e consegnala a mano.']);
        }
        $contacts = $this->contacts->all();
        if ($contacts === null) {
            throw ValidationException::withMessages(['ledger' => 'Per inviare la ricevuta per email servono email e telefono della segreteria: inseriscili in Altro › Configurazione guidata › Dati dell\'ente. Intanto scaricala e consegnala a mano.']);
        }
        // Subito, contando sulla quota dell'ora; con la posta ferma o la quota finita (o se il server rifiuta) va in coda.
        $org = $this->context->require();
        $to = $r['email'];
        if (MailQueue::sendNowOrQueue(new SendReceiptMail($org->id, $receipt), static fn () => SendReceiptMail::send($receipt, $to, $r, $contacts, SendReceiptMail::orgName($org)))) {
            return back()->with('status', 'Ricevuta n. '.$r['number'].' mandata a '.$to.'.');
        }

        return back()->with('status', 'Ricevuta n. '.$r['number'].' in coda: la posta in uscita è in pausa o ha raggiunto il limite dell\'ora, parte da sola appena possibile a '.$to.'.');
    }
}
