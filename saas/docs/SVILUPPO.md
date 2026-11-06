# SaaS: sviluppo in locale e convenzioni del codice

Come si avvia il progetto e dove si mette il codice. Le regole generali sono in [REGOLE_DI_LAVORO.md](REGOLE_DI_LAVORO.md).

## 1. Avvio

Servono PHP 8.3 (estensioni `pdo_pgsql`, `sodium`, `intl`, `mbstring`), Composer e PostgreSQL 16.

```bash
cd saas
sudo -u postgres scripts/db-locale.sh   # ruoli as_owner e as_app, database as_central, as_test, as_test_dedicato
composer install
cp .env.example .env && php artisan key:generate
php artisan saas:keys:generate           # copia la chiave principale in SAAS_MASTER_KEY
php artisan saas:migrate                 # migrazioni su tutti i database
npm install && npm run build             # CSS e JavaScript (Vue, Tailwind)
composer check                           # Pint, PHPStan al massimo, Pest
php artisan saas:org:create "Polisportiva Prova" polisportiva-prova tu@example.com "Nome"   # associazione di prova
php artisan serve
```

L'invito al titolare finisce nel log (`MAIL_MAILER=log`, `storage/logs/laravel.log`): il link porta alla scelta
della password. In locale su http serve `SESSION_SECURE_COOKIE=false`.

Le password dello script sono di sviluppo e valgono solo in locale e in CI. I test usano `as_test` e `as_test_dedicato`
(`phpunit.xml`) e girano su PostgreSQL vero: la Row-Level Security non si può provare con SQLite.

## 2. Due ruoli, due connessioni

| Connessione | Ruolo | Uso |
|---|---|---|
| `pgsql` | `as_app` (`DB_USERNAME`) | richieste web e code; soggetto alla RLS, niente superuser, niente `BYPASSRLS`, niente `DELETE` dove non serve |
| `pgsql_owner` | `as_owner` (`DB_OWNER_USERNAME`) | solo `php artisan saas:migrate` |

Sul server i due ruoli sono due utenti PostgreSQL creati da cPanel; il proprietario va reso proprietario del
database. Il ruolo dell'applicazione non legge nemmeno la tabella `migrations`.

## 3. Moduli

```
app/Modules/<Modulo>/
  Contracts/                   interfaccia pubblica: l'unica parte che gli altri moduli possono usare
  Models/  Http/  Console/  Providers/  …
  Database/Migrations/Central/ solo database centrale (schema core)
  Database/Migrations/Org/     tutti i database: condiviso e dedicati (schemi app, audit, sites)
```

Un test di architettura fallisce se un modulo usa qualcosa di un altro modulo fuori da `Contracts`.

## 4. Associazione corrente

- `Core\Contracts\OrgContext` è l'unico punto che imposta `app.org_id`: `enter()`, `leave()`, `run()`.
- La middleware `ResetOrgContext` è la prima di ogni richiesta: parte senza associazione e la azzera alla fine.
- I lavori in coda implementano `OrgScopedJob` e usano la middleware `RunInOrgContext`; il loro payload contiene solo
  identificativi. La coda sta nel database centrale.
- I modelli con i dati di un'associazione estendono `Core\Contracts\OrgModel`: connessione dell'associazione, filtro
  su `org_id`, `org_id` assegnato in automatico. Senza associazione impostata lanciano `NoOrgContext`.

## 5. Accesso

| Cosa | Dove |
|---|---|
| Accesso, password, verifica in due passaggi | Laravel Fortify, pagine Inertia in `resources/js/pages/Auth` e `Account`; configurazione in `config/fortify.php` |
| Indirizzi | `/accesso`, `/esci`, `/password-dimenticata`, `/reimposta-password`, `/conferma-password`, `/verifica-accesso`, `/account/verifica-in-due-passaggi` |
| Ruoli | `Core\Contracts\OrgRole`: titolare, amministratore, segreteria, commercialista (`core.org_users`), più i ruoli personalizzati (`custom`, nome e permessi in `core.org_roles`) |
| Permessi | `Core\Contracts\Ability`, regola pura `Ability::allows()`; nelle rotte `can:people.edit`, nel codice `Gate::allows(...)` |
| Inviti | `Core\Access\OrgMembers` (`invite`, `changeRole`, `remove`, `saveRole`, `deleteRole`), pagina `/gestionale/operatori` (`org.operators`) e i comandi `saas:org:create`, `saas:operator:invite` |

