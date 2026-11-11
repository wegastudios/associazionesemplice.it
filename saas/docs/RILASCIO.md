# SaaS: rilascio, server, backup

Come il SaaS arriva sul server e come si tiene in piedi: versioni, cron e code, dump orari cifrati con prova di
ripristino. Gli script sono in `saas/deploy/`, il workflow in `.github/workflows/saas-deploy.yml`.

## 1. Sul server

Sub-account cPanel del reseller (produzione `associazionesemplice`, staging a parte, con database e chiavi propri).

```
~/saas/
  releases/20261104104457-e34d23686c/   una cartella per versione (si tengono le ultime 5) (la cartella releases è 711: il web server deve attraversarla)
  current -> releases/…                 versione in uso
  shared/.env                           configurazione (permessi 600), da deploy/env.server.example
  shared/dedicated-dbs.json             database dedicati (se ce ne sono)
  shared/storage/                       log, sessioni, file cifrati delle associazioni (app/org-files), temporanei
  incoming/                             pacchetti in arrivo (si cancellano dopo l'estrazione)
```

- **Cartella pubblica** del sottodominio `app.associazionesemplice.it` (cPanel → Domini): `saas/current/public`.
  Tutto il resto è fuori dal web.
- **Area soci** (pacchetto Area soci): `https://<slug>.associazionesemplice.it/area`. Serve un **sottodominio jolly**
  `*.associazionesemplice.it` con la stessa cartella pubblica `saas/current/public` e il certificato jolly (AutoSSL
  con validazione DNS), e nel `.env` `SAAS_MEMBERS_DOMAIN=associazionesemplice.it` (è anche il valore predefinito).
  I nomi riservati (`app`, `www`, `portale`…) non diventano mai associazioni; un sottodominio senza associazione
  attiva con il pacchetto risponde 404. Le email di accesso dei soci partono con la posta della piattaforma.
- **Cron**, una sola riga (la aggiunge il rilascio):
  `* * * * * cd ~/saas/current && /usr/local/bin/php artisan schedule:run >> ~/saas/shared/storage/logs/cron.log 2>&1`.
  Lo scheduler fa il resto:

| Quando | Cosa |
|---|---|
| ogni minuto | `queue:work --stop-when-empty --max-time=50` (code su Postgres) |
| ogni ora, :07 | `saas:backup:dump` (produzione e staging) |
| ogni giorno | `saas:audit:verify` 03:37 · `saas:portal:usage` 04:23 · `saas:backup:prune` 05:11 · `saas:portal:suspend-expired` 00:41 · `queue:prune-failed` 05:47 |
| ogni mese, il 1° | `saas:audit:partitions` 02:13 |
| ogni settimana, lunedì | `saas:backup:restore-test` 06:17 (solo staging, con la chiave privata dei backup) |

### Impostazioni tecniche

Posta (SMTP), spazio dei backup (SFTP), servizio chiavi e cache si impostano **solo da `shared/.env`** sul server: il
SaaS (`app.associazionesemplice.it`) è solo il gestionale delle singole associazioni e non ha pagine di
amministrazione della piattaforma. Contratto, upgrade, limiti, piani e utenti attivi e non si gestiscono dal portale
(`associazionesemplice.it/saas`), che parla con il SaaS con richieste firmate ([CONTRATTO_PORTALE.md](CONTRATTO_PORTALE.md) §2.3).

