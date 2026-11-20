# Rotazione delle chiavi: procedura

Cosa si cambia, quando e come, con i comandi che esistono oggi. Dove una rotazione non è ancora possibile, c'è scritto.
Il passaggio tra chiave locale e servizio chiavi (che **non** è una rotazione) è in [CHIAVE_REMOTA.md](CHIAVE_REMOTA.md);
le regole sulle chiavi in [DATABASE.md](DATABASE.md) §5; il servizio in [../keyservice/README.md](../keyservice/README.md).

## 1. Che chiavi ci sono

| Chiave | Dove sta | Cosa protegge | Si ruota? |
|---|---|---|---|
| **Chiave principale** | `config.ini` del servizio chiavi e copia di recupero offline | avvolge le chiavi dati (`core.org_keys.wrapped_key`) | sì, §3 |
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

Con i comandi attuali il passaggio è **servizio vecchio → chiave locale provvisoria → servizio nuovo**: per il tempo
della finestra la chiave locale sta nel file d'ambiente e la piattaforma è «di prova» (`RILASCIO.md`).

1. Copia di recupero attuale scaricata offline in due posti (`saas:keys:recovery`, poi `saas:keys:recovery-check`).
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
   avvolte con lei (come per il passaggio dalla chiave locale). Passati i 30 giorni, si distruggono la copia di recupero
   vecchia e il `config.ini` del servizio vecchio.

Limite di oggi: non c'è un comando diretto «servizio vecchio → servizio nuovo»; la chiave locale provvisoria nel file
d'ambiente è il prezzo. Un comando che apre dal vecchio e avvolge sul nuovo senza passare dal file d'ambiente è
una modifica da progettare (`KeyMigration::rewrap` prende già due custodi qualunque).

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

## 7. Dopo ogni rotazione

- `saas:health`, `saas:keys:monitor`, `saas:backup:restore-test` (staging).
- Una riga nel calendario dello staff con data e chiave ruotata (mai il valore).
- Controllare che nessuna copia vecchia sia rimasta nella cartella pubblica, nel repository, in chat o nelle email.