Regole:

- **niente registrazione pubblica**: gli utenti nascono dal portale o da un invito;
- **verifica in due passaggi obbligatoria per chiunque sia operatore**, anche di un'associazione sospesa: senza, il
  gestionale manda alla pagina di attivazione; non si può disattivare né sostituire il segreto confermato;
- l'associazione scelta sta in sessione, ma **ruolo e stato dell'associazione si ricontrollano a ogni richiesta**
  (`IdentifyOperatorOrg`): un ruolo tolto vale subito; un'associazione non propria risponde 404;
- password di almeno 12 caratteri, in produzione anche il controllo sulle violazioni note; 5 tentativi al minuto per
  email e indirizzo, 5 per il codice; conferma della password valida 15 minuti;
- «Password dimenticata» risponde allo stesso modo che l'email esista o no;
- **«Ricordami» vero**: senza la spunta l'accesso finisce quando si chiude il browser (`SESSION_EXPIRE_ON_CLOSE=true`) e
  comunque dopo 2 ore senza attività; con la spunta il dispositivo resta riconosciuto 30 giorni (`auth.guards.web.remember`);
- **cookie solo per il suo indirizzo**: nome proprio (`as_gestionale`, in produzione `__Host-as_gestionale`), senza
  `Domain`: non lo ricevono né il portale (`associazionesemplice.it`, che ha una sessione sua) né i sottodomini dei
  soci. Gestionale, portale e area soci hanno quindi sessioni **separate**: entrare in uno non fa entrare negli altri;
- sessione cifrata, cookie solo HTTPS, Content-Security-Policy senza script esterni, pagine con dati non in cache,
  cronologia di Inertia cifrata, strumenti di sviluppo di Inertia spenti.

Ruoli personalizzati (segretari, consiglieri, tesorieri): li crea titolare o amministratore con i permessi di
`Ability::assignable()` (tutti tranne abbonamento, impostazioni e operatori); `Ability::normalize()` aggiunge quelli
presupposti (chi modifica vede). Valgono dalla richiesta successiva; un ruolo si elimina solo se nessuno lo ha. Nessuno
cambia il proprio ruolo né si toglie da solo. Il menu del gestionale mostra solo le sezioni permesse (`org.can`).

I permessi dei soci sui propri dati (area soci) non passano dai ruoli: derivano dai dati, come `Access` nel plugin, e
arrivano con il modulo Soci.

## 6. Cifratura

Modulo `Cifratura`; regole in [DATABASE.md](DATABASE.md) §5, chiavi in [ARCHITETTURA.md](ARCHITETTURA.md) §7.

```php
// Modello: l'attributo tax_code vive nelle colonne tax_code_enc e tax_code_bidx (bytea).
protected function casts(): array
{
    return [
        'tax_code'   => Encrypted::class.':index=taxcode', // con indice di ricerca (taxcode, iban, phone, text)
        'birth_date' => Encrypted::class.':date',
        'address'    => Encrypted::class.':json',
        'phone'      => Encrypted::class,
    ];
}

Person::whereEncrypted('tax_code', 'rss mra 80a01 h501u');   // ricerca esatta, normalizzata

// File: cifrati sul disco org_files; nel database si salvano i campi di StoredFile.
$stored = app(EncryptedFiles::class)->store($orgId, $upload->getRealPath(), $upload->getClientOriginalName(), $mime);
return app(EncryptedFiles::class)->download($orgId, $stored);  // dopo il controllo dei permessi
```

- I campi `*_enc` e `*_bidx` vanno in `$hidden` del modello.
- Un campo cifrato si legge solo dentro la sua associazione (OrgContext): altrimenti errore.
- Il servizio chiavi ha la sua cartella, `keyservice/`, senza dipendenze: si copia sull'altro account con
  `config.ini` fuori dalla cartella pubblica ([keyservice/README.md](../keyservice/README.md)).

## 7. Registro delle azioni

Modulo `Audit`; regole in [DATABASE.md](DATABASE.md) §7.

```php
final class Person extends OrgModel
{
    use Audited;   // creazione, modifica, cancellazione e ripristino, con i nomi dei campi
}

app(AuditLog::class)->record('export.done', 'app.people', null, ['rows' => 120, 'format' => 'csv']);
```

