# SaaS: schema del database

Regole per il database della versione SaaS (gestionale online, e gestionale + sito generato dall'AI).
Il plugin WordPress resta com'è: questo documento riguarda solo il SaaS.

Priorità, in quest'ordine: **1. sicurezza dei dati personali e fiscali · 2. velocità**.

## 0. Contesto e decisioni prese

| Tema | Decisione |
|---|---|
| Stack | Laravel + PostgreSQL. La logica delle classi `*Rules` del plugin si riusa. |
| Multi-tenant | Un database condiviso con `org_id` + Row-Level Security. **Database dedicato** (esclusivo dell'associazione, anche sullo stesso server Postgres) come opzione a pagamento. |
| Hosting iniziale | Sub-account `associazionesemplice` del reseller Wega Studios su SupportHost (nodo in Finlandia, backup in UE). Per crescere: semidedicato o VPS. |
| PostgreSQL | **16** (aggiornato da SupportHost dalla 13; è l'ultima che cPanel supporta, con aggiornamenti fino a novembre 2028). Estensioni `pgcrypto`, `pg_trgm`, `citext`, `uuid-ossp` installate. |
| Redis | Istanza dedicata via socket, 64 MB, politica `allkeys-lru` (piena: toglie le chiavi meno usate, non rifiuta; se non risponde la cache passa al database): **solo cache e limiti di frequenza, sempre con scadenza**. Code dei lavori su Postgres. |
| Backup | JetBackup giornaliero (30 giorni) + **nostro dump orario cifrato** di ogni database su uno spazio esterno in UE. Perdita massima: un'ora. |
| Creazione dei database | Via cPanel UAPI. L'utente Postgres deve avere un **nome diverso dal database** (cPanel lo vieta), poi `update_privileges`. |

## 1. Schemi

| Schema | Contenuto | Row-Level Security |
|---|---|---|
| `core` | associazioni (`orgs`), utenti di accesso (`users`), ruoli nell'associazione (`org_users`), abbonamenti e piani, chiavi dati cifrate (`org_keys`), domini | no; accesso ristretto |
| `app` | tutti i dati dell'associazione | **sì, forzata** |
| `audit` | registro delle azioni | sì |
| `sites` | siti, versioni, file caricati | sì |

**Accesso globale, anagrafica per associazione.** Una persona attiva in due associazioni ha un solo `core.users` e due
`app.people` collegate (`people.user_id`), che prende il posto di `wp_user_id`. I permessi restano derivati dai dati,
come oggi in `Access`.

**Colonna non più letta: `core.users.platform_admin`.** Dal passaggio D31 nessun codice la legge né la scrive (i super
amministratori sono per identificativo della persona in `core.staff_super_admins`). La colonna resta nel database: da
togliere con il sì del titolare (la rimozione è distruttiva).

**`core` esiste solo nel database centrale.** I database dedicati contengono `app`, `audit` e `sites`, più una piccola
tabella locale `app.orgs` con l'associazione stessa. Una chiave esterna non può attraversare due database, quindi:
- le tabelle di `app`, `audit` e `sites` puntano a `app.orgs`, presente anche nel database condiviso;
- i riferimenti a `core.users` (`people.user_id`, `created_by`) sono **senza chiave esterna**: li controlla
  l'applicazione;
- le migrazioni sono di due tipi: `core` solo sul database centrale, le altre su tutti i database.

## 2. Chiavi primarie: UUIDv7

- Tutte le tabelle hanno `id uuid` **UUIDv7** (ordinato nel tempo, quindi efficiente per gli indici), generato da Laravel.
  Postgres 16 non lo genera nativamente (arriva nella 18).
- **Motivo principale: la portabilità.** Un'associazione passa dal database condiviso a uno dedicato, o dal plugin al SaaS
  e ritorno, senza riscrivere nessun identificativo.
- Gli id non si possono indovinare da un URL. I token pubblici (QR della tessera, link personali) restano comunque
  valori casuali separati.

## 3. Separazione tra associazioni: tre barriere

1. **`org_id` in ogni tabella di `app`, `audit` e `sites`**, sempre prima colonna di chiavi e indici.
2. **Chiavi esterne composte**: il database impedisce che un dato dell'associazione A punti a un dato dell'associazione B.
3. **Row-Level Security forzata** su ogni tabella. All'inizio di ogni richiesta e di ogni lavoro in coda
   l'applicazione esegue `set_config('app.org_id', <id>, false)`, e a fine richiesta la azzera. Si imposta sempre
   all'inizio, così una connessione persistente non porta mai con sé l'associazione precedente. Le policy usano
   `NULLIF(current_setting('app.org_id', true), '')`: se manca, nessuna riga è visibile e la richiesta non va in errore.

Ruoli Postgres:

| Ruolo | Uso | Permessi |
|---|---|---|
| proprietario (migrazioni) | `php artisan saas:migrate` | proprietario delle tabelle; anche lui soggetto alla RLS (`FORCE`) |
| applicazione | richieste web e code | solo i permessi necessari, tabella per tabella; mai `BYPASSRLS` né superuser |
| backup | `pg_dump` (`saas:backup:dump`) | solo `SELECT`; ha una policy `backup_read` di sola lettura su ogni tabella, mai di scrittura |

Esempio:

```sql
CREATE TABLE app.people (
  org_id      uuid        NOT NULL REFERENCES app.orgs(id),
  id          uuid        NOT NULL,
  first_name  text        NOT NULL,
  last_name   text        NOT NULL,
  -- …
  PRIMARY KEY (org_id, id)
);

CREATE TABLE app.transactions (
  org_id        uuid    NOT NULL,
  id            uuid    NOT NULL,
  tx_date       date    NOT NULL,
  type          text    NOT NULL CHECK (type IN ('income', 'expense', 'transfer')),
  amount_cents  bigint  NOT NULL CHECK (amount_cents > 0),
  account_id    uuid    NOT NULL,
  person_id     uuid,
  voided_at     timestamptz,
  void_reason   text,
  created_by    uuid,
  created_at    timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (org_id, id),
  FOREIGN KEY (org_id, account_id) REFERENCES app.accounts(org_id, id),
  FOREIGN KEY (org_id, person_id)  REFERENCES app.people(org_id, id)
);

ALTER TABLE app.transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE app.transactions FORCE ROW LEVEL SECURITY;
CREATE POLICY org_isolation ON app.transactions
  USING      (org_id = (SELECT NULLIF(current_setting('app.org_id', true), '')::uuid))
  WITH CHECK (org_id = (SELECT NULLIF(current_setting('app.org_id', true), '')::uuid));
CREATE POLICY backup_read ON app.transactions FOR SELECT TO <ruolo dei backup> USING (true);
```

Il sottoselect fa leggere `app.org_id` una volta per istruzione: PostgreSQL lo tratta come un valore fisso e usa gli indici
che partono da `org_id` anche nelle query che non lo scrivono (con la forma precedente, con un `OR` sul ruolo dei backup,
faceva una scansione completa di tutte le associazioni; misurato su 60.000 righe, costo del piano da 3243 a 8).
Il ruolo dei backup legge con la sua policy `backup_read` (sola lettura). Le policy valgono per tutti i ruoli, il
proprietario compreso (`FORCE`): le operazioni del proprietario impostano `app.org_id` come l'applicazione.

(Prova della RLS con `FORCE` e `set_config` fatta sul server SupportHost il 2026-11-02: funziona.)

**Vincolo sulle connessioni: niente pooler in modalità transazione.** L'associazione corrente è impostata a livello di
sessione (`set_config('app.org_id', …, false)`, in `DatabaseOrgContext`). Con un pooler in modalità transazione (per
esempio PgBouncer con `pool_mode = transaction`) due istruzioni di fila possono arrivare a connessioni diverse e
l'impostazione andrebbe all'associazione sbagliata o mancherebbe. Si usa una connessione diretta a PostgreSQL, o un
pooler in modalità sessione. `saas:health` lo verifica a ogni rilascio (imposta e rilegge `app.org_id` su istruzioni
consecutive) e controlla anche ruolo dell'applicazione, RLS attiva e forzata su `app.*`, `audit.*` e `sites.*`, e
permessi del ruolo (sul database condiviso: i database dedicati si controllano dai test di schema).

**Ruoli di gruppo e oggetti (`saas:health`, voce «oggetti e ruoli di gruppo»).** Su hosting cPanel i ruoli dell'applicazione,
del proprietario e dei backup sono membri, con ereditarietà, del ruolo di gruppo che possiede il database: non si evita,
quindi si controlla oggetto per oggetto, su ogni database (solo query di sola lettura sul catalogo, con il ruolo dei backup).
Segnala, con le regole degli altri controlli sui permessi (errore con `SAAS_HEALTH_STRICT`, avviso senza):
1. schemi inattesi: oltre a `public`, `app`, `audit`, `sites`, `core`, `portal`, a quelli che iniziano per `org_` e a quelli
   elencati in `SAAS_HEALTH_EXTRA_SCHEMAS` (nomi separati da virgola);
2. tabelle, viste, sequenze, funzioni e schemi di proprietà dell'applicazione, dei backup o di un ruolo di gruppo di cui
   sono membri, cioè non del ruolo proprietario (rimedio: `ALTER ... OWNER TO` con il ruolo proprietario);
3. permessi espliciti dei ruoli di gruppo su schemi e tabelle (rimedio: `REVOKE`);
4. `CREATE` sul database o su uno schema da parte dell'applicazione o dei backup, anche ereditato.
Un'altra voce, «separazione dal ruolo proprietario», è **sempre un ERRORE**, anche senza `SAAS_HEALTH_STRICT`: l'applicazione
o i backup sono membri del ruolo proprietario (la separazione dei ruoli non esiste).
Il dettaglio di questi messaggi resta nel file riservato `shared/health-<data>.txt` (rilascio) o nel file di `permessi-db`
(manutenzione); nel log del rilascio escono solo l'esito e il conteggio, e nessuna risposta HTTP li riporta.
Quando 1-4 sono in ordine e applicazione o backup possono assumere con `SET ROLE` il ruolo che possiede il database, la
voce resta verde e `saas:health` stampa una nota «limite noto»: è un'informazione, non un errore.

## 4. Tipi di dato e indici

- **Importi**: `bigint` in centesimi con `CHECK`. Mai `numeric` o `float` per il denaro.
- **Istanti**: `timestamptz`. **Date contabili, di competenza, di nascita**: `date`.
- **Stati e tipi**: `text` con `CHECK` sui valori ammessi (più semplice da far evolvere degli enum nativi).
- **`jsonb`** solo per dati davvero variabili (righe di un pagamento, impostazioni).
- **Ogni chiave esterna ha un indice**, che inizia con `org_id`.
- **Chiavi esterne verso `app.orgs` (H31, misurate, non tolte)**: ogni tabella ha la sua. A ogni inserimento il
  controllo blocca la riga dell'associazione (FOR KEY SHARE) fino alla fine della transazione. In CI, 5000 righe a
  gruppi di 100 hanno impiegato 24,6 ms con la chiave e 7,9 ms senza (`tests/Feature/Performance/SchemaMeasuresTest.php`).
  In 20 tabelle la stessa garanzia c'è già: un'altra chiave esterna con `org_id` in testa, tutte le colonne
  obbligatorie, verso una tabella che punta ad `app.orgs`. Per esempio `app.transactions`, tramite conto, categoria e
  anno fiscale. Nelle altre 22, tra cui `audit.log` e `audit.heads`, è l'unica garanzia. Toglierne una richiede una
  migrazione in due tempi e la prova che la cancellazione di un'associazione (`DatabaseOrgPurger`) resta nello stesso
  ordine. Il guadagno è piccolo: per ora restano tutte.
- **Indici parziali** `WHERE deleted_at IS NULL` per le tabelle con cancellazione logica.
- **Ricerca per nome**: indice trigram (`pg_trgm`) su nome e cognome.
- **Paginazione per cursore**, mai `OFFSET`.
- Vincoli nel database, non solo nel codice: `NOT NULL`, `UNIQUE`, `CHECK`, chiavi esterne.

## 5. Cifratura dei singoli campi

Si cifra nell'applicazione con `sodium`: la chiave non entra mai nel database.

| Cifrati | In chiaro |
|---|---|
| codice fiscale, data e luogo di nascita, indirizzo, telefono, IBAN, documenti d'identità, certificati medici (tipo, medico, file), dati dei tutori dei minori | nome, cognome, email, numero tessera |

- **Algoritmi** (libsodium): campi con XChaCha20-Poly1305; impronte di ricerca con BLAKE2b con chiave; file con
  secretstream XChaCha20-Poly1305 a blocchi da 64 KiB; chiavi dati cifrate con XChaCha20-Poly1305.
- **Ogni testo cifrato è legato** all'associazione, al campo (`app.people.tax_code`) e alla versione della chiave
  (dato autenticato): copiato in un'altra associazione o in un altro campo non si apre. Formato: `0x01` · versione della
  chiave · nonce · testo cifrato. Due cifrature dello stesso valore sono diverse.
- **Formato legato alla riga** (`0x02`): stesso schema, con l'id della riga nel dato autenticato (non nel testo: si ricava
  dalla riga alla lettura). Un testo copiato su un'altra riga non si apre. Si scrive per i campi di `app.people` e delle
  impostazioni di pagamento (opzione `bound` del cast `Encrypted`); gli altri campi cifrati restano `0x01`. L'interruttore
  `SAAS_ROW_BOUND_FORMAT` (`saas.crypto.row_bound_format`, acceso di default) riporta le nuove scritture a `0x01`. La lettura
  apre sempre entrambi i formati: le righe vecchie restano `0x01` finché il campo non viene riscritto, oppure finché non le
  porta a `0x02` il comando `saas:crypt:upgrade-format` (procedura in [RILASCIO.md](RILASCIO.md) §3). Le impronte di
  ricerca non cambiano.
- **Copie d'appoggio del passaggio** (`app.crypt_format_backups`): una riga per campo convertito, con l'associazione,
  la tabella, la riga, la colonna, il vecchio testo cifrato `0x01`, l'impronta SHA-256 del nuovo e la data. Come ogni
  tabella di `app`: `org_id`, RLS forzata, nessun dato in chiaro. Si tengono 30 giorni (`saas:crypt:upgrade-format:prune`,
  ogni giorno) e servono al comando inverso `saas:crypt:upgrade-format:undo`.
- **Ricerca esatta sui campi cifrati**: accanto al valore cifrato (`tax_code_enc bytea`) si salva un **blind index**
  (`tax_code_bidx bytea`, impronta del valore normalizzato con una chiave dell'associazione). Serve per cercare un codice
  fiscale esatto e per `UNIQUE (org_id, tax_code_bidx)`. Lo stesso codice fiscale ha impronte diverse in associazioni
  diverse. Le impronte usano sempre la prima versione della chiave: una rotazione non le cambia.
- Le colonne binarie si scrivono nel formato esadecimale di PostgreSQL (`Cifratura\Contracts\Bytea`): PDO manderebbe
  i byte come testo.
- **Gerarchia delle chiavi**:
  - chiave principale sul **servizio chiavi**, un account separato (`saas/keyservice/`); ripiego: nel file d'ambiente;
  - una chiave dati per associazione (`core.org_keys`, con versione), salvata cifrata con la chiave principale e legata
    all'associazione; da lei si derivano sottochiavi separate per campi, impronte e file;
  - ogni associazione ha la sua chiave, anche con il database dedicato (la chiave resta nel database centrale);
  - il ruolo dell'applicazione legge e aggiunge chiavi, non le modifica né le cancella;
  - cancellare un'associazione = distruggere la sua chiave (`saas:keys:destroy`, solo per associazioni già
    cancellate, con il ruolo proprietario): da quel momento il servizio non apre più i dati cifrati; i backup già fatti
    restano apribili fino alla loro scadenza, entro 30 giorni ([CANCELLAZIONE_DATI.md](CANCELLAZIONE_DATI.md) §3 bis);
  - passare dalla chiave principale locale al servizio chiavi (o tornare) riavvolge le chiavi dati senza cambiarle
    (`saas:keys:rewrap`, con il ruolo proprietario): [CHIAVE_REMOTA.md](CHIAVE_REMOTA.md).
- **File allegati**: fuori dal database e fuori dalla cartella pubblica (disco `org_files`), cifrati con la chiave
  dell'associazione e legati al loro percorso. Nel database restano nome, dimensione, tipo e impronta SHA-256, che si
  ricontrolla alla lettura. Si scaricano solo come allegato, senza cache.
- **Limite**: sui campi cifrati non si fanno ricerche parziali, ordinamenti o filtri per intervallo (per esempio l'età
  dalla data di nascita). Se serviranno, si aggiunge un dato derivato in chiaro e meno preciso, deciso caso per caso.

## 6. Prima nota immutabile

Fatto in `app/Modules/Contabilita` (migrazione `create_accounting_tables`); le regole qui sotto valgono nel database.

- Il ruolo dell'applicazione ha **solo `INSERT` e `SELECT`** su `app.transactions`, più `UPDATE` sulla sola colonna
  `activity_id`: il centro di costo è un dato di analisi e si può correggere (incassi e spese non stornati, esercizio
  aperto, con traccia nel registro). Il trigger rifiuta ogni altra modifica insieme a quella.
- Lo storno passa da `app.void_transaction()` (`SECURITY DEFINER`), che scrive soltanto `voided_at`, `void_reason` e
  `voided_by`, e storna insieme il gruppo: le due righe di un giroconto, tutte le voci e la ricevuta di un incasso.
  Un trigger rifiuta qualunque altra modifica e qualunque cancellazione.
- Un **trigger** rifiuta i movimenti (e gli storni) fuori da un esercizio aperto e sui conti chiusi.
- **Numerazione delle ricevute senza buchi**: tabella `app.counters (org_id, kind, year, last_value)`, letta con
  `SELECT … FOR UPDATE` nella stessa transazione dell'emissione.
- **Saldi precalcolati** (`app.account_balances`), aggiornati dai trigger nella stessa transazione del movimento; il
  ruolo dell'applicazione li può solo leggere. Le fotografie mensili (`balance_snaps`) arriveranno con i fondi.

## 7. Registro delle azioni e GDPR

- `audit.log` **solo in aggiunta**: le migrazioni concedono all'applicazione solo `INSERT` e `SELECT`, ma la protezione
  non dipende dai permessi (il ruolo dell'applicazione può ereditarne altri dall'hosting): un trigger rifiuta modifiche
  e cancellazioni anche al proprietario, salvo la cancellazione definitiva di un'associazione (le sole sue righe, a
  blocchi, con la stessa regola dei movimenti della prima nota), e un trigger di istruzione rifiuta `TRUNCATE`, sulla
  tabella e su ogni partizione, a chi non è il proprietario. Lo stesso vale per `app.transactions`. Partizionato per mese (`audit.log_AAAA_MM`, più una partizione predefinita);
  le partizioni si preparano in anticipo con `saas:audit:partitions`, ogni mese da cron. Ogni partizione ha la sua RLS.
  Il mese nuovo nasce come tabella a sé e poi si aggancia (`ATTACH PARTITION`: il registro resta scrivibile); la
  partizione predefinita deve restare vuota, perché l'aggancio la rilegge con il blocco forte (`saas:health` lo controlla).
  Ogni partizione nuova ha il rifiuto del `TRUNCATE` prima dell'aggancio (`Audit\Log\TruncateGuard`, che crea anche la
  funzione se un database ne fosse senza); `saas:health` dà errore se `audit.log`, una sua partizione o `app.transactions`
  non hanno il loro trigger attivo.
