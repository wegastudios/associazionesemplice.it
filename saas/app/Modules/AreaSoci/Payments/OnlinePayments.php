<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Payments;

use App\Modules\AreaSoci\Jobs\SendPaymentMail;
use App\Modules\AreaSoci\Jobs\SettleOnlinePayment;
use App\Modules\AreaSoci\Mail\DonationReceiptMail;
use App\Modules\AreaSoci\Mail\PaymentRefunded;
use App\Modules\AreaSoci\Models\PaymentSettings;
use App\Modules\AreaSoci\Services\MemberAccessError;
use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\MemberReceipts;
use App\Modules\Contabilita\Contracts\ReceiptHold;
use App\Modules\Core\Contracts\OrgContacts;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDonations;
use App\Modules\Core\Contracts\PayableDues;
use App\Modules\Soci\Contracts\MemberProfiles;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Pagamenti online dei soci (dal PaymentService del plugin). Il socio sceglie le voci dovute (ricalcolate qui), paga su
 * Stripe o PayPal con il conto dell'associazione; all'incasso confermato (ritorno dal pagamento o webhook di Stripe)
 * ogni voce si registra in prima nota con la sua ricevuta, una volta sola. Se qualcosa non torna (importo diverso, voce
 * non più dovuta) il pagamento resta «da controllare» per la segreteria.
 *
 * @phpstan-import-type Due from PayableDues
 *
 * @phpstan-type Payment array{id: string, person_id: string, provider: string, status: string, amount_cents: int, paid_cents: int|null, items: list<Due>, receipts: list<string>, held: list<list<string>>, provider_ref: string|null, error: string|null, refunded_cents: int, created_at: string}
 */
