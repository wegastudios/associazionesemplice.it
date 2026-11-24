# Rotazione delle chiavi: procedura

Cosa si cambia, quando e come, con i comandi che esistono oggi. Dove una rotazione non è ancora possibile, c'è scritto.
Il passaggio tra chiave locale e servizio chiavi (che **non** è una rotazione) è in [CHIAVE_REMOTA.md](CHIAVE_REMOTA.md);
le regole sulle chiavi in [DATABASE.md](DATABASE.md) §5; il servizio in [../keyservice/README.md](../keyservice/README.md).

## 1. Che chiavi ci sono

| Chiave | Dove sta | Cosa protegge | Si ruota? |
|---|---|---|---|
| **Chiave principale** | `config.ini` del servizio chiavi (o `SAAS_MASTER_KEY` se locale) e copia di recupero offline | avvolge le chiavi dati (`core.org_keys.wrapped_key`) | sì, §3 |
| **Coppia di firma SaaS ↔ servizio** | privata nell'ambiente del SaaS, pubblica in `config.ini` del servizio | chi può chiedere al servizio di aprire le chiavi | sì, §4 |
| **Coppia dei backup** | pubblica nel SaaS (`SAAS_BACKUP_PUBLIC_KEY`), privata offline e sullo staging | cifratura dei dump | sì, §5 |
| **Chiave dati dell'associazione** | in `core.org_keys`, avvolta con la chiave principale | campi cifrati, file, impronte di ricerca, sigillo del registro | **non ancora**, §6 |

## 2. Quando

- **Subito** se si sospetta che una chiave sia uscita: file d'ambiente o `config.ini` letti da chi non doveva, copia di
  recupero senza la sua frase, accesso all'hosting o al computer di chi gestisce da parte di terzi, allarme del servizio
  chiavi sulle aperture al minuto che non si spiega.
- **Quando una persona con accesso ai segreti se ne va**: firma e chiave principale.
- **A calendario**: la coppia di firma e quella dei backup una volta l'anno; la chiave principale ogni due anni o con
  un cambio di hosting. Le date si scrivono nel calendario dello staff.

Ogni rotazione si prova prima sullo staging e si fa in una finestra con `php artisan down`.

## 3. Chiave principale

Le chiavi dati non cambiano (§6): si riavvolgono con una chiave principale nuova. Due casi, entrambi con comandi che
esistono. Valgono prima e dopo il lancio: sono operazioni in finestra, con il gestionale in manutenzione.

### 3.1 Da una chiave locale a un'altra chiave locale (`SAAS_KEY_VAULT=local`)

La chiave vecchia si legge **solo** dal file d'ambiente (`SAAS_MASTER_KEY_OLD`): il comando non ha argomenti né
opzioni per le chiavi, perché le righe di comando si vedono nell'elenco dei processi. Nessun comando stampa chiavi.

1. Copia di recupero attuale (con la chiave vecchia) scaricata offline in due posti: `saas:keys:recovery`, poi
   `saas:keys:recovery-check` sullo stesso file. È la via di ritorno anche se il file d'ambiente va perso.
2. Prova generale sullo staging o sulla demo con lo stesso procedimento.
3. `php artisan saas:keys:generate`: la chiave nuova, da copiare nel file d'ambiente senza passare da chat o email.
   Nel file d'ambiente: `SAAS_MASTER_KEY_OLD` = il valore che oggi è in `SAAS_MASTER_KEY`, poi `SAAS_MASTER_KEY` =
   la chiave nuova. `php artisan config:cache`.
4. `php artisan down`.
5. `php artisan saas:keys:rewrap local-nuova --dry-run`. Prova tutto senza scrivere: controlla che ogni chiave dati si
   apra con la vecchia o con la nuova e che, riavvolta, si riapra uguale. Deve dire 0 associazioni non riuscite. Se
   dice «chiavi che non si aprono né con SAAS_MASTER_KEY_OLD né con SAAS_MASTER_KEY» non ha scritto nulla: la chiave
   vecchia nel file d'ambiente è sbagliata (o `config:cache` non è stato rifatto), si corregge e si ripete.
