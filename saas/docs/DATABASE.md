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
| Redis | Istanza dedicata via socket, 64 MB, rifiuta le scritture quando è piena: **solo cache e limiti di frequenza, sempre con scadenza**. Code dei lavori su Postgres. |
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
| proprietario (migrazioni) | `php artisan migrate` | proprietario delle tabelle; anche lui soggetto alla RLS (`FORCE`) |
| applicazione | richieste web e code | solo i permessi necessari, tabella per tabella; mai `BYPASSRLS` né superuser |

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
  USING      (org_id = NULLIF(current_setting('app.org_id', true), '')::uuid)
  WITH CHECK (org_id = NULLIF(current_setting('app.org_id', true), '')::uuid);
```

(Prova della RLS con `FORCE` e `set_config` fatta sul server SupportHost il 2026-11-02: funziona.)

## 4. Tipi di dato e indici

- **Importi**: `bigint` in centesimi con `CHECK`. Mai `numeric` o `float` per il denaro.
- **Istanti**: `timestamptz`. **Date contabili, di competenza, di nascita**: `date`.
- **Stati e tipi**: `text` con `CHECK` sui valori ammessi (più semplice da far evolvere degli enum nativi).
- **`jsonb`** solo per dati davvero variabili (righe di un pagamento, impostazioni).
- **Ogni chiave esterna ha un indice**, che inizia con `org_id`.
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
    cancellate, con il ruolo proprietario): i dati cifrati, anche nei backup, non si aprono più.
- **File allegati**: fuori dal database e fuori dalla cartella pubblica (disco `org_files`), cifrati con la chiave
  dell'associazione e legati al loro percorso. Nel database restano nome, dimensione, tipo e impronta SHA-256, che si
  ricontrolla alla lettura. Si scaricano solo come allegato, senza cache.
- **Limite**: sui campi cifrati non si fanno ricerche parziali, ordinamenti o filtri per intervallo (per esempio l'età
  dalla data di nascita). Se serviranno, si aggiunge un dato derivato in chiaro e meno preciso, deciso caso per caso.

## 6. Prima nota immutabile

- Il ruolo dell'applicazione ha **solo `INSERT` e `SELECT`** su `app.transactions`.
- Lo storno passa da una funzione `SECURITY DEFINER` che può scrivere soltanto `voided_at` e `void_reason`.
- Un **trigger** rifiuta i movimenti datati in un esercizio chiuso.
- **Numerazione delle ricevute senza buchi**: tabella `app.counters (org_id, kind, year, last_value)`, letta con
  `SELECT … FOR UPDATE` nella stessa transazione dell'emissione.
- **Saldi precalcolati** (`app.account_balances`), aggiornati nella stessa transazione del movimento, più le fotografie
  mensili (`balance_snaps`). Nessuna pagina somma l'intera prima nota.

## 7. Registro delle azioni e GDPR

- `audit.log` **solo in aggiunta**: l'applicazione ha solo `INSERT` e `SELECT`; un trigger rifiuta modifiche e
  cancellazioni anche al proprietario. Partizionato per mese (`audit.log_AAAA_MM`, più una partizione predefinita);
  le partizioni si preparano in anticipo con `saas:audit:partitions`, ogni mese da cron. Ogni partizione ha la sua RLS.
- **Catena sigillata**: ogni riga ha un numero progressivo per associazione e il sigillo
  `HMAC(chiave del registro, sigillo precedente · forma canonica della riga)`. La chiave del registro è derivata da quella
  dell'associazione, quindi sta dietro il servizio chiavi: chi altera il database senza la chiave non riesce a
  ricostruire una catena valida. `audit.heads` tiene la testa e, bloccata con `FOR UPDATE`, serializza le scritture
  della stessa associazione.
- **Verifica**: `saas:audit:verify` (ogni notte da cron) ricalcola la catena e indica il primo punto rotto: riga
  modificata, tolta, aggiunta o testa non corrispondente.
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
- **Dump orario** per database, cifrato prima di uscire dal server, su uno spazio esterno in UE. Prova di ripristino
  periodica.

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