- **Blocchi forti brevi**: migrazioni, partizioni e potatura del registro aspettano un blocco al massimo
  `SAAS_DB_LOCK_TIMEOUT` (3 secondi) e riprovano tre volte (`Core\Contracts\ShortLocks`): il backup orario tiene un
  blocco leggero su ogni tabella finché dura, e un'istruzione in fila dietro di lui fermerebbe tutte le query sulla
  tabella. `saas:migrate` aspetta anche la fine di un backup in corso (al massimo 8 minuti) e, se un database non
  riesce, migra gli altri e fallisce alla fine.
- **Catena sigillata**: ogni riga ha un numero progressivo per associazione e il sigillo
  `HMAC(chiave del registro, sigillo precedente · forma canonica della riga)`. La chiave del registro è derivata da quella
  dell'associazione, quindi sta dietro il servizio chiavi: chi altera il database senza la chiave non riesce a
  ricostruire una catena valida. `audit.heads` tiene la testa e, bloccata con `FOR UPDATE`, serializza le scritture
  della stessa associazione. Dentro una transazione le righe si raccolgono e si legano alla catena tutte insieme come
  ultima cosa prima della conferma (un blocco sulla testa, sempre dopo gli altri, e un inserimento multiplo); seguono
  la transazione: un punto di salvataggio annullato toglie le sue righe. Numero e ora si assegnano con la testa
  bloccata, quindi nella catena l'ordine dei numeri resta quello delle ore; l'ora vera dell'azione resta nei dettagli
  (`action_at`, coperta dal sigillo) e l'esportazione la mostra come «Quando». Fuori da una transazione la riga si
  scrive subito e `occurred_at` è l'ora dell'azione.