6. `php artisan saas:keys:rewrap local-nuova` (chiede conferma; `--force` per saltarla). Per associazione, in
   transazione: apre con la vecchia, riavvolge con la nuova, controlla, solo dopo scrive. Una riga `keys.rewrap` con
   `to = local-nuova` nel registro della piattaforma.
7. Di nuovo `saas:keys:rewrap local-nuova`: deve dire 0 riavvolte (le righe già a posto non si toccano).
8. `saas:health`, un accesso di prova a un'associazione che legga un campo cifrato, `php artisan up`.
9. Nuova copia di recupero con la chiave nuova (`saas:keys:recovery`) e sua verifica (`saas:keys:recovery-check`).
10. **La chiave vecchia resta offline per almeno 30 giorni, ma fuori dal file d'ambiente**: i backup fatti prima del
    passaggio hanno le chiavi avvolte con lei. Si toglie `SAAS_MASTER_KEY_OLD` dal file d'ambiente (`config:cache`)
    quando i controlli sono fatti, non si lascia lì «per sicurezza». Passati i 30 giorni si distrugge la copia di
    recupero vecchia.

**Ritorno** (la chiave nuova non va: errori nei controlli, chiave sbagliata copiata): stesso comando con le due chiavi
scambiate nel file d'ambiente. `SAAS_MASTER_KEY` = la chiave vecchia, `SAAS_MASTER_KEY_OLD` = la nuova, `config:cache`,
`saas:keys:rewrap local-nuova --dry-run`, poi senza `--dry-run`, poi di nuovo (0 riavvolte). Le chiavi dati delle associazioni
non si ricreano: cambia solo come sono avvolte. Finché la vecchia non è distrutta, il ritorno è sempre
possibile; la copia di recupero del punto 1 lo copre anche se il file d'ambiente è perso.

Se il comando si interrompe a metà (errore di rete al database, processo ucciso) non c'è nulla da disfare: ogni
associazione è una transazione, e ripetendo il comando si riavvolgono solo quelle rimaste (le altre risultano «già a
posto»). Se una chiave non si apre con nessuna delle due, il comando non scrive nulla finché non si è capito perché.

### 3.2 Da un servizio chiavi a un altro servizio chiavi

Non c'è un comando diretto: il passaggio è **servizio vecchio → chiave locale provvisoria → servizio nuovo**, e per il
tempo della finestra la chiave locale sta nel file d'ambiente (la piattaforma è «di prova», `RILASCIO.md`).

1. Copia di recupero attuale scaricata offline in due posti (`saas:keys:recovery --con-servizio`, poi
   `saas:keys:recovery-check`).
2. Servizio nuovo pronto (account o cartella nuova, `config.ini` con una chiave principale **nuova**, la stessa
   `client_public_key`, gli indirizzi ammessi). Il vecchio servizio resta acceso.
3. `php artisan saas:keys:generate`: la chiave locale provvisoria va in `SAAS_MASTER_KEY` (non è quella del servizio).
4. `php artisan down`, `saas:keys:rewrap local --dry-run` (0 non riuscite), `saas:keys:rewrap local`,
   `SAAS_KEY_VAULT=local`, `config:cache`, di nuovo `saas:keys:rewrap local` (0 riavvolte).
5. Nel file d'ambiente `SAAS_KEY_SERVICE_URL` del servizio nuovo; `config:cache`.
6. `saas:keys:rewrap remote --dry-run`, `saas:keys:rewrap remote`, `SAAS_KEY_VAULT=remote`, `config:cache`, di nuovo
   `saas:keys:rewrap remote` (0 riavvolte), `saas:health`, `php artisan up`.
7. Tolta `SAAS_MASTER_KEY` dal file d'ambiente, `config:cache`. Nuova copia di recupero con la chiave del servizio
   nuovo e sua verifica.