(`core.platform_settings` e `PlatformSettings` restano nel codice, ma senza pagina nessuno li scrive: le impostazioni
valgono quelle del file d'ambiente.)

Ripieghi automatici (`saas:health` li mostra come note):

| Manca | Cosa succede |
|---|---|
| Redis (estensione o socket) | cache sul database (`core.cache`) |
| server SMTP | le email finiscono nel log |
| server SFTP dei backup | i dump restano in `shared/storage/app/backups` |
| servizio chiavi (indirizzo e chiave di firma) | chiave principale locale (`SAAS_MASTER_KEY`): piattaforma **di prova**, il portale riceve 503 `key_service_not_configured` e non crea associazioni; quelle di prova si creano con `saas:org:create` |

## 2. Rilascio

`.github/workflows/saas-deploy.yml`:

1. **controlli** (lo stesso workflow `saas.yml`: Pint, PHPStan, test, controllo delle dipendenze);
2. **pacchetto** (`deploy/build.sh`): dipendenze senza quelle di sviluppo, CSS e JavaScript compilati (sul server
   non c'è Node), niente test, documenti, `.env` o dati; impronta SHA-256;
3. **invio via SSH** nell'ambiente scelto e `deploy/remote.sh` sul server:
   impronta → controllo di salute → `saas:migrate` → partizioni → cache (`optimize`) → **cambio del link `current`**
   → riavvio delle code → controllo di salute; se il controllo fallisce si torna da soli alla versione precedente.

Quando parte: **solo a mano** (Actions → «SaaS · rilascio»), solo da `main`, con l'approvazione obbligatoria
dell'ambiente. Per ora c'è **un solo ambiente**: finché non ci sono clienti operativi staging e produzione coincidono.
Quando ci sarà uno staging separato, si potrà farlo partire da solo a ogni push su `main`.

Con un solo ambiente, la **prova di ripristino** (che non gira dove sta la produzione, perché lì non deve esserci la
chiave privata dei backup) si fa a mano dal computer di chi gestisce, con la coppia di chiavi dei backup in un `.env`
locale e il database di prova locale: `php artisan saas:backup:restore-test`.

Segreti per ambiente (GitHub → Settings → Environments → `staging` e `production`): `SAAS_SSH_HOST`, `SAAS_SSH_PORT`,
`SAAS_SSH_USER`, `SAAS_SSH_PRIVATE_KEY` (chiave Ed25519 solo per il rilascio, autorizzata solo su quel sub-account),
`SAAS_SSH_KNOWN_HOSTS` (riga di `ssh-keyscan -p PORTA HOST`, controllata a mano). Le chiavi dell'applicazione non
passano da GitHub: stanno solo in `shared/.env`.

**Ritorno alla versione precedente**: `~/saas/current/deploy/rollback.sh` (immediato). Le migrazioni non si annullano:
sono in due tempi, quindi la versione precedente funziona anche con lo schema nuovo.

**Migrazioni in due tempi** (REGOLE_DI_LAVORO.md): una versione aggiunge colonne e tabelle nuove senza togliere le
vecchie; la versione successiva, quando nessun codice in uso le legge più, le toglie. Durante il cambio, per al più un
paio di minuti (cache dei percorsi di PHP), qualche richiesta può essere servita ancora dalla versione precedente:
anche per questo lo schema deve andare bene a entrambe.

**Controllo di salute**: `php artisan saas:health` (database e migrazioni di tutti i database, servizio chiavi, spazio
dei file, spazio dei backup). Dall'esterno: `https://app.associazionesemplice.it/up`.

## 3. Backup

| Livello | Cosa | Dove | Perdita massima |
|---|---|---|---|
| SupportHost | JetBackup giornaliero, 30 giorni | backup in UE del fornitore | un giorno |
| **Nostro** | dump orario di ogni database, cifrato | **spazio esterno in UE** (SFTP, per esempio uno Storage Box) | **un'ora** |

- `pg_dump` gira con il **ruolo dei backup**: solo lettura, nessun permesso di scrittura, ammesso esplicitamente dalle
  policy della Row-Level Security (su cPanel non esiste un ruolo con `BYPASSRLS`). I dati sensibili sono già cifrati
  campo per campo: il dump li contiene cifrati, e per aprirli serve comunque il servizio chiavi.
- Il dump si cifra **sul server, prima di uscire**, con la **chiave pubblica** dei backup: il server cifra ma non può
  decifrare. La coppia completa sta solo sullo staging (per la prova di ripristino) e in una copia offline.
- I file temporanei passano da `shared/storage/app/tmp` (0700), mai da `/tmp`, che su un hosting condiviso può essere
  comune ad altri account.
- Accanto a ogni dump c'è un file `.json` con impronta e numero di righe per tabella (nessun dato).
- Conservazione: tutti i dump delle ultime 48 ore, poi il primo di ogni giorno per 30 giorni.
- **Prova di ripristino** (`saas:backup:restore-test`, ogni settimana sullo staging): scarica l'ultimo dump di
  produzione, ne controlla l'impronta, lo decifra, lo ripristina nel database di prova (che viene svuotato) e
  confronta le righe di ogni tabella con quelle registrate. Si rifiuta di girare in produzione.
- Stesso percorso, a mano, per un ripristino vero: `pg_restore` del dump decifrato nel database di destinazione con il
  ruolo proprietario (vedi la procedura nel §5).

## 4. Prima di andare su staging

| Cosa | Chi |
|---|---|
| Sub-account cPanel di staging con PHP 8.3 (estensioni `pdo_pgsql`, `sodium`, `intl`, `mbstring`, `redis`) | Luca |
| Database PostgreSQL e tre utenti (applicazione, proprietario, backup), più il database di prova per il ripristino | Luca, da cPanel |
| **`pg_dump` e `pg_restore` della versione 16** sul server: c'è solo il client 13, che non sa fare il dump di un server 16 | da chiedere a SupportHost |
| Sottodominio di staging con HTTPS, cartella pubblica `saas/current/public` | Luca |
| Account cPanel separato per il servizio chiavi, con sottodominio HTTPS (`keyservice/README.md`) | Luca |
| Spazio esterno in UE per i backup, con accesso SFTP a chiave | da scegliere |
| Chiave SSH solo per il rilascio, autorizzata sul sub-account; segreti nell'ambiente `staging` di GitHub | Luca |
| `shared/.env` compilato da `deploy/env.server.example`; chiavi da `php artisan saas:keys:generate` (una serie per ambiente) | Luca |
| Primo rilascio, poi `php artisan saas:org:create` per le due associazioni di prova del primo traguardo | dal workflow, poi a mano |

## 5. Ripristino vero (procedura)

1. Mettere in manutenzione: `php artisan down` dalla versione in uso.
2. Scaricare il dump scelto e il suo `.json` dallo spazio dei backup su una macchina con la coppia di chiavi dei backup
   (staging o computer di chi gestisce).
3. Decifrare e ripristinare con `pg_restore --exit-on-error --dbname=…` con l'utente proprietario, nel database di
   destinazione vuoto (stesso nome degli utenti: i permessi del dump valgono così come sono).
4. `php artisan saas:health` e `php artisan saas:audit:verify`.
5. `php artisan up`.

Il comando `saas:backup:restore-test` fa i passi 2–3 sul database di prova: è la stessa procedura, provata ogni
settimana.

## Catalogo pubblico

`GET https://app.associazionesemplice.it/api/catalogo/v1` è pubblico e firmato (nessun accesso). Sul server serve solo che
`SAAS_PORTAL_SIGNING_KEY` e `SAAS_PORTAL_URL` siano impostate (la stessa chiave del resto del contratto). Per cambiare
prezzi o piani oggi si modifica `config/catalogo.php` e si rilascia; con l'amministrazione (`/saas/admin`) si pubblicherà
dal pannello senza rilascio. Dopo ogni rilascio che cambia il catalogo: `curl -sI …/api/catalogo/v1` deve mostrare il
nuovo `ETag`. Nessun segreto in più, nessun cron nuovo.

## Proxy di associazionesemplice.it/saas

`/saas/admin`, `/saas/staff` e `/saas/api` sono serviti dal SaaS sotto l'indirizzo del portale: il web server del portale li inoltra a `app.associazionesemplice.it` **con lo stesso percorso** (nessuna riscrittura), aggiungendo `X-Forwarded-Host`, `-Proto` e `-For`. Tutto il resto di `/saas` resta del portale.

- **Fiducia negli inoltri**: gli `X-Forwarded-*` valgono solo se la richiesta arriva da un indirizzo di `SAAS_TRUSTED_PROXIES` (nel `.env` del server, separati da virgole; vuoto = nessuno si fida, come in sviluppo). Ci va l'indirizzo da cui il server contatta se stesso, non un intervallo largo.
- **Cookie**: l'amministrazione usa un cookie proprio (`__Secure-as_admin`, percorso `/saas/admin`) **senza dominio**, anche se `SESSION_DOMAIN` è quello del gestionale: così vale solo per `associazionesemplice.it`.
- **`.env`**: se c'è `SAAS_ADMIN_PATH`, va tolto (il predefinito è `saas/admin`). `APP_URL` e `SESSION_DOMAIN` restano quelli del gestionale.
- **Tornare indietro**: si ripristina la copia dell'`.htaccess` di `public_html` fatta prima del cambio e si svuota `SAAS_TRUSTED_PROXIES`; il rinvio del portale a `app./admin` torna a funzionare da solo.

**Aggiornamento (11/11/2026): `SAAS_PUBLIC_URL`.** Dietro il proxy il web server dell'app sostituisce l'indirizzo del client e il SaaS non vede l'`X-Forwarded-Host`, quindi i moduli di `/saas/admin` puntavano ad `app.` (e la CSP `form-action 'self'` li bloccava). Gli indirizzi delle pagine `/saas/admin` e `/saas/staff` ora partono da `SAAS_PUBLIC_URL` (`https://associazionesemplice.it`), un valore fisso: nessuna intestazione della richiesta può spostarli. `SAAS_TRUSTED_PROXIES` resta ma non è più necessario per questo.
