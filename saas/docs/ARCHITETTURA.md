# SaaS: architettura dell'applicazione

Come è fatto il SaaS e come si collega a ciò che esiste già. Database in [DATABASE.md](DATABASE.md),
perimetro e pacchetti in [PERIMETRO.md](PERIMETRO.md).

## 1. Due sistemi

```
                 ┌──────────────── PORTALE (esiste) ──────────────────┐
 Cliente ──────▶ │ account cliente · piani · abbonamenti Stripe/PayPal│
                 │ licenze plugin · programma partner · ponte SDI     │
                 └──────┬───────────────────────────────┬────────────┘
                        │ diritti firmati (piano,        │ SSO "Gestisci
                        │ fascia, pacchetti, operatori)  │ abbonamento"
                        ▼                                ▼
                 ┌──────────────── SAAS (nuovo, Laravel) ─────────────┐
 Operatori ────▶ │ gestionale · area soci · generatore siti · API      │
 Soci ─────────▶ │ utenti propri, verifica in due passaggi, permessi   │
                 └──────┬──────────────────────────────────────────────┘
                        ▼
                 PostgreSQL 16 (condiviso + dedicati) · Redis (solo cache)
```

**Portale** (`portale/`): centro commerciale unico per plugin e SaaS. Account cliente, listino, abbonamenti,
cambi di piano, programma partner, ponte verso l'intermediario SDI, fatturazione dei nostri servizi. Si sviluppa
solo dalla sessione del portale; con il SaaS comunica tramite il contratto API del §11.

**SaaS** (`saas/`): il prodotto. Non parla con Stripe: riceve dal portale i **diritti firmati** (piano, fascia di
soci, pacchetti attivi, numero di operatori, scadenza), con lo stesso meccanismo del livello di licenza firmato del
plugin. Operatori e soci sono utenti del SaaS; solo chi gestisce l'abbonamento passa al portale, con un accesso unico
come quello verso il sito di supporto.

## 2. Monolite modulare (Laravel 13, PHP 8.3)

Una sola applicazione, divisa in moduli per dominio. Ogni modulo ha le sue regole, i suoi servizi, le sue rotte e le
sue migrazioni.

```
saas/app/Modules/
  Core/          associazioni, utenti, ruoli e permessi, diritti dal portale, domini
  Contabilita/   prima nota, conti, ricevute, rendiconto, fondi, chiusure
  Soci/          anagrafica, tesseramento, tutori, certificati, consensi
  Attivita/      corsi ed eventi come centri di costo
  Fatturazione/  fattura elettronica (XML nostro, invio tramite il ponte del portale)
  AreaSoci/      API e PWA dei soci
  Sito/          generatore AI e pubblicazione
  Audit/  Cifratura/  Import/  Export/
```

- Le regole del plugin (`*Rules.php`) si **copiano** nei moduli insieme ai loro test. Niente pacchetto comune: dovrebbe
  restare compatibile con PHP 7.4 (minimo del plugin) e frenerebbe il SaaS. La divergenza si controlla con gli stessi
  casi di test nei due progetti.
- I confini tra moduli si fanno rispettare con test di architettura: un modulo usa un altro solo dalla sua interfaccia
  pubblica.
- Qualità: PHPStan al livello massimo, Pint, Pest, controllo delle dipendenze in CI, revisioni di sicurezza come per il
  plugin.

## 3. Indirizzi e domini

| Indirizzo | Chi | Cosa |
|---|---|---|
| `app.associazionesemplice.it` | operatori, commercialisti | gestionale; cambio di associazione per chi ne segue più d'una |
| `nome.associazionesemplice.it` | soci e pubblico | sito (pacchetto Sito) e area soci (pacchetto Area soci) dell'associazione; incluso nel Sito |
| **dominio proprio** (`www.miaassociazione.it`) | soci e pubblico | come il sottodominio; extra a pagamento |