- **Indici del registro**: l'indice per scheda (`log_org_subject`) non lo usa nessuna lettura del codice (prova in
  `tests/Feature/Performance/SchemaMeasuresTest.php`) e si toglie in due tempi (H28). Primo tempo: la conferma in
  produzione con l'azione «indici-registro» della manutenzione del server (`saas:audit:index-usage`, con il ruolo dei
  backup: letture di ogni indice sommate sulle partizioni, per database, dall'ultimo azzeramento delle statistiche).
  Secondo tempo, solo con 0 letture su tutti i database e su un periodo sufficiente: la migrazione che lo toglie.
- **Verifica**: `saas:audit:verify` (ogni notte da cron) ricalcola la catena e indica il primo punto rotto: riga
  modificata, tolta, aggiunta o testa non corrispondente.
- **Copia della testa fuori dal database**: ogni giorno `saas:audit:heads:copy` salva nello spazio dei backup numero e
  sigillo di testa (e dell'ancora) di ogni associazione, in un file firmato con una chiave derivata da quella
  dell'applicazione. La verifica li confronta: una coda tagliata o un'ancora riscritta insieme alla testa non tornano.
  Se la copia si ferma per più di 3 giorni, la verifica esce con errore.
- **Registri di sicurezza** (accessi degli operatori, accessi dello staff): senza dati personali liberi (un accesso
  fallito salva l'email solo se ha forma di indirizzo, altrimenti una sigla) e, oltre 18 mesi, si cancellano
  (`saas:audit:prune-security`). `saas:audit:prune --before=…` a mano chiede conferma (o `--force`) e non scende sotto il
  limite di conservazione.
- **Cosa si registra**: creazione, modifica, cancellazione e ripristino delle schede (`Audit\Contracts\Audited`, con
  i nomi dei campi), inviti e ruoli degli operatori, apertura dell'associazione da parte di un operatore, e **le
  letture** dei dati sanitari (campi `Encrypted::class.':health'`, una riga per scheda e campo in ogni richiesta).
- **Mai valori personali nel registro**: chi (utente o sistema), cosa, su quale scheda, quali campi, da quale indirizzo.
  I dettagli ammettono solo testi brevi, interi, booleani e liste.
- **Eventi di sicurezza** degli utenti (accessi riusciti e falliti, blocchi, uscite, password, verifica in due
  passaggi): `core.security_events` nel database centrale, solo in aggiunta, senza catena. Per gli accessi falliti
  con un'email sconosciuta non si salva l'email.
- **Cancellazione su richiesta**: i dati anagrafici vengono resi anonimi (`anonymized_at`), i movimenti restano per
  l'obbligo di conservazione decennale (art. 2220 c.c.).

## 8. Partizioni, migrazioni e backup

- **Partizioni** solo dove servono: `audit.log`, code dei messaggi, presenze. Non sulle tabelle delle associazioni.
- **Migrazioni Laravel versionate**, eseguite con un solo comando su tutti i database (condiviso e dedicati):
  `php artisan saas:migrate`, sempre con il ruolo proprietario. Stanno nei moduli, in `Database/Migrations/Central`
  (solo `core`) e `Database/Migrations/Org` (tutti i database); dettagli in [SVILUPPO.md](SVILUPPO.md).
- Modifiche in due tempi: prima si **aggiunge** il nuovo, poi si **toglie** il vecchio. Nessun fermo durante gli
  aggiornamenti.
- **Autovacuum per tabella** (`Core/Database/Migrations/*/2027_01_10_*_autovacuum_per_table.php`): le tabelle piccole
  aggiornate di continuo (testa del registro, saldi, contatori, sessioni dei soci; nel condiviso sessioni, coda e cache)
  si puliscono dopo 100 righe morte più l'1%, senza pause; quelle solo in aggiunta (prima nota, ricevute, consensi,
  registri di sicurezza) dopo 1000 righe nuove più il 5%, congelando subito le righe. Una tabella nuova di uno dei due
  tipi si aggiunge a quelle liste in una migrazione nuova. `ALTER TABLE ... SET (autovacuum_...)` prende solo un blocco
  SHARE UPDATE EXCLUSIVE: letture, scritture e dump passano. La tabella partizionata del registro non accetta questi
  parametri: restano quelli di fabbrica sulle partizioni.
- **Sessioni** (`core.sessions`): solo quelle degli operatori e dell'amministrazione (servono a «Dispositivi collegati»,
  alla revoca e ai limiti di durata), senza indice su `last_activity` e con `fillfactor` 60 (aggiornamenti sul posto).
  Area soci e moduli pubblici (rotte con il marchio `cookie-session`, middleware `UseCookieSession`) tengono la
  sessione in un cookie cifrato: messaggi, scheda scelta e token CSRF, mai un accesso (quello del socio sta in
  `app.member_sessions`).