- Si scrive solo dentro un'associazione (OrgContext), nella stessa transazione della modifica.
- Azioni nel formato `oggetto.azione` (`person.updated`, `operator.invited`, `health.read`).
- I campi sanitari si marcano `Encrypted::class.':health'`: le letture finiscono nel registro da sole.
- Cron: `saas:audit:partitions` il primo del mese, `saas:audit:verify` ogni notte (già nello scheduler).

## 8. Pacchetti e abbonamento

Diritti dal portale ([CONTRATTO_PORTALE.md](CONTRATTO_PORTALE.md)), letti con `Portale\Contracts\Entitlements`:

```php
Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:member_area'])->group(...);

$e = app(Entitlements::class)->for($orgId);
$e->has(Package::Resources);                 // pacchetto attivo?
$e->limit(Package::Resources, 'hourly.tier'); // limite del pacchetto
```

Le associazioni create a mano (pilota, `saas:org:create`) non hanno abbonamento sul portale: niente limite di
operatori e nessun pacchetto finché non ne ricevono.

## 8 bis. Configurazione guidata

- `Core\Org\SetupWizard`, pagina `/gestionale/configurazione` (`org.settings`). Al primo accesso il riepilogo la apre
  da solo, una volta per sessione, finché non è completata o rimandata (`core.setup_status` in `app.settings`).
- I passi li forniscono i moduli: una classe che implementa `Core\Contracts\SetupStep`, registrata con
  `$this->app->tag([...], SetupStep::TAG)` nel provider. Si controllano tutti prima di salvare; se un passo ha errori
  non si salva nulla. Oggi: `Contabilita\Setup\EntityStep` (dati dell'ente, `ente.*`), `Soci\Setup\MembershipStep`,
  `Contabilita\Setup\AccountsStep`, `Soci\Setup\ApplicationsStep`.
- Nei test le associazioni nascono con la configurazione già fatta (`createOrg(..., setupDone: false)` per provarla).

## 9. Soci e tesseramento

- Regole portate dal plugin in `app/Modules/Soci/Rules` (`TaxCode`, `Age`, `Phone`, `Iban`, `SocialYear`,
  `PersonRules`) con i loro casi di test; i tipi di persona in `Soci\Contracts\MemberType`.
- Una tessera nasce dall'incasso della quota (`MembershipService::renewWithPayment`: movimento in prima nota con
  ricevuta e tessera insieme) o senza incasso con un motivo. Vale se lei e l'incasso non sono stornati: la vista
  `app.valid_memberships` lo calcola, quindi stornare la quota toglie la tessera da sé.
- Il fondatore è sempre in regola. I soci attivi (`Soci\Contracts\Members`) sono quelli che il SaaS comunica al portale.
- Tutori (`GuardianService`, regole in `GuardianRules`): al massimo due; il primo è l'operativo (deve poter entrare e
  avere l'email), togliendolo l'operativo passa all'altro.
- Certificati medici (`CertificateService`): tipo, medico e nome del file cifrati e marcati `health`, file cifrato;
  ogni lettura e ogni scarico finiscono nel registro. Solo chi ha `health.view` (non il commercialista) li vede.
  L'elenco delle scadenze usa solo le date, senza aprire i dati sanitari.
- Impostazioni del tesseramento in `app.settings` (`Core\Contracts\OrgSettings`): mese di inizio dell'anno sociale e
  quota associativa.
- Consensi (`ConsentService`, canali in `Soci\Contracts\ConsentChannel`): ogni consenso o revoca è un evento in
  `app.consent_events`, che non si modifica; lo stato è l'ultimo evento (vista `app.current_consents`). Per un minore
  lo dà un suo tutore.