**Dominio proprio**
- Il cliente punta il dominio verso di noi (CNAME per `www`, record A per il dominio nudo), e ne dimostra il possesso
  con un record TXT di verifica.
- Il dominio si aggiunge all'account via cPanel UAPI e riceve il certificato da AutoSSL (Let's Encrypt).
- Con il dominio attivo, il sottodominio rimanda a quello (un solo indirizzo canonico, meglio per la SEO); l'area soci
  segue il dominio proprio.
- Controllo periodico: se il dominio non punta più a noi, l'avviso arriva al cliente e il sottodominio torna canonico.
- Il gestionale resta sempre su `app.associazionesemplice.it`.

**Sottodomini riservati.** I nomi non sono tutti liberi: alcuni servono a noi, altri vanno protetti da abusi.

| Tipo | Esempi | Perché |
|---|---|---|
| Tecnici | `app`, `www`, `api`, `admin`, `portale`, `supporto`, `demo`, `mail`, `cdn`, `static`, `status`, `docs`, `blog`, `staging`, `dev`, `test` | servizi nostri |
| **Categorie** (landing nostre) | `asd`, `ssd`, `aps`, `odv`, `onlus`, `ets`, `terzosettore`, `volontariato`, `sport`, `cultura`, `circolo`, `circoli`, `polisportiva`, `proloco`, `associazioni`, `associazione` | landing mirate: per esempio `onlus.associazionesemplice.it` per una campagna rivolta esplicitamente alle onlus |
| Marchio e ingannevoli | `associazionesemplice`, `login`, `accesso`, `pagamenti`, `banca`, `pec`, `agenziaentrate`, `inps`, `paypal`, `stripe` | phishing e confusione |

- L'elenco è gestito dal pannello, non scritto nel codice: si aggiunge un nome quando nasce una nuova campagna.
- Sono bloccate anche le **varianti** dei nomi riservati: con trattini, cifre, plurali (`onlus-1`, `asd2`, `a-s-d`).
- Formato: lettere minuscole, cifre e trattini, 3–40 caratteri, niente trattino iniziale o finale.

## 4. Richiesta tipo

1. L'indirizzo individua l'associazione: dalla sessione e dall'utente per gli operatori, dal sottodominio o dal
   dominio proprio per i soci.
2. La connessione si sceglie da `core.orgs`: database condiviso o dedicato.
3. All'inizio di ogni richiesta e di ogni lavoro in coda si imposta `app.org_id` con `set_config`; **a fine richiesta
   si azzera**, perché con le connessioni persistenti resterebbe alla richiesta successiva. Senza `app.org_id` la
   Row-Level Security non mostra nulla: l'errore va sempre dal lato sicuro (policy in [DATABASE.md](DATABASE.md) §3).
4. Permessi derivati dai dati (come `Access` nel plugin), con ruoli configurabili per segretari e consiglieri.

## 5. Interfaccia

- **Gestionale**: Inertia + **Vue 3** + Tailwind 4. Compilazione con Vite su GitHub Actions (sul server non c'è Node).
- **Area soci**: PWA leggera su API REST versionata (`/api/v1`), token Sanctum. La stessa API serve i blocchi
  dinamici del sito e le integrazioni future.
- **Accesso**: Laravel Fortify. Verifica in due passaggi (TOTP) obbligatoria per gli operatori; passkey come passo
  successivo.

## 6. Lavori in background

- Code su **Postgres** (driver `database`). Redis (64 MB, rifiuta le scritture quando è pieno) solo per cache e
  limiti di frequenza, sempre con scadenza.
- Cron ogni minuto: `/usr/local/bin/php artisan schedule:run` (da cron `php` è la versione CGI) e
  `queue:work --stop-when-empty --max-time=50`.
- Lavori: email, PDF delle ricevute, fatture elettroniche, import, generazione dei siti, dump orari, migrazioni sui
  database dedicati, verifica dei domini propri.

## 7. Chiavi di cifratura

- La chiave principale sta sul **servizio chiavi** (`saas/keyservice/`), in un account cPanel separato, solo HTTPS. Il
  SaaS gli chiede di cifrare o decifrare la chiave dati di un'associazione: richieste firmate con Ed25519 (il servizio
  ha solo la chiave pubblica), con ora e nonce contro le ripetizioni, da indirizzi ammessi. Il servizio registra ogni
  operazione (associazione, indirizzo), mai le chiavi.
