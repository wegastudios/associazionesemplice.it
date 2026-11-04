<?php

declare(strict_types=1);

use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Receipts\ReceiptMail;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\GuardianService;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->user = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->user, OrgRole::Operator);
});

it('produce la ricevuta in PDF con i dati dell\'ente e di chi ha pagato', function (): void {
    $receiptId = $this->orgContext()->run($this->org->id, function (): string {
        $s = app(OrgSettings::class);
        $s->set('ente.tax_code', '12345678903');
        $s->set('ente.city', 'Roma');
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'email' => 'mario@example.com', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000);

        return (string) DB::table('app.receipts')->value('id');
    });

    $res = $this->actingAs($this->user)->get("/gestionale/ricevute/{$receiptId}.pdf")->assertOk()
        ->assertHeader('content-type', 'application/pdf')->assertHeader('cache-control', 'no-store, private');
    $pdf = (string) $res->getContent();
    $year = now()->format('Y');
    expect($pdf)->toStartWith('%PDF-1.4')->toContain('Polisportiva Alfa')->toContain('Codice fiscale: 12345678903')
        ->toContain('RICEVUTA DI PAGAMENTO')->toContain("N. 1/{$year}")->toContain('Mario Rossi')->toContain('Codice fiscale: RSSMRA80A01H501U')
        ->toContain("Quota associativa {$year}")->toContain('Pagato con: Contanti');
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('audit.log')->where('action', 'receipt.pdf')->exists())->toBeTrue());
});

it('intesta la ricevuta del minore al genitore, con la nota sulla detrazione', function (): void {
    $receiptId = $this->orgContext()->run($this->org->id, function (): string {
        $kid = Person::create(['first_name' => 'Luca', 'last_name' => 'Rossi', 'tax_code' => 'RSSLCU14A01H501X', 'birth_date' => now()->subYears(10)->toDateString()]);
        $mum = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi', 'email' => 'anna@example.com', 'tax_code' => 'VRDGPP75B10F205B', 'birth_date' => '1985-01-01']);
        app(GuardianService::class)->link($kid->id, $mum->id);
        $s = app(ActivityService::class);
        $yoga = $s->save(null, ['name' => 'Judo', 'kind' => 'course', 'social_year' => now()->format('Y'), 'billing' => 'monthly', 'fee_cents' => 3000]);
        $s->enroll($yoga->id, $kid->id, now()->format('Y-m'));
        $s->collect($yoga->id, $kid->id, Account::query()->sole()->id, now()->toDateString(), [now()->format('Y-m')], null, PaymentMethod::BankTransfer);

        return (string) DB::table('app.receipts')->value('id');
    });

    $pdf = (string) $this->actingAs($this->user)->get("/gestionale/ricevute/{$receiptId}.pdf")->assertOk()->getContent();
    expect($pdf)->toContain('Anna Rossi')->toContain('Codice fiscale: VRDGPP75B10F205B')->toContain('Per conto di Luca Rossi')
        ->toContain('RSSLCU14A01H501X')->toContain('Pagamento tracciato')->toContain('Contributo Judo');

    Mail::fake();
    $this->post("/gestionale/ricevute/{$receiptId}/invia")->assertSessionHas('status', 'Ricevuta n. 1/'.now()->format('Y').' mandata a anna@example.com.');
    Mail::assertSent(ReceiptMail::class, fn (ReceiptMail $m): bool => $m->hasTo('anna@example.com') && count($m->attachments()) === 1);
});

it('non dà le ricevute stornate né le manda senza email', function (): void {
    [$receiptId, $other] = $this->orgContext()->run($this->org->id, function (): array {
        $cash = Account::query()->sole()->id;
        $p = Person::create(['first_name' => 'Senza', 'last_name' => 'Email']);
        app(MembershipService::class)->renewWithPayment($p->id, $cash, 3000);
        $q = Person::create(['first_name' => 'Altro', 'last_name' => 'Socio']);
        app(MembershipService::class)->renewWithPayment($q->id, $cash, 3000);
        $first = (string) DB::table('app.receipts')->where('number', 1)->value('id');
        app(Ledger::class)->void((string) DB::table('app.transactions')->where('person_id', $q->id)->value('id'), 'errore');

        return [$first, (string) DB::table('app.receipts')->where('number', 2)->value('id')];
    });

    $this->actingAs($this->user)->post("/gestionale/ricevute/{$receiptId}/invia")->assertSessionHasErrors('ledger');
    $this->get("/gestionale/ricevute/{$other}.pdf")->assertNotFound();

    $acc = $this->createUser('acc@example.com', twoFactor: true);
    $this->addOperator($this->org, $acc, OrgRole::Accountant);
    $this->actingAs($acc)->get("/gestionale/ricevute/{$receiptId}.pdf")->assertOk();
    $this->post("/gestionale/ricevute/{$receiptId}/invia")->assertForbidden();
});