- Promemoria (`ReminderService`, comando `saas:reminders:send` ogni mattina, `--dry-run` per contare): tessera e
  certificato in scadenza o scaduti da meno di 30 giorni, al socio o al tutore operativo del minore. Uno solo per
  persona, tipo e scadenza (`app.reminders`, prenotato prima dell'invio). Si accendono nelle impostazioni delle domande.
- Domande di adesione (`ApplicationService`, regole in `ApplicationRules` dal plugin): modulo pubblico
  `/adesione/{indirizzo}` solo se l'associazione è attiva e le ha accese; 5 invii al minuto per indirizzo IP e 50
  all'ora per associazione, campo trappola `website`, stessa risposta qualunque sia il dato. I dati del modulo sono
  cifrati in `app.applications.data_enc`. Accogliendo nasce la scheda (con il genitore, cercato per codice fiscale o
  email) e si registrano i consensi del modulo come «Online»; respingendo serve un motivo.
- Import (`PeopleImportService`, lettura e piano in `Soci/Import`, portati dal plugin con i loro test): `.xlsx` o CSV
  (`;`, `,` o tabulazione, UTF-8 o Windows-1252), al massimo 5 MB e 5000 righe. Prima l'anteprima, poi lo stesso file
  si applica: chi c'è già si riconosce per tessera, email, cellulare, codice fiscale o nome e si aggiorna con i soli
  dati presenti; le colonne con un anno diventano tessere senza incasso («Importata da file»); tutori e ospitanti
  si cercano anche nel file. Una riga rifiutata non ferma le altre. Nel registro `people.imported`.
- Export (`PeopleExportService`, permesso `people.export`, non per il commercialista): CSV per Excel con le colonne
  che l'import riconosce, celle che sembrano formule precedute da un apostrofo; nel registro `export.done`.
- Libro soci (`MemberBookService`, `/gestionale/soci/libro`): soci (non ospiti né genitori) in ordine di ingresso, con
  domanda e ammissione (dalle domande online), cessazione, ultima quota incassata e tutore. PDF e CSV con
  `people.export`, nel registro `member_book.exported`. L'uscita non può essere nel futuro né prima dell'ingresso.

## 9 bis. Attività

- Modulo `Attivita`: corsi ed eventi di un anno sociale (`app.activities`), iscrizioni (`app.enrollments`, si chiudono
  con l'ultimo mese, non si cancellano). Regole portate dal plugin in `Attivita/Rules` (`PaymentCalc`, `Pricing`,
  `ActivityRules`) con i loro casi di test.
- Centro di costo: `app.transactions.activity_id`, scritto con `Income`/`Expense` (`activityId`) e cambiato con
  `Ledger::assignActivity()`. Totali, movimenti e incassi per persona e mese con `Ledger::activityTotals()`,
  `activityMovements()`, `activityPayments()`. La prima nota elenca le attività con `Attivita\Contracts\Activities`.
- Situazione dei contributi calcolata dagli incassi non stornati con mese di competenza: stornare un incasso lo toglie.
- Permessi: vedere `people.view`; attività e iscrizioni `people.edit`; incassi `people.edit` e `accounting.edit`;
  spese e cambio dell'attività di un movimento `accounting.edit`.

- Eventi con prenotazioni (`recurring`): date in `app.activity_sessions` (orario, luogo, posti), prenotazioni alla
  singola data in `app.session_bookings` con il dovuto fissato alla prenotazione: contributo soci/ospiti, oppure un
  listino (`activities.price_lists`, testo «Pacchetto 5 ingressi 40 x5»); un pacchetto copre più ingressi, usati con
  `package_of`. I posti si controllano con la data bloccata (`FOR UPDATE`). Lista d'attesa in `app.session_waitlist`:
  annullamenti e posti in più fanno entrare da soli i primi. Incassi con ricevuta legati all'attività
  (`app.booking_payments`, pagato = movimenti non stornati). Gli eventi singoli restano a iscrizione.
- Presenze (`app.attendance`): lezioni dei corsi dai giorni della settimana nel periodo del corso, data dell'evento,
  date non annullate degli eventi con prenotazioni; si registrano a lezione fatta. Calendario settimanale
  `/gestionale/calendario`. Regole in `Attivita/Rules/SessionRules` con i loro test.
- Da fare: lezioni annullate o spostate dei corsi, avviso email a chi entra dalla lista d'attesa, prenotazione online
  dei soci (con l'Area soci).

## 9 quater. Risorse e prenotazioni (pacchetto)

- Modulo `Risorse`, rotte dietro `package:resources` (senza pacchetto 403; il menu mostra «Risorse» solo con il
  pacchetto, dai `org.packages` condivisi con le pagine). Tipologia = come si vende la risorsa: `hourly`, `daily`,
  `seasonal` (`Risorse\Contracts\Typology`). Il numero di risorse attive per tipologia è limitato da
  `Entitlements::limit(Package::Resources, '<tipologia>.tier')`; una tipologia assente dal pacchetto non si crea.
- Tabelle: `app.resources`, `app.reservations` (prenotazioni e blocchi; orari locali `timestamp` nel fuso
  Europe/Rome), `app.reservation_payments` (legame con i movimenti della prima nota), `app.resource_waitlist`.
  Le sovrapposizioni le impedisce un vincolo `EXCLUDE USING gist` (estensione `btree_gist`) sulle prenotazioni
  attive; il servizio controlla prima per dare il messaggio giusto (quale giorno, blocco o prenotazione).
- Regole dal plugin in `Risorse/Rules/ResourceRules` (fasce, giornate, stagioni, ospiti, tariffe a fasce, tariffa non
  soci, servizi aggiuntivi) con i casi di test portati.
- Socio = tessera valida alla data (`Members::isActive`): paga la tariffa soci; altri la tariffa non soci se c'è. Si
  prenota anche per un esterno (solo nome). Stagionali solo a persone dell'anagrafica, con lista d'attesa che si chiude
  da sola all'assegnazione.
- Incassi con `Ledger::recordIncome` (voce della risorsa o «Altre entrate», ricevuta se chi paga è in anagrafica);
  il pagato è la somma dei movimenti non stornati (`Ledger::liveAmounts`).
- Permessi: vedere `people.view`; risorse, prenotazioni, blocchi e lista d'attesa `people.edit`; incassi anche
  `accounting.edit`. Prenotazione e pagamento online dei soci arriveranno con l'Area soci.

## 9 quinquies. Area soci (pacchetto, passo 1)

- Modulo `AreaSoci`. L'associazione si riconosce dal sottodominio: rotte su `{org}.<saas.members_domain>/area`,
  middleware `member-org` (associazione attiva con il pacchetto `member_area`, altrimenti 404). In locale
  `SAAS_MEMBERS_DOMAIN=localhost` e si apre `http://<slug>.localhost:8000/area`; nei test il dominio è fissato in
  `phpunit.xml`.
- Accesso senza password (dal `MemberSession` del plugin): l'email riceve link e codice a 6 cifre (15 minuti, una
  volta sola, 5 tentativi); per chi non ha email la segreteria prepara dalla scheda un link valido 7 giorni da
  mandare su WhatsApp. Sessioni in `app.member_sessions`, cookie `as_socio` (path `/area`, HttpOnly, SameSite Lax),
  30 giorni senza uso o 90 dall'accesso; «esci da tutti i dispositivi» per il socio e per la segreteria. Nel database
  solo impronte sha256 di link, codici e token. Limiti di frequenza per indirizzo IP e per email.
- Le schede visibili: la propria e quelle dei minori di cui si è tutori (si sceglie in alto). Pagine: tessera con QR
  (link firmato `/area/verifica/{persona}` che dice solo se la tessera è valida oggi), profilo con telefono e indirizzo
  modificabili, quote delle attività e ricevute in PDF, eventi dei prossimi 60 giorni con prenotazione, lista
  d'attesa e annullamento fino al giorno prima (contributo da pagare in segreteria).
- Gli altri moduli espongono ai soci solo contratti: `Soci\Contracts\MemberProfiles`,
  `Contabilita\Contracts\MemberReceipts`, `Attivita\Contracts\MemberActivities`. QR senza librerie in
  `Documenti\Contracts\QrCode` (dal plugin, con i suoi test).
- Registro: le azioni dei soci hanno `actor_type = 'member'` e `actor_id` = la persona (`Audit\Contracts\ActingMember`).
- Passo 2, pagamenti online: ogni modulo fornisce le sue voci dovute con `Core\Contracts\PayableDues` (tag
  `saas.payable-dues`): quota associativa (Soci), mensilità e contributi dei corsi ed eventi con prenotazione
  (Attività), prenotazioni delle risorse (Risorse). Il browser manda solo le chiavi; importi ricalcolati sul server.
  `AreaSoci\Payments\OnlinePayments` crea il pagamento (Stripe Checkout o PayPal Orders v2, dal plugin) con le chiavi
  dell'associazione in `app.payment_settings` (cifrate con la sua chiave, mai rimandate al browser), conferma al
  ritorno e con il webhook firmato di Stripe (`/area/webhook/stripe` sul sottodominio), registra ogni voce in prima
  nota con la sua ricevuta una volta sola (riga bloccata); importo diverso o voce non più dovuta = «da controllare»
  per la segreteria. Gestionale: `/gestionale/pagamenti-online` (impostazioni con `org.settings` e conferma della
  password, elenco con `accounting.view`). Nei test Stripe e PayPal sono simulati con `Http::fake`.
- Risorse Web: il socio prenota dall'area soci le risorse a ore e a giornata delle tipologie che il pacchetto ha con
  `web: true` (`Risorse\Contracts\MemberResources`), con anticipo e niente passato; annulla le sue future non pagate.
- Prossimo passo: app installabile (PWA), notifiche e tessera nel wallet.

## 9 ter. Documenti e ricevute

- PDF senza librerie: `Documenti\Contracts\Pdf` (dal plugin; A4 verticale o orizzontale, testo, linee, rettangoli).
- Ricevute (`Contabilita\Receipts\ReceiptPdf`, `/gestionale/ricevute/{id}.pdf`, invio con `/invia`): intestazione con i
  dati dell'ente della configurazione, chi ha pagato con il codice fiscale; per un minore il genitore (`ReceiptRules`,
  dal plugin con i suoi test), con la nota sulla detrazione dai 5 ai 18 anni. I dati delle persone arrivano da
  `Soci\Contracts\People`. Le ricevute stornate non si stampano. Scarico e invio nel registro.