8. **La chiave principale vecchia resta offline per almeno 30 giorni**: i backup fatti prima del passaggio hanno le chiavi
   avvolte con lei. Passati i 30 giorni, si distruggono la copia di recupero vecchia e il `config.ini` del servizio vecchio.

Il ritorno al servizio vecchio, finché è acceso, è lo stesso percorso al contrario (`rewrap local`, poi `rewrap remote`
con l'indirizzo vecchio).

## 4. Coppia di firma SaaS ↔ servizio

Il servizio accetta **una sola** chiave pubblica: il cambio è un taglio netto, quindi in finestra.

1. `php artisan saas:keys:generate`: nuova coppia (la privata non si stampa in chat né si salva fuori dal file d'ambiente).
2. `php artisan down`. In `config.ini` del servizio: `client_public_key` nuova. Nel file d'ambiente del SaaS:
   `SAAS_KEY_SERVICE_SIGNING_KEY` nuova, `config:cache`.
3. `saas:keys:monitor` e `saas:health` devono dire ok; `php artisan up`.
4. Nuova copia di recupero (contiene la chiave di firma) e verifica; vecchia copia distrutta.

## 5. Coppia dei backup

1. Nuova coppia con `saas:keys:generate`. La pubblica in `SAAS_BACKUP_PUBLIC_KEY`, `config:cache`; la privata offline e
   sullo staging (prova di ripristino).
2. I dump già fatti si aprono **solo con la privata vecchia**: la si tiene finché esiste un dump che la richiede
   (conservazione: 48 ore di dump orari, poi uno al giorno per 30 giorni) e poi la si distrugge.
3. Il primo dump nuovo si prova con `saas:backup:restore-test` sullo staging.

## 6. Chiavi dati delle associazioni: cosa non c'è ancora

Oggi esiste solo la versione 1 di ogni chiave (`KeyRing::create`). Il formato dei dati cifrati porta già il numero di
versione, e `KeyRing::current` usa la più recente, quindi aggiungere una versione 2 e leggere i dati vecchi funzionerebbe.
Ma una rotazione completa richiede cose che oggi non ci sono:

- inserire la versione 2 (con il ruolo proprietario);
- **ricifrare** tutti i campi, tutti i file (anche quelli degli anni chiusi e delle fotografie) e le colonne cifrate di
  ogni modulo;
- le **impronte di ricerca** (`blindIndex`) e il **sigillo del registro** (`HmacSealer`) usano per scelta sempre la
  versione 1: ruotarli vuol dire ricalcolare le impronte e rifare la catena del registro;
- finché la versione 1 serve per impronte e sigillo, non si può distruggere.

Per questo oggi ruotare la chiave principale (§3) **non cambia le chiavi dati**: chi ha la chiave principale vecchia e un
dump fatto prima della rotazione apre ancora i dati di quel dump. Vale finché quei dump esistono (30 giorni); i dati
cifrati sul database vivo restano apribili con le stesse chiavi dati. Una ricifratura vera si valuta caso per caso con lo
sviluppo. Il formato che lega il testo cifrato alla riga (`0x02`, vedi DATABASE.md) è già in scrittura per soci e
impostazioni di pagamento: una ricifratura futura dovrà leggere entrambi i formati e scrivere con l'id della riga.
Le righe scritte prima si portano a `0x02` con `saas:crypt:upgrade-format` (procedura, copie d'appoggio per 30 giorni e
comando inverso in RILASCIO.md §3); il comando non cambia la chiave né la versione, solo il formato.

## 7. Dopo ogni rotazione

- `saas:health`, `saas:keys:monitor`, `saas:backup:restore-test` (staging).
- Una riga nel calendario dello staff con data e chiave ruotata (mai il valore).
- Controllare che nessuna copia vecchia sia rimasta nella cartella pubblica, nel repository, in chat o nelle email.