final readonly class OnlinePayments
{
    public const MIN_CENTS = 50;

    /** Dopo «rimborsa e storna»: la voce non sparisce, torna da pagare. Frasi mostrate in gestionale (conferma ed esito). */
    public const STAYS_RESERVATION = 'La prenotazione resta valida e torna da pagare: se non serve più, annullala dalla scheda della risorsa.';

    public const STAYS_ENROLLMENT = "L'iscrizione resta e il contributo torna da pagare: se non serve più, chiudi l'iscrizione dalla scheda dell'attività.";

    /** Frasi dell'email al socio, in coda al rimborso. */
    public const MAIL_RESERVATION = 'La tua prenotazione resta attiva e risulta da pagare: se non ti serve più, avvisa la segreteria.';

    public const MAIL_ENROLLMENT = 'La tua iscrizione resta attiva e il contributo risulta da pagare: se non ti serve più, avvisa la segreteria.';

    public const MAX_PER_HOUR = 10;

    /** Secondi tra due richieste al servizio di pagamento per lo stesso pagamento ancora in attesa. */
    public const CHECK_SECONDS = 15;

    public function __construct(
        private Cache $cache,
        private DatabaseManager $db,
        private OrgContext $context,
        private Container $app,
        private StripeGateway $stripe,
        private PayPalGateway $paypal,
        private AuditLog $audit,
        private Ledger $ledger,
        private MemberProfiles $profiles,
        private OrgDonations $donations,
    ) {}

    public function settings(): ?PaymentSettings
    {
        return PaymentSettings::query()->first();
    }

    /** @return list<string> */
    public function providers(): array
    {
        return $this->settings()?->providers() ?? [];
    }

    /**
     * Le voci dovute ora, da tutti i moduli.
     *
     * @param  list<string>  $personIds
     * @return list<Due>
     */
    public function dues(array $personIds): array
    {
        $out = [];
        foreach ($this->modules() as $m) {
            array_push($out, ...$m->dues($personIds));
        }

        return $out;
    }

    /**
     * Crea il pagamento e restituisce la pagina del servizio di pagamento a cui mandare il socio.
     *
     * @param  list<string>  $personIds  le schede che il socio può pagare (la sua e quelle dei figli)
     * @param  list<string>  $keys
     */
    public function checkout(string $payerId, array $personIds, array $keys, string $provider, ?string $email, string $baseUrl, string $brand, int $donationCents = 0, ?string $publicSlug = null): string
    {
        $settings = $this->settings();
        if ($settings === null || ! in_array($provider, $settings->providers(), true)) {
            throw new MemberAccessError('Questo metodo di pagamento non è attivo.');
        }
        $recent = $this->table()->where('person_id', $payerId)->where('created_at', '>', now()->subHour())->count();
        if ($recent >= self::MAX_PER_HOUR) {
            throw new MemberAccessError('Hai avviato molti pagamenti in poco tempo: riprova tra qualche minuto.');
        }
        $dues = [];
        foreach ($this->dues($personIds) as $d) {
            $dues[$d['key']] = $d;
        }
        $items = [];
        foreach (array_unique($keys) as $k) {
            if (isset($dues[$k])) {
                $items[] = $dues[$k];
            }
        }
        if ($donationCents > 0) {
            // «Aggiungi X € per una donazione»: importo scelto da chi paga, voce a parte (erogazione liberale, senza commissioni a suo carico).
            if (! $this->donations->checkout()) {
                throw new MemberAccessError('La donazione non è attiva.');
            }
            if ($donationCents < OrgDonations::MIN_CENTS || $donationCents > OrgDonations::MAX_CENTS) {
                throw new MemberAccessError('La donazione può andare da 1 € a 1.000 €.');
            }
            $items[] = ['key' => 'd:'.$payerId, 'person_id' => $payerId, 'group' => 'Donazione', 'label' => 'Donazione a '.$brand, 'amount_cents' => $donationCents, 'donation' => true, 'public' => $publicSlug !== null];
        }
        if ($items === []) {
            throw new MemberAccessError('Scegli almeno una voce da pagare.');
        }
        $total = (int) array_sum(array_column($items, 'amount_cents'));
        if ($total < self::MIN_CENTS) {
            throw new MemberAccessError('L\'importo minimo per pagare online è 0,50 €.');
        }

        $id = Str::uuid7()->toString();
        $this->table()->insert(['org_id' => $this->context->require()->id, 'id' => $id, 'person_id' => $payerId, 'provider' => $provider,
            'amount_cents' => $total, 'items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        // Dalla pagina pubblica di donazione chi paga non ha l'area soci: torna a una pagina pubblica.
        $prefix = $publicSlug === null ? '/area/pagamenti/'.$id : '/dona/'.$publicSlug.'/'.$id;
        $success = rtrim($baseUrl, '/').$prefix.'/esito';
        $cancel = rtrim($baseUrl, '/').$prefix.'/annullato';
        $lines = array_map(static fn (array $i): array => ['label' => $i['label'], 'amount_cents' => $i['amount_cents'], 'donation' => $i['donation'] ?? false], $items);
        try {
            if ($provider === 'stripe') {
                $res = $this->stripe->createSession((string) $settings->stripe_secret, StripeGateway::checkoutParams($lines, $id, $email, $success.'?session_id={CHECKOUT_SESSION_ID}', $cancel), $id);
            } else {
                $token = $this->paypal->token($settings->paypal_live, (string) $settings->paypal_client_id, (string) $settings->paypal_secret);
                $res = $this->paypal->createOrder($settings->paypal_live, $token, PayPalGateway::orderPayload($lines, $id, $success, $cancel, $brand), $id);
            }
        } catch (RuntimeException $e) {
            $this->table()->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
            $this->audit->record('payment.failed', 'app.online_payments', $id, ['provider' => $provider]);
            throw new MemberAccessError('Il servizio di pagamento non risponde in questo momento. Riprova più tardi.');
        }
        $this->table()->where('id', $id)->update(['status' => 'pending', 'provider_ref' => $res['id'], 'updated_at' => now()]);
        $this->audit->record('payment.created', 'app.online_payments', $id, ['provider' => $provider, 'cents' => $total, 'items' => count($items)]);

        return $res['url'];
    }

    /**
     * Il socio torna dal pagamento: si chiede al servizio se è andato a buon fine e, se sì, si registra. Un pagamento già
     * definitivo si legge solo dal database; uno ancora in attesa si chiede al servizio al massimo ogni 15 secondi
     * (aperture ravvicinate della pagina mostrano lo stato di prima).
     *
     * @return Payment|null
     */
    public function confirm(string $paymentId, string $personId): ?array
    {
        $p = $this->find($paymentId);
        if ($p === null || $p['person_id'] !== $personId) {
            return null;
        }
        if ($p['status'] !== 'pending' || $p['provider_ref'] === null) {
            return $p;
        }
        if (! $this->cache->add('online-payment-check|'.$p['id'], true, self::CHECK_SECONDS)) {
            return $p;
        }
        $settings = $this->settings();
        if ($settings === null) {
            return $p;
        }
        try {
            if ($p['provider'] === 'stripe') {
                $session = $this->stripe->retrieveSession((string) $settings->stripe_secret, $p['provider_ref']);
                if (StripeGateway::isPaid($session)) {
                    $this->settle($p['id'], StripeGateway::paidCents($session));
                }
            } else {
                $token = $this->paypal->token($settings->paypal_live, (string) $settings->paypal_client_id, (string) $settings->paypal_secret);
                $order = $this->paypal->capture($settings->paypal_live, $token, $p['provider_ref']);
                if (PayPalGateway::isCompleted($order)) {
                    $this->settle($p['id'], PayPalGateway::capturedCents($order));
                }
            }
        } catch (RuntimeException $e) {
            $this->table()->where('id', $p['id'])->update(['error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
        }

        return $this->find($paymentId);
    }

    /** Il socio ha rinunciato sulla pagina del servizio. */
    public function cancel(string $paymentId, string $personId): void
    {
        $this->table()->where('id', $paymentId)->where('person_id', $personId)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * Webhook di Stripe (H14): firma verificata con il segreto dell'associazione, evento di un nostro pagamento salvato una
     * volta sola (per il suo id) e incasso registrato in coda (SettleOnlinePayment). Risponde subito; gli eventi non
     * pagati o di sessioni non nostre si ignorano. Stessa sessione con eventi diversi: settle registra una volta sola.
     *
     * @return int codice HTTP: 200 ricevuto, 400 firma non valida, 503 coda non disponibile (Stripe riprova)
     */
    public function stripeWebhook(string $payload, string $signature): int
    {
        $secret = $this->settings()?->stripe_webhook;
        if ($secret === null || ! StripeWebhook::verify($payload, $signature, $secret, time())) {
            return 400;
        }
        $event = json_decode($payload, true);
        $session = is_array($event) && is_array($event['data'] ?? null) && is_array($event['data']['object'] ?? null) ? $event['data']['object'] : null;
        $type = is_array($event) ? ($event['type'] ?? '') : '';
        /** @var array<string, mixed>|null $session */
        if ($session === null || ! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) || ! StripeGateway::isPaid($session)) {
            return 200;
        }
        $ref = is_string($session['id'] ?? null) ? $session['id'] : '';
        $id = $this->table()->where('provider', 'stripe')->where('provider_ref', $ref)->value('id');
        if (! is_string($id)) {
            return 200;
        }
        // Stripe dà sempre un id all'evento; senza, vale l'impronta del contenuto firmato.
        $given = is_array($event) ? ($event['id'] ?? null) : null;
        $eventId = is_string($given) && $given !== '' ? mb_substr($given, 0, 255) : 'sha256:'.hash('sha256', $payload);

        return $this->queueSettlement('stripe', $eventId, $id, StripeGateway::paidCents($session)) ? 200 : 503;
    }

    /**
     * Salva l'evento (una volta sola) e mette in coda la registrazione. Un evento già ricevuto non rifà nulla, salvo che
     * il suo lavoro sia fallito del tutto: allora torna in coda. false = la coda non risponde (il gestore riproverà).
     */
    private function queueSettlement(string $provider, string $eventId, string $paymentId, int $cents): bool
    {
        $rowId = Str::uuid7()->toString();
        $new = $this->events()->insertOrIgnore(['org_id' => $this->context->require()->id, 'id' => $rowId, 'provider' => $provider, 'event_id' => $eventId,
            'payment_id' => $paymentId, 'paid_cents' => $cents]);
        if ($new === 0) {
            $failed = $this->events()->where('provider', $provider)->where('event_id', $eventId)->where('status', 'failed')->value('id');
            if (! is_string($failed) || $this->events()->where('id', $failed)->where('status', 'failed')->update(['status' => 'queued']) === 0) {
                return true;
            }
            $rowId = $failed;
        }
        try {
            Bus::dispatch(new SettleOnlinePayment($this->context->require()->id, $rowId));
        } catch (Throwable) {
            $this->events()->where('id', $rowId)->update(['status' => 'failed']);

            return false;
        }

        return true;
    }

    /**
     * Dal lavoro in coda (anche rilanciato a mano dopo «failed»): registra l'incasso dell'evento e lo segna fatto. Un
     * evento già fatto non rifà nulla.
     */
    public function settleEvent(string $eventId): void
    {
        $e = $this->events()->where('id', $eventId)->first(['payment_id', 'paid_cents', 'status']);
        $paymentId = $e->payment_id ?? null;
        $cents = $e->paid_cents ?? null;
        if (! in_array($e->status ?? null, ['queued', 'failed'], true) || ! is_string($paymentId) || ! is_numeric($cents)) {
            return;
        }
        $this->settle($paymentId, (int) $cents);
        $this->events()->where('id', $eventId)->update(['status' => 'done', 'processed_at' => now()]);
    }

    /** Il lavoro si è arreso: l'evento resta «failed» e il registro azioni lo dice. */
    public function eventFailed(string $eventId): void
    {
        $paymentId = $this->events()->where('id', $eventId)->where('status', 'queued')->value('payment_id');
        if (is_string($paymentId) && $this->events()->where('id', $eventId)->where('status', 'queued')->update(['status' => 'failed']) > 0) {
            $this->audit->record('payment.webhook_failed', 'app.online_payments', $paymentId, []);
        }
    }

    /**
     * Registra l'incasso, una volta sola: ogni voce in prima nota con la sua ricevuta.
     */
    public function settle(string $paymentId, int $paidCents): void
    {
        $mailTo = null;
        $this->connection()->transaction(function () use ($paymentId, $paidCents, &$mailTo): void {
            $this->table()->where('id', $paymentId)->lockForUpdate()->first(['id']);
            $p = $this->find($paymentId);
            if ($p === null || ! in_array($p['status'], ['created', 'pending', 'cancelled', 'failed'], true)) {
                return;
            }
            $settings = $this->settings();
            $errors = [];
            $receipts = [];
            $held = [];
            $already = [];
            $gone = [];
            if ($paidCents !== $p['amount_cents']) {
                $errors[] = 'Incassati '.self::euro($paidCents).' invece di '.self::euro($p['amount_cents']).': da controllare.';
            } elseif ($settings?->account_id === null) {
                $errors[] = 'Manca il conto degli incassi online nelle impostazioni.';
            } else {
                foreach ($p['items'] as $item) {
                    $module = $this->owner($item['key']);
                    try {
                        // Ogni voce nella sua transazione: una voce che non si registra non annulla le altre.
                        if ($module === null) {
                            throw new InvalidArgumentException('Voce non riconosciuta.');
                        }
                        // I soldi sono già arrivati sul conto del gestore: ogni voce si registra SEMPRE in prima nota (cassa e conto devono tornare).
                        // Se nel frattempo era già pagata o annullata (controllo solo per quota, mese di corso e prenotazione a evento) si segnala:
                        // il pagamento va da controllare e la segreteria decide se restituire.
                        // Chi paga un mese di corso e non risulta più iscritto: i soldi vanno comunque in prima nota sul contributo del corso.
                        $course = $module->notEnrolled($item['key']);
                        if ($course !== null) {
                            $gone[] = $course;
                            $this->audit->record('payment.item_not_enrolled', 'app.online_payments', $paymentId, ['key' => $item['key'], 'cents' => $item['amount_cents']]);
                        } elseif (preg_match('/^[qmb]:/', $item['key']) === 1 && ! self::stillDue($module, $item)) {
                            $already[$item['person_id']][] = $item['label'];
                            $this->audit->record('payment.item_already_paid', 'app.online_payments', $paymentId, ['key' => $item['key'], 'cents' => $item['amount_cents']]);
                        }
                        // Il movimento entra subito in prima nota, la ricevuta si emette dopo: subito se il pagamento è in ordine, altrimenti
                        // quando la segreteria lo segna «controllato».
                        // La donazione dalla pagina pubblica resta com'era: ricevuta subito, senza attendere il controllo.
                        $now = now('Europe/Rome')->toDateString();
                        $anyway = ! str_starts_with($item['key'], 'd:');
                        if ($item['public'] ?? false) {
                            $receipt = $this->connection()->transaction(fn (): ?string => $module->settle($item['key'], $item['amount_cents'], (string) $settings->account_id, $now, $p['provider'], $anyway));
                            if ($receipt !== null) {
                                $receipts[] = $receipt;
                            }
                        } else {
                            $ids = $this->connection()->transaction(fn (): array => ReceiptHold::run(fn (): ?string => $module->settle($item['key'], $item['amount_cents'], (string) $settings->account_id, $now, $p['provider'], $anyway)));
                            if ($ids !== []) {
                                $held[] = $ids;
                            }
                        }
                    } catch (Throwable $e) {
                        $errors[] = $item['label'].': '.($e instanceof InvalidArgumentException ? $e->getMessage() : 'non registrata');
                    }
                }
            }
            // Il testo elenca le voci già pagate del primo gruppo (per persona) che ne ha; gli altri gruppi non aggiungono voci, ma il pagamento resta da controllare.
            $first = array_values($already)[0] ?? [];
            $notes = $errors;
            if ($gone !== []) {
                $courses = array_values(array_unique($gone));
                $notes[] = (count($courses) === 1 ? 'Iscrizione terminata: ' : 'Iscrizioni terminate: ').implode(', ', $courses).'. L\'incasso è stato registrato comunque: controlla se va restituito.';
            }
            if ($first !== []) {
                $notes[] = (count($first) === 1 ? 'Già pagata: ' : 'Già pagate: ').implode(', ', $first).'. L\'incasso è stato registrato comunque: controlla se va restituito.';
            }
            // Pagamento in ordine: le ricevute si emettono subito. Da controllare: i movimenti restano in prima nota senza ricevuta
            // e la ricevuta nasce quando la segreteria segna il pagamento «controllato».
            if ($notes === [] && $held !== []) {
                [$issued, $left, $failure] = $this->issueReceipts($held);
                $receipts = [...$receipts, ...$issued];
                $held = $left;
                if ($failure !== null) {
                    $notes[] = 'Ricevuta non emessa: '.$failure;
                }
            }
            $this->table()->where('id', $paymentId)->update([
                'status' => $notes === [] ? 'settled' : 'review', 'paid_cents' => $paidCents, 'paid_at' => now(), 'updated_at' => now(),
                'receipts' => json_encode(array_values(array_unique($receipts)), JSON_THROW_ON_ERROR), 'error' => $notes === [] ? null : mb_substr(implode(' ', $notes), 0, 1000),
                'held_transactions' => json_encode($held, JSON_THROW_ON_ERROR),
            ]);
            $this->audit->record($notes === [] ? 'payment.settled' : 'payment.review', 'app.online_payments', $paymentId, ['cents' => $paidCents, 'receipts' => count($receipts)]);
            // Chi ha donato dalla pagina pubblica non ha l'area soci: la ricevuta gli arriva per email (come prima: contano solo gli errori veri).
            if ($errors === [] && $receipts !== [] && in_array(true, array_column($p['items'], 'public'), true)) {
                $mailTo = [$p['person_id'], array_values(array_unique($receipts))];
            }
        });
        if ($mailTo !== null) {
            $this->mailDonationReceipts($paymentId, $mailTo[0], $mailTo[1], $paidCents);
        }
    }

    /**
     * Emette le ricevute dei movimenti in attesa, una per gruppo (una per voce). Si ferma al primo errore.
     *
     * @param  list<list<string>>  $groups
     * @return array{0: list<string>, 1: list<list<string>>, 2: string|null} ricevute emesse, gruppi ancora in attesa, errore
     */
    private function issueReceipts(array $groups): array
    {
        $issued = [];
        foreach ($groups as $i => $group) {
            try {
                $label = $this->ledger->issueReceipt($group);
            } catch (LedgerError $e) {
                return [$issued, array_values(array_slice($groups, $i)), $e->getMessage()];
            }
            if ($label !== null) {
                $issued[] = $label;
            }
        }

        return [$issued, [], null];
    }

    /**
     * La ricevuta della donazione pubblica in PDF, a chi ha donato: in coda come posta massiva (SendPaymentMail), così oltre
     * il limite orario aspetta invece di perdersi. Se l'email non c'è o alla fine non parte resta nel registro azioni: la
     * segreteria la manda a mano dalla ricevuta.
     *
     * @param  list<string>  $numbers  «numero/anno»
     */
    private function mailDonationReceipts(string $paymentId, string $personId, array $numbers, int $cents): void
    {
        $this->queueMail(SendPaymentMail::receipts($this->context->require()->id, $paymentId, $personId, $cents, $numbers), 'receipt');
    }

    /**
     * Manda la ricevuta della donazione (dal lavoro in coda). Senza email lo scrive nel registro; un errore torna al lavoro,
     * che riprova.
     *
     * @param  list<string>  $numbers  «numero/anno»
     */
    public function deliverDonationReceipts(string $paymentId, string $personId, array $numbers, int $cents): void
    {
        $email = $this->emailOf($personId);
        if ($email === null) {
            $this->audit->record('payment.receipt_mail_skipped', 'app.online_payments', $paymentId, ['why' => 'no_email']);

            return;
        }
        $files = [];
        foreach ($numbers as $label) {
            if (preg_match('#^(\d+)/(\d{4})$#', $label, $m) !== 1) {
                continue;
            }
            $id = $this->connection()->table('app.receipts')->where('number', (int) $m[1])->where('year', (int) $m[2])->value('id');
            $pdf = is_string($id) ? $this->app->make(MemberReceipts::class)->pdf($id, [$personId]) : null;
            if ($pdf !== null) {
                $files[] = $pdf;
            }
        }
        if ($files === []) {
            throw new RuntimeException('Ricevuta non trovata.');
        }
        $contacts = $this->app->make(OrgContacts::class)->all();
        $org = $this->connection()->table('app.orgs')->value('name');
        $this->app->make(Mailer::class)->to($email)->send(new DonationReceiptMail(is_string($org) ? $org : '', $this->profiles->card($personId)['first_name'] ?? '', self::euro($cents), implode(', ', $numbers), $files, $contacts['email'] ?? null, $contacts['phone'] ?? null));
        $this->audit->record('payment.receipt_mail_sent', 'app.online_payments', $paymentId, []);
    }

    /** Mette in coda un'email del pagamento; se nemmeno la coda risponde resta nel registro azioni. true = in coda. */
    private function queueMail(SendPaymentMail $job, string $kind): bool
    {
        if ($this->emailOf($job->personId) === null) {
            $this->audit->record('payment.'.$kind.'_mail_skipped', 'app.online_payments', $job->paymentId, ['why' => 'no_email']);

            return false;
        }
        try {
            Bus::dispatch($job);
        } catch (Throwable) {
            $this->audit->record('payment.'.$kind.'_mail_skipped', 'app.online_payments', $job->paymentId, ['why' => 'queue_failed']);

            return false;
        }

        return true;
    }

    private function emailOf(string $personId): ?string
    {
        $email = $this->profiles->details($personId)['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Pagina pubblica di ritorno dalla donazione: si chiede al servizio com'è andata, come per il socio.
     *
     * @return Payment|null
     */
    public function confirmPublic(string $paymentId): ?array
    {
        $p = Str::isUuid($paymentId) ? $this->find($paymentId) : null;
        if ($p === null || ! in_array(true, array_column($p['items'], 'public'), true)) {
            return null;
        }

        return $this->confirm($paymentId, $p['person_id']);
    }

    /** Il donatore ha rinunciato sulla pagina del servizio. */
    public function cancelPublic(string $paymentId): void
    {
        $p = Str::isUuid($paymentId) ? $this->find($paymentId) : null;
        if ($p !== null && in_array(true, array_column($p['items'], 'public'), true)) {
            $this->cancel($paymentId, $p['person_id']);
        }
    }

    /**
     * @param  list<string>|null  $personIds  null = tutti (gestionale)
     * @return list<Payment>
     */
    public function list(?array $personIds, int $limit = 100): array
    {
        $q = $this->table()->orderByDesc('created_at')->limit($limit);
        if ($personIds !== null) {
            $q->whereIn('person_id', $personIds);
        }

        return array_values(array_filter(array_map(fn (object $r): ?array => $this->row($r), $q->get()->all())));
    }

    /**
     * Che cosa resta in piedi dopo uno storno: prenotazioni di risorse («r:») e iscrizioni ad attività o eventi
     * («m:», «e:», «b:»). Le quote associative no: lo storno annulla la tessera.
     *
     * @param  list<Due>  $items
     * @return array{reservation: bool, enrollment: bool}
     */
    public static function stays(array $items): array
    {
        $out = ['reservation' => false, 'enrollment' => false];
        foreach ($items as $i) {
            if (str_starts_with($i['key'], 'r:')) {
                $out['reservation'] = true;
            } elseif (preg_match('/^[meb]:/', $i['key']) === 1) {
                $out['enrollment'] = true;
            }
        }

        return $out;
    }

    /**
     * Le frasi di gestionale per quel pagamento (vuoto se non c'è nulla che resti in piedi).
     *
     * @param  list<Due>  $items
     */
    public static function staysNote(array $items): string
    {
        $s = self::stays($items);

        return trim(($s['reservation'] ? self::STAYS_RESERVATION : '').' '.($s['enrollment'] ? self::STAYS_ENROLLMENT : ''));
    }

    /**
     * Restituisce soldi al socio: l'ordine di rimborso parte verso Stripe o PayPal, resta nel registro e il socio riceve
     * un'email. Con $cents si restituisce una parte dei soldi non registrati di un pagamento «da controllare».
     * Con $cents null è il gesto unico per un pagamento già registrato: si stornano le sue ricevute in prima nota (che
     * annullano anche le tessere) e si restituisce tutto quello che resta; se lo storno non è possibile (anno fiscale
     * chiuso) non si rimborsa nulla, e se il rimborso non riesce anche lo storno viene annullato.
     */
    public function refund(string $paymentId, ?int $cents, string $reason, string $orgName): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new MemberAccessError('Scrivi il motivo del rimborso: lo leggerà il socio.');
        }
        $void = $cents === null;

        // 1. Breve: controlli e promemoria «rimborso in corso» (con la chiave che lo rende ripetibile senza doppioni).
        $plan = $this->connection()->transaction(function () use ($paymentId, $cents, $void): array {
            $row = $this->table()->where('id', $paymentId)->lockForUpdate()->first();
            $p = $row === null ? null : $this->find($paymentId);
            $allowed = $void ? ['settled', 'review'] : ['review'];
            if ($row === null || $p === null || ! in_array($p['status'], $allowed, true) || $p['provider_ref'] === null || $p['paid_cents'] === null) {
                throw new MemberAccessError($void ? 'Si rimborsano solo i pagamenti già incassati.' : 'Si rimborsano solo i pagamenti «da controllare» già incassati.');
            }
            $done = is_numeric($row->refunded_cents ?? null) ? (int) $row->refunded_cents : 0;
            $left = $p['paid_cents'] - $done;
            if ($void && $p['receipts'] === [] && $p['held'] === []) {
                throw new MemberAccessError('Questo pagamento non ha voci registrate: usa «rimborsa» con l\'importo.');
            }
            $amount = $void ? $left : (int) $cents;
            if ($amount < self::MIN_CENTS || $amount > $left) {
                throw new MemberAccessError('Importo non valido: si può restituire da 0,50 € a '.self::euro(max(0, $left)).'.');
            }
            $pending = is_numeric($row->refund_pending_cents ?? null) ? (int) $row->refund_pending_cents : null;
            if ($pending !== null && $pending !== $amount) {
                throw new MemberAccessError('C\'è un rimborso di '.self::euro($pending).' di cui non si sa l\'esito: controlla sul pannello di '.($p['provider'] === 'stripe' ? 'Stripe' : 'PayPal').' e ripeti quello stesso importo, senza cambiarlo.');
            }
            if ($void) {
                $this->assertVoidable($p['receipts'], $p['held']);
            }
            $key = 'refund-'.$paymentId.'-'.$done.'-'.$amount;
            $this->table()->where('id', $paymentId)->update(['refund_pending_cents' => $amount, 'refund_key' => $key, 'updated_at' => now()]);

            return ['p' => $p, 'done' => $done, 'amount' => $amount, 'key' => $key];
        });
        $p = $plan['p'];
        $amount = $plan['amount'];
        $providerRef = (string) $p['provider_ref'];

        // 2. La chiamata al gestore, senza transazione e senza blocchi sui conti.
        $settings = $this->settings() ?? throw new MemberAccessError('Mancano le impostazioni dei pagamenti online.');
        try {
            $refundId = $p['provider'] === 'stripe'
                ? $this->stripe->refund((string) $settings->stripe_secret, $providerRef, $amount, $plan['key'])
                : $this->paypal->refund($settings->paypal_live, $this->paypal->token($settings->paypal_live, (string) $settings->paypal_client_id, (string) $settings->paypal_secret), $providerRef, $amount, $plan['key']);
        } catch (ConnectionException|GatewayUnknown) {
            // Il gestore non ha risposto: il rimborso può essere partito. Non si annulla in silenzio: resta il promemoria, e
            // ripetendo lo stesso importo la chiave identica evita un secondo rimborso.
            $this->table()->where('id', $paymentId)->update(['error' => mb_substr(trim(($p['error'] ?? '').' Rimborso di '.self::euro($amount).': il gestore non ha risposto, esito da verificare.'), 0, 1000), 'updated_at' => now()]);
            $this->audit->record('payment.refund_unknown', 'app.online_payments', $paymentId, ['cents' => $amount, 'provider' => $p['provider']]);
            throw new MemberAccessError('Il gestore dei pagamenti non ha risposto: non si sa se il rimborso di '.self::euro($amount).' è partito, e nulla è stato annullato. Controlla sul pannello di '.($p['provider'] === 'stripe' ? 'Stripe' : 'PayPal').': se non risulta, ripeti il rimborso con lo stesso importo (non viene fatto due volte).');
        } catch (RuntimeException $e) {
            $this->table()->where('id', $paymentId)->update(['refund_pending_cents' => null, 'refund_key' => null, 'updated_at' => now()]);
            throw new MemberAccessError('Rimborso non riuscito: '.$e->getMessage().($void ? ' Nulla è stato annullato: ricevute e tessere sono come prima.' : ''));
        }

        // 3. Registrazione: storno delle ricevute (se richiesto), importo restituito, registro azioni.
        $voidError = null;
        $sent = $this->connection()->transaction(function () use ($paymentId, $plan, $p, $amount, $refundId, $reason, $void, &$voidError): ?array {
            $row = $this->table()->where('id', $paymentId)->lockForUpdate()->first();
            $done = $row !== null && is_numeric($row->refunded_cents ?? null) ? (int) $row->refunded_cents : 0;
            if ($row === null || $done !== $plan['done']) {
                return null; // già registrato da un'altra richiesta identica
            }
            if ($void) {
                try {
                    $this->voidReceipts($p['receipts'], $p['held'], $reason);
                } catch (MemberAccessError $e) {
                    $voidError = $e->getMessage();
                }
            }
            $note = 'Restituiti '.self::euro($amount).' al socio.'.($voidError !== null ? ' L\'annullamento in prima nota non è riuscito: fallo a mano.' : '');
            $update = ['refunded_cents' => $done + $amount, 'refunded_at' => now(), 'updated_at' => now(), 'refund_pending_cents' => null, 'refund_key' => null,
                'error' => mb_substr(trim(($p['error'] ?? '').' '.$note), 0, 1000)];
            if ($p['status'] === 'settled' || $p['receipts'] !== [] || $p['held'] !== []) {
                $update += ['status' => 'settled', 'reviewed_at' => now()];
            }
            if ($void && $voidError === null) {
                $update += ['held_transactions' => '[]']; // i movimenti in attesa di ricevuta sono stati stornati
            }
            $this->table()->where('id', $paymentId)->update($update);
            $this->audit->record('payment.refunded', 'app.online_payments', $paymentId, ['cents' => $amount, 'provider' => $p['provider'], 'refund' => $refundId, 'voided' => $void && $voidError === null]);

            return ['person_id' => $p['person_id'], 'cents' => $amount, 'date' => $p['created_at'],
                'stays' => $void && $voidError === null ? self::stays($p['items']) : ['reservation' => false, 'enrollment' => false]];
        });
        if ($sent === null) {
            return false;
        }
        $mailed = $this->tellMember($paymentId, $sent['person_id'], $sent['cents'], $sent['date'], $reason, $orgName, $sent['stays']);
        if ($voidError !== null) {
            throw new MemberAccessError('Il rimborso di '.self::euro($amount).' è stato fatto'.($mailed ? ' e il socio riceverà l\'avviso per email' : '').', ma l\'annullamento in prima nota no: '.$voidError.' Annullalo a mano dalla prima nota.'
                .($mailed ? '' : ' Il socio non ha un\'email o l\'invio non è riuscito: avvisalo tu.'));
        }

        return $mailed;
    }

    /**
     * Prima di toccare il gestore: lo storno delle ricevute deve essere possibile (anno fiscale aperto).
     *
     * @param  list<string>  $receipts
     * @param  list<list<string>>  $held  movimenti ancora senza ricevuta
     */
    private function assertVoidable(array $receipts, array $held): void
    {
        $heldIds = array_merge(...$held);
        if ($heldIds !== []) {
            $closed = $this->connection()->table('app.transactions as t')
                ->join('app.fiscal_years as f', static fn (JoinClause $j) => $j->on('f.org_id', '=', 't.org_id')->on('f.year', '=', 't.fiscal_year'))
                ->whereIn('t.id', $heldIds)->whereNull('t.voided_at')->where('f.status', 'closed')->value('f.year');
            if ($closed !== null) {
                throw new MemberAccessError('Non si può annullare l\'incasso: l\'anno fiscale '.(is_numeric($closed) ? (int) $closed : '').' è chiuso, va riaperto. Il rimborso non è stato fatto.');
            }
        }
        foreach ($receipts as $label) {
            if (preg_match('#^(\d+)/(\d{4})$#', $label, $m) !== 1) {
                continue;
            }
            $receipt = $this->connection()->table('app.receipts')->where('number', (int) $m[1])->where('year', (int) $m[2])->first(['id', 'voided_at']);
            if ($receipt === null || ($receipt->voided_at ?? null) !== null || ! is_string($receipt->id ?? null)) {
                continue;
            }
            $closed = $this->connection()->table('app.transactions as t')
                ->join('app.fiscal_years as f', static fn (JoinClause $j) => $j->on('f.org_id', '=', 't.org_id')->on('f.year', '=', 't.fiscal_year'))
                ->where('t.receipt_id', $receipt->id)->whereNull('t.voided_at')->where('f.status', 'closed')->value('f.year');
            if ($closed !== null) {
                throw new MemberAccessError('Non si può annullare la ricevuta '.$label.': l\'anno fiscale '.(is_numeric($closed) ? (int) $closed : '').' è chiuso, va riaperto. Il rimborso non è stato fatto.');
            }
        }
    }

    /**
     * Storna in prima nota gli incassi delle ricevute indicate («numero/anno»): annullano anche le tessere.
     *
     * @param  list<string>  $receipts
     * @param  list<list<string>>  $held  movimenti ancora senza ricevuta: si stornano anche questi
     */
    private function voidReceipts(array $receipts, array $held, string $reason): void
    {
        foreach (array_merge(...$held) as $txId) {
            if ($this->connection()->table('app.transactions')->where('id', $txId)->whereNull('voided_at')->doesntExist()) {
                continue;
            }
            try {
                $this->ledger->void($txId, mb_substr('Rimborso del pagamento online: '.$reason, 0, 255));
            } catch (LedgerError $e) {
                throw new MemberAccessError('Non si può annullare l\'incasso: '.$e->getMessage().' Il rimborso non è stato fatto.');
            }
        }
        foreach ($receipts as $label) {
            if (preg_match('#^(\d+)/(\d{4})$#', $label, $m) !== 1) {
                continue;
            }
            $receipt = $this->connection()->table('app.receipts')->where('number', (int) $m[1])->where('year', (int) $m[2])->first(['id', 'voided_at']);
            if ($receipt === null || ($receipt->voided_at ?? null) !== null || ! is_string($receipt->id ?? null)) {
                continue;
            }
            $tx = $this->connection()->table('app.transactions')->where('receipt_id', $receipt->id)->whereNull('voided_at')->value('id');
            if (! is_string($tx)) {
                continue;
            }
            try {
                $this->ledger->void($tx, mb_substr('Rimborso del pagamento online: '.$reason, 0, 255));
            } catch (LedgerError $e) {
                throw new MemberAccessError('Non si può annullare la ricevuta '.$label.': '.$e->getMessage().' Il rimborso non è stato fatto.');
            }
        }
    }

    /**
     * Email al socio: importo, motivo e tempi di accredito, in coda come posta massiva (SendPaymentMail). Se non c'è
     * l'email o alla fine non parte, il rimborso resta valido e si scrive nel registro. true = avviso in partenza.
     *
     * @param  array{reservation: bool, enrollment: bool}  $stays
     */
    private function tellMember(string $paymentId, string $personId, int $cents, string $createdAt, string $reason, string $orgName, array $stays): bool
    {
        $notes = array_values(array_filter([$stays['reservation'] ? self::MAIL_RESERVATION : null, $stays['enrollment'] ? self::MAIL_ENROLLMENT : null]));

        return $this->queueMail(SendPaymentMail::refund($this->context->require()->id, $paymentId, $personId, $cents, date('d/m/Y', (int) strtotime($createdAt)), mb_substr($reason, 0, 200), $orgName, $notes), 'refund');
    }

    /**
     * Manda l'avviso di rimborso (dal lavoro in coda). Senza email lo scrive nel registro; un errore torna al lavoro, che riprova.
     *
     * @param  list<string>  $notes
     */
    public function deliverRefundNotice(string $paymentId, string $personId, int $cents, string $paidOn, string $reason, string $orgName, array $notes): void
    {
        $email = $this->emailOf($personId);
        if ($email === null) {
            $this->audit->record('payment.refund_mail_skipped', 'app.online_payments', $paymentId, ['why' => 'no_email']);

            return;
        }
        // L'avviso è una conseguenza automatica del rimborso: parte sempre; senza i contatti della segreteria esce senza indirizzo di risposta né riga contatti.
        $contacts = $this->app->make(OrgContacts::class)->all();
        $this->app->make(Mailer::class)->to($email)->send(new PaymentRefunded($orgName, self::euro($cents), $paidOn, $reason, $contacts['email'] ?? null, $contacts['phone'] ?? null, $notes));
        $this->audit->record('payment.refund_mail_sent', 'app.online_payments', $paymentId, []);
    }

    /**
     * La segreteria ha controllato un pagamento «da controllare»: nascono le ricevute dei movimenti in attesa (una per voce),
     * una volta sola, ma solo se il pagamento è pagato, non rimborsato e senza un rimborso da fare: se i soldi sono stati
     * restituiti prima la ricevuta non nasce mai. Se non si può emettere (anno fiscale chiuso) il pagamento resta da controllare.
     */
    public function markReviewed(string $paymentId): void
    {
        $this->connection()->transaction(function () use ($paymentId): void {
            $row = $this->table()->where('id', $paymentId)->lockForUpdate()->first();
            $p = $row === null ? null : $this->find($paymentId);
            if ($row === null || $p === null || $p['status'] !== 'review') {
                throw new MemberAccessError('Pagamento non trovato o già controllato.');
            }
            $returned = $p['refunded_cents'] > 0 || ($row->refund_pending_cents ?? null) !== null || $p['paid_cents'] === null || $p['paid_cents'] <= 0;
            [$issued, $left, $failure] = $returned ? [[], [], null] : $this->issueReceipts($p['held']);
            if ($failure !== null) {
                throw new MemberAccessError('La ricevuta non si può emettere: '.$failure);
            }
            $this->table()->where('id', $paymentId)->update([
                'status' => 'settled', 'reviewed_at' => now(), 'updated_at' => now(), 'held_transactions' => json_encode($left, JSON_THROW_ON_ERROR),
                'receipts' => json_encode(array_values(array_unique([...$p['receipts'], ...$issued])), JSON_THROW_ON_ERROR),
            ]);
            $this->audit->record('payment.reviewed', 'app.online_payments', $paymentId, ['receipts' => count($issued), 'returned' => $returned]);
        });
    }

    /** @return Payment|null */
    public function find(string $id): ?array
    {
        $r = Str::isUuid($id) ? $this->table()->where('id', $id)->first() : null;

        return $r === null ? null : $this->row($r);
    }

    /** @return Payment|null */
    private function row(object $r): ?array
    {
        if (! is_string($r->id ?? null) || ! is_string($r->person_id ?? null) || ! is_string($r->items ?? null) || ! is_string($r->receipts ?? null)) {
            return null;
        }
        $items = json_decode($r->items, true);
        $receipts = json_decode($r->receipts, true);
        $heldRaw = json_decode(is_string($r->held_transactions ?? null) ? $r->held_transactions : '[]', true);
        $held = [];
        foreach (is_array($heldRaw) ? $heldRaw : [] as $group) {
            $ids = array_values(array_filter(is_array($group) ? $group : [], is_string(...)));
            if ($ids !== []) {
                $held[] = $ids;
            }
        }
        $clean = [];
        foreach (is_array($items) ? $items : [] as $i) {
            if (is_array($i) && is_string($i['key'] ?? null) && is_string($i['person_id'] ?? null) && is_string($i['label'] ?? null) && is_int($i['amount_cents'] ?? null)) {
                $clean[] = ['key' => $i['key'], 'person_id' => $i['person_id'], 'group' => is_string($i['group'] ?? null) ? $i['group'] : '', 'label' => $i['label'], 'amount_cents' => $i['amount_cents'],
                    'donation' => ($i['donation'] ?? false) === true, 'public' => ($i['public'] ?? false) === true];
            }
        }

        return [
            'id' => $r->id, 'person_id' => $r->person_id, 'provider' => is_string($r->provider ?? null) ? $r->provider : '', 'status' => is_string($r->status ?? null) ? $r->status : '',
            'amount_cents' => is_numeric($r->amount_cents ?? null) ? (int) $r->amount_cents : 0, 'paid_cents' => is_numeric($r->paid_cents ?? null) ? (int) $r->paid_cents : null,
            'items' => $clean, 'receipts' => array_values(array_filter(is_array($receipts) ? $receipts : [], is_string(...))), 'held' => $held,
            'provider_ref' => is_string($r->provider_ref ?? null) ? $r->provider_ref : null, 'error' => is_string($r->error ?? null) ? $r->error : null,
            'refunded_cents' => is_numeric($r->refunded_cents ?? null) ? (int) $r->refunded_cents : 0,
            'created_at' => is_string($r->created_at ?? null) ? $r->created_at : '',
        ];
    }

    /** @return list<PayableDues> */
    private function modules(): array
    {
        $out = [];
        foreach ($this->app->tagged(PayableDues::TAG) as $m) {
            if ($m instanceof PayableDues) {
                $out[] = $m;
            }
        }

        return $out;
    }

    /**
     * La voce è ancora dovuta, per almeno l'importo pagato?
     *
     * @param  Due  $item
     */
    private static function stillDue(PayableDues $module, array $item): bool
    {
        foreach ($module->dues([$item['person_id']]) as $due) {
            if ($due['key'] === $item['key']) {
                return $due['amount_cents'] >= $item['amount_cents'];
            }
        }

        return false;
    }

    private function owner(string $key): ?PayableDues
    {
        foreach ($this->modules() as $m) {
            if ($m->owns($key)) {
                return $m;
            }
        }

        return null;
    }

    private static function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table('app.online_payments');
    }

    private function events(): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table('app.payment_webhook_events');
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