## 9 quater. Import ed esportazione

- Lettura di Excel e CSV condivisa in `Documenti\Contracts` (`SheetReader`, `Xlsx`, `Text`); CSV per Excel con
  `Documenti\Contracts\Csv` (BOM, «;», celle-formula protette).
- Prima nota: `Contabilita\Import\LedgerSheet` (dal LedgerImport del plugin, con i suoi test) e
  `LedgerImportService` (`/gestionale/prima-nota/importa`, `accounting.edit`): anteprima e applicazione dello stesso
  file; movimenti senza ricevuta, conti nuovi creati, giroconti accoppiati, doppioni saltati (anche quelli del proprio
  export), righe rifiutate elencate. `LedgerExport` scrive lo stesso formato (`/gestionale/prima-nota/esporta`).
- Esportazione completa (`Core\Export\FullExport`, `/gestionale/esportazione`, `org.settings`): ogni modulo fornisce
  i suoi file con una classe che implementa `Core\Contracts\DataExport`, registrata con il tag `DataExport::TAG`;
  ciascuno esporta solo ciò che l'operatore può vedere. L'archivio si crea in `storage/app/tmp` e si cancella dopo lo
  scarico; nel registro `export.full`. Gli allegati dei certificati non ci sono.

## 10. Nuova tabella di un'associazione