- **Dump orario** per database, cifrato prima di uscire dal server con la chiave pubblica dei backup, su uno spazio
  esterno in UE. Prova di ripristino ogni settimana sullo staging ([RILASCIO.md](RILASCIO.md) §3).

## 9. Moduli (dalle 64 tabelle del plugin)

| Modulo | Tabelle del plugin |
|---|---|
| Anagrafica | `people`, `guardians`, `member_levels`, `memberships`, `medical_certs`, `consents`, `documents`, `attachments` |
| Attività | `activities`, `activity_staff`, `enrollments`, `sessions`, `lesson_exceptions`, `attendance`, `waitlist`, `bookings` |
| Risorse | `resources`, `reservations`, `reservation_guests`, `resource_waitlist`, `items`, `loans` |
| Contabilità | `accounts`, `categories`, `transactions`, `fiscal_years`, `funds`, `fund_entries`, `expense_funds`, `budgets`, `budget_lines`, `cash_counts`, `balance_snaps`, `discrepancy_approvals`, `receipts`, `refunds`, `credit_entries`, `credit_charges` |
| Pagamenti online | `payments` (`woo_links` sparisce: i pagamenti passano direttamente dai gateway) |
| Vita associativa | `assemblies`, `assembly_attendees`, `assembly_proxies`, `minutes`, `polls`, `poll_options`, `poll_voters`, `poll_votes` |
| Comunicazioni | `broadcasts`, `broadcast_rcpt`, `sms_queue`, `push_subs`, `notices`, `reminders`, `newsletter_sync` |
| Enti | `affiliations`, `body_cards`, `conventions`, `insurance`, `fivepm`, `assoc_policies` |
| Sistema | `audit_log` (→ `audit.log`), `import_batches` |

Nuove per il SaaS: schema `core` (associazioni, utenti, ruoli, abbonamenti, chiavi, domini) e schema `sites`.

## 10. Decisioni sullo schema

1. **Prima nota in partita singola**, come oggi. La **partita doppia è un addon**: lo schema dei movimenti va pensato
   per poterla aggiungere senza riscrivere quello che c'è.
2. **Email in chiaro**: serve per l'accesso, gli invii e la ricerca parziale.
3. **Numero di tessera modificabile a mano**, come oggi, con il pulsante **"primo numero libero"**, che propone il numero
   più basso non ancora usato nell'associazione (riempie anche i buchi). Unicità garantita dal database:
   `UNIQUE (org_id, card_number)` sulle schede non cancellate.
4. **Chiavi primarie UUIDv7** (§2).
- **Tabelle di piattaforma** (`core.platform_staff`, `core.platform_audit`): non sono dati di un'associazione, quindi senza
  `org_id` e senza RLS, nello schema `core`. Il registro (`platform_audit`) concede al ruolo dell'applicazione solo `SELECT`
  e `INSERT`; lo staff non è collegato a `core.users` (altra persona, altro accesso). Un test lo verifica.