- La chiave sbloccata resta **solo in memoria**: nel processo e, se c'è APCu, per 5 minuti. Mai su disco, mai in Redis.
  Senza APCu si fa una chiamata al servizio per richiesta che usa dati cifrati.
- Se il servizio non risponde, i dati cifrati restano chiusi: l'errore non contiene dettagli.
- Ripiego (`SAAS_KEY_VAULT=local`): chiave principale nel file d'ambiente fuori dalla cartella pubblica. Protegge i dump
  del database, non chi entra nell'account del SaaS. Si usa in sviluppo e nei test.
- Le chiavi si generano con `php artisan saas:keys:generate`; della chiave principale va tenuta una copia offline.

## 8. File

Fuori dalla cartella pubblica, cifrati per associazione, serviti solo dopo il controllo dei permessi. Si usa il
filesystem di Laravel, così più avanti si passa a uno spazio compatibile S3 senza cambiare il codice.

## 9. Siti

L'AI produce una **specifica JSON**; il generatore la trasforma in **HTML statico** con la nostra libreria di
componenti, in una cartella per associazione, servita dal sottodominio (host virtuale jolly) o dal dominio proprio,
più la CDN. I blocchi dinamici leggono l'API. La chiamata all'AI parte da un lavoro in coda. Dettagli in un documento
dedicato al builder.

## 10. Ambienti, rilascio, controllo

| Ambiente | Dove |
|---|---|
| Sviluppo | in locale (Laravel Herd o Docker, con Postgres 16) |
| Staging | sub-account cPanel separato, con il suo database |
| Produzione | sub-account `associazionesemplice` |

- Rilascio da GitHub Actions: test, analisi, build, `composer install --no-dev`, invio via SSH in una cartella di
  release con link simbolico (ritorno alla versione precedente immediato), migrazioni su tutti i database con un
  comando dedicato.
- Controllo: log strutturati, tracciamento degli errori (Sentry, regione UE), monitoraggio di disponibilità, log delle
  query lente, obiettivo p95 sotto i 200 ms.

## 11. Contratto con il portale

Definito in [CONTRATTO_PORTALE.md](CONTRATTO_PORTALE.md); lato SaaS fatto (`app/Modules/Portale`), lato portale da
sviluppare nella sessione del portale.

| Direzione | Cosa |
|---|---|
| portale → SaaS | creazione dell'associazione e invito del titolare (`POST /api/portale/v1/orgs`) |
| portale → SaaS | diritti firmati (stato, scadenza, soci, operatori, pacchetti, partner) a ogni cambio (`PUT …/entitlements`) |
| SaaS → portale | consumi giornalieri: soci, operatori, fatture elettroniche (`POST /api/saas/v1/usage`) |
| SaaS → portale | accesso unico «Gestisci abbonamento» per il titolare (`/saas/accedi?t=…`, 60 secondi) |
| SaaS → portale | fatture elettroniche tramite il ponte SDI: fase 2 |

Tutto con JWT EdDSA di vita breve, come le attestazioni delle licenze, con chiavi separate nei due sensi.

## 12. Decisioni prese

1. Codice in una cartella `saas/` di questo repository, accanto a `supporto/` e `portale/`.
2. Regole del plugin copiate con i test, niente pacchetto comune.
3. Vue 3 per il gestionale.
4. Sottodominio per associazione con sito e area soci; **dominio proprio** come extra; **sottodomini riservati**
   per servizi, landing di categoria e protezione da abusi.