Ogni tabella di `app`, `audit` e `sites`:

1. ha `org_id` in testa alla chiave primaria (`PRIMARY KEY (org_id, id)`) e `id` UUIDv7;
2. punta ad `app.orgs` con `org_id` e alle altre tabelle con chiavi esterne composte `(org_id, …)`, ciascuna con il suo
   indice;
3. chiama `OrgSchema::isolate('app.tabella')` e `OrgSchema::grant('app.tabella', [...])` con i soli permessi necessari.

I test in `tests/Feature/Core/SchemaRulesTest.php` controllano queste regole su tutte le tabelle presenti nel database:
una tabella nuova che non le rispetta li fa fallire. Ogni tabella aggiunge comunque il suo test di separazione.

## 11. Database dedicati

L'elenco è un file JSON fuori dal repository e fuori dalla cartella pubblica (`SAAS_DEDICATED_DB_FILE`), con il nome del
database e, se si usano ruoli separati, le loro credenziali (formato in `Core\Database\DatabaseRegistry`). In
`core.orgs.database` si scrive il nome del dedicato; `null` vuol dire database condiviso. `saas:migrate` li migra tutti.

## 12. Composer dal cloud di Claude

Nelle sessioni cloud il proxy blocca gli zip di GitHub: Composer ripiega sui sorgenti git, più lento. `phpstan/phpstan`
esiste solo come zip; si scarica il suo repository con git e si mette l'archivio nella cache di Composer
(`~/.cache/composer/files/phpstan/phpstan/<sha1 dell'URL>.zip`). In locale e in GitHub Actions non serve.
