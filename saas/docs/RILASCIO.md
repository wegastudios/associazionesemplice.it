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
| ogni minuto | Lavoratori delle code su Postgres: `queue:work --queue=mail-urgent --max-time=55` (solo codici e link di conferma, sulla connessione a ripresa breve; c'è sempre e non conta nel massimo) e al massimo `SAAS_QUEUE_MAX_WORKERS` altri (1-2, di base 2; il server ha 25 processi PHP in tutto): `queue:work --queue=mail-urgent,default,mail-bulk --max-time=50 --timeout=60` (lavori brevi) · `queue:work database-long --queue=long --timeout=1800` (copia completa e import: ripresi solo dopo 2100 s, `DB_QUEUE_LONG_RETRY_AFTER` può solo alzarlo, mai mentre girano; uno alla volta, quindi un giro di import aspetta la fine di una copia completa in corso, fino a 30 minuti; parte solo se la coda `long` ha un lavoro). Con 1 un solo lavoratore su `database-long` per `default`, `mail-bulk` e `long` (mai per `mail-urgent`, che resta a ripresa breve). Tutti con `--stop-when-empty` |
| ogni 5 minuti | `saas:keys:monitor` (solo con `SAAS_KEY_VAULT=remote`; email all'amministrazione, una per guasto) |
| ogni ora, :07 | `saas:backup:dump` (produzione e staging) |
| ogni giorno | **`saas:night` 02:23**: controlli notturni uno dopo l'altro, senza accavallarsi (H36): `saas:receipts:verify` (verifica completa dei PDF delle ricevute, 500 per associazione, prima le mai verificate: «Verifica delle ricevute» ne mostra l'esito senza rileggere i file) · `saas:crypt:upgrade-format:prune` 04:07 (copie d'appoggio del passaggio di formato oltre 30 giorni) · `saas:audit:heads:copy` (testa del registro nello spazio dei backup) · `saas:audit:verify --rotate` (dal punto di controllo; un settimo delle associazioni a notte ripercorre la catena intera) · `saas:balances:verify` · la domenica `saas:archive:verify`; poi, a parte: `saas:portal:usage` 04:23 · `saas:files:usage` 04:51 (contatore dello spazio dei file di ogni associazione riallineato al disco) · `saas:backup:prune` 05:11 · `saas:portal:suspend-expired` 00:41 · `queue:prune-failed` 05:47 (falliti oltre 7 giorni) · `saas:member-access:prune` 06:13 (link, codici e sessioni dell'area soci scaduti da 30 giorni, a blocchi) |
| ogni mese, il 1° | `saas:audit:partitions` 02:13 |
| ogni settimana, lunedì | `saas:backup:restore-test` 06:17 (solo staging, con la chiave privata dei backup) · `saas:audit:prune-security` 03:27 (accessi di operatori e staff oltre 18 mesi) |
| ogni 15 minuti | `saas:tmp:purge --minutes=60` (dump in chiaro e archivi dell'esportazione rimasti in `storage/app/tmp`; copie di sicurezza complete, cifrate, oltre `SAAS_EXPORT_KEEP_HOURS`, 6 ore, e archivi in chiaro di lavori interrotti in `storage/app/exports` oltre 35 minuti) |

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

In `APP_ENV=production` i ripieghi sulla posta (log) e sulla chiave principale locale **non sono ammessi**: `saas:health`
fallisce e il rilascio torna alla versione precedente. Con il driver `sftp` dei backup l'impronta dell'host
(`SAAS_BACKUP_SFTP_FINGERPRINT`) è obbligatoria in ogni ambiente. La chiave principale di prova dei test non si accetta
fuori da `local` e `testing`.

**Stato della produzione (letto dal .env di produzione il 24/11/2026; la diagnosi in sola lettura è l'azione `variabili` di «SaaS · manutenzione server»):**
`SAAS_KEY_VAULT=remote` con `SAAS_KEY_SERVICE_URL` e `SAAS_MASTER_KEY` impostate; `SAAS_PURGE_ENABLED=1` (cancellazione
notturna accesa); `SAAS_AUDIT_PRUNE_ENABLED` acceso (letto il 20/11/2026); `SAAS_UNIFIED_LOGIN`, `SAAS_STAFF_SSO` e
`SAAS_STAFF_ISSUER` = `true`; `SAAS_UNIFIED_OPERATORS` non impostato (vale il predefinito, spento); `SAAS_NOTICES_ENABLED=0`
(avvisi email del gestionale spenti); `SAAS_REQUIRE_TWO_FACTOR=false` (**spenta sul server: da riaccendere prima dei dati
veri**); `SAAS_BACKUP_PUBLIC_KEY`, `SAAS_PG_DUMP` e `SAAS_PG_RESTORE` impostate; PostgreSQL 16.15 e pg_dump 16.15; APCu attivo e
condiviso; Redis in uso; posta SMTP già attiva. Non verificato in questa revisione: le variabili SFTP dei backup, `DB_RESTORE_DATABASE`,
Emailit.

## 2. Rilascio

`.github/workflows/saas-deploy.yml`:

1. **controlli** (lo stesso workflow `saas.yml`: Pint, PHPStan, test, controllo delle dipendenze);
2. **pacchetto** (`deploy/build.sh`): dipendenze senza quelle di sviluppo, CSS e JavaScript compilati (sul server
   non c'è Node), niente test, documenti, `.env` o dati; impronta SHA-256;
3. **invio via SSH** nell'ambiente scelto e `deploy/remote.sh` sul server:
   impronta → controllo di salute → `saas:migrate` → partizioni → cache (`optimize`) → `saas:catalog:align` (catalogo allineato alla configurazione) → **cambio del link `current`**
   → riavvio delle code → **`saas:web:refresh`** (OPcache e cache dei percorsi del PHP web azzerati con la rotta firmata
   `POST /api/portale/v2/runtime/reset`, poi due richieste di riscaldamento: `/up` e `/api/catalogo/v1` (nessuna apre una sessione); al meglio, se non
   risponde il rilascio prosegue) → controllo di salute; se il controllo fallisce si torna da soli alla versione precedente.

Quando parte: **solo a mano** (Actions → «SaaS · rilascio»), solo da `main`, con l'approvazione obbligatoria
dell'ambiente. Per ora c'è **un solo ambiente**: finché non ci sono clienti operativi staging e produzione coincidono.
Quando ci sarà uno staging separato, si potrà farlo partire da solo a ogni push su `main`.

Con un solo ambiente, la **prova di ripristino** (che non gira dove sta la produzione, perché lì non deve esserci la
chiave privata dei backup) si fa a mano dal computer di chi gestisce, con la coppia di chiavi dei backup in un `.env`
locale e il database di prova locale: `php artisan saas:backup:restore-test`.

**Ogni ambiente si rilascia solo dal ramo `main`**: il workflow rifiuta ogni altro ramo (il passo «Rilascio» non parte).
Il pacchetto e lo script `remote.sh` che girano sul server vengono dal ramo scelto, quindi un ramo qualunque non deve
poter arrivare al server nemmeno per prova.

**Stato su GitHub (verificato il 24/11/2026)**: esiste solo l'ambiente `production`, con `main` come unico ramo
ammesso e **senza revisori obbligatori**: il rilascio non aspetta clic, coerente con «CI verde = si unisce e si mette
online». Gli ambienti `demo` e `staging` non esistono (nessun segreto, nessun rilascio da lì). Al titolare non resta
nessun passo su GitHub. Se un giorno si crea `demo` o `staging`: stessa regola, solo `main`
(Settings → Environments → Deployment branches → Selected branches and tags → `main`).

**Scelta da prendere al lancio** (non adesso): se attivare **Required reviewers** su `production`. Ogni rilascio
resterebbe fermo finché il titolare non lo approva con un clic; «Prevent self-review» resterebbe spento finché è
l'unico revisore. Oggi è spento di proposito.

Segreti per ambiente (GitHub → Settings → Environments → `production`): `SAAS_SSH_HOST`, `SAAS_SSH_PORT`,
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
Durante il rilascio il log del workflow (pubblico) riporta solo l'esito e il numero di avvisi; l'uscita completa viene
salvata sul server in `shared/health-<data>.txt` (permessi 600, ultimi 5 file) e si legge via SSH.
Se `crontab` non è disponibile sul server, lo scheduler va configurato a mano con la riga indicata in questo documento.

**Saldi dei conti e input «ignora-saldi».** Il controllo notturno `saas:balances:verify` confronta il saldo di ogni
conto con saldo di partenza più movimenti non stornati; se c'è un conto diverso, la voce «saldi dei conti» di
`saas:health` è un ERRORE e ferma il rilascio (dettaglio di associazione e conto solo in `shared/saldi-<data>.txt`).
Quando serve proprio un rilascio per correggere quel difetto:

1. Actions → «SaaS · rilascio» → spuntare **ignora-saldi** (di norma è spento, e va lasciato spento per tutti gli
   altri rilasci).
2. Con l'input acceso `saas:health` gira con `--ignore-balances`: **solo** la voce «saldi dei conti» diventa un avviso;
   ogni altro errore (database, migrazioni, servizio chiavi, configurazione…) continua a fermare il rilascio e a
   riportare alla versione precedente.
3. Il rilascio lo annota in due posti: nel log del workflow (avviso «Rilascio con ignora-saldi, lanciato da
   `<utente GitHub>`» e, dal server, la riga «ATTENZIONE: controllo dei saldi dei conti IGNORATO per questo rilascio,
   lanciato da `<utente>`») e nel registro dell'amministrazione (`core.platform_audit`, azione
   `deploy.balances_check_ignored`, con utente e revisione), con `php artisan saas:deploy:ignore-balances <utente> <revisione>`.
   Se l'annotazione non riesce, il rilascio si ferma prima di toccare qualcosa.
4. Dopo il rilascio: `php artisan saas:balances:verify` sul server e poi `saas:health` senza opzioni, per vedere che
   la voce sia tornata «ok». Niente si corregge da solo: la correzione la fa la versione rilasciata.

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
- Il dump è **un solo flusso** (H35): `pg_dump` → cifratura → spazio dei backup, senza file in chiaro né cifrati sul disco del server; compressione `zstd` (`SAAS_BACKUP_COMPRESSION`, `gzip:6` per tornare indietro se il `pg_dump` del server non ha zstd), priorità bassa (`nice`/`ionice` se il server li ammette, `SAAS_BACKUP_LOW_PRIORITY=false` per toglierli). Il formato cifrato non cambia. Se `pg_dump` fallisce il file parziale si cancella. Per la prova di ripristino, i file temporanei passano da `shared/storage/app/tmp` (0700), mai da `/tmp`, che su un hosting condiviso può essere
  comune ad altri account.
- Accanto a ogni dump c'è un file `.json` con impronta e numero di righe per tabella (nessun dato). Dice anche la `compression` usata e `compression_fallback` (vero se è scattato il ripiego su gzip), e ha sempre `estimated` (anche vuoto); le descrizioni vecchie senza questi campi si leggono ancora. Le righe sono **stimate dalle statistiche** per le tabelle sopra 50.000 righe (`estimated` le elenca) e contate davvero sotto; la prova di ripristino conta sempre davvero: uguali per le tabelle contate, dello stesso ordine di grandezza (da metà al doppio, o entro 5.000 righe) per le stimate.
- **Database, ora e ambiente stanno dentro il file cifrato** (formato 2, dato associato di ogni blocco): la prova di
  ripristino rifiuta un dump il cui database, ambiente o ora non corrispondono a nome del file e descrizione, o con l'ora
  nel futuro. **I dump in formato 2 si aprono solo con il codice nuovo**: prima di tornare a una versione precedente del
  gestionale (`rollback.sh`) si conserva una copia del codice attuale (o almeno del comando di ripristino) da usare per
  ripristinare, perché il codice vecchio non sa leggerli. I dump nel formato 1 (fino a questa versione) si leggono ancora, con un avviso: non si può controllare di che
  database e di che ora sono. Si rifiuta anche se il database di prova coincide con uno dei veri.
- **Campi cifrati legati alla riga (`0x02`)**: da questa versione i campi dei soci e delle impostazioni di pagamento si
  scrivono nel formato legato alla riga. **Il codice precedente alla lettura doppia non apre le righe `0x02`**: per i dati
  scritti o modificati dopo questo rilascio il ritorno (`rollback.sh`) a una versione precedente non è più possibile senza
  perdere l'accesso a quei campi. I dump fatti prima, con righe `0x01`, si aprono con il codice nuovo. In caso di
  emergenza, `SAAS_ROW_BOUND_FORMAT=false` riporta le nuove scritture a `0x01` (le righe già `0x02` restano leggibili solo
  dal codice nuovo).
- **Passaggio delle righe vecchie a `0x02`** (`saas:crypt:upgrade-format`). Procedura:
  1. **Prova**: azione `formato-dry-run` del workflow «SaaS · manutenzione server» (o `php artisan saas:crypt:upgrade-format
     --dry-run`). Non scrive nulla e stampa solo conteggi per associazione e tabella: righe da convertire, già `0x02`,
     campi che verrebbero salvati in appoggio, righe che non si decifrano (con tabella, id della riga e colonna, mai valori).
     Esce con errore se anche un solo campo non si decifra: va chiarito prima del passaggio vero.
  2. **Passaggio**: `php artisan saas:crypt:upgrade-format` (`--org=<id>` per una sola associazione). Una associazione per
     volta, ognuna in una transazione sua con le righe bloccate (`FOR UPDATE`); si può ripetere (le righe già `0x02` si
     saltano). Se in una associazione un campo non si decifra, quell'associazione non cambia, le altre vanno avanti e il
     comando esce con errore. Per ogni campo decifra il vecchio testo, lo ricifra con l'id della riga, verifica che si
     riapra identico e solo allora lo sostituisce. Le impronte di ricerca (`*_bidx`) non cambiano. Restano `0x01`
     le fotografie del libro soci, le ricevute, gli anni chiusi, le richieste di adesione e i certificati.
  3. **Copie d'appoggio**: prima di sostituire un campo, il vecchio testo cifrato (`0x01`) va in `app.crypt_format_backups`,
     nello stesso database dell'associazione (stessa Row-Level Security delle altre tabelle di `app`; solo testo cifrato
     e l'impronta SHA-256 del nuovo, nessun dato in chiaro). Si tengono **30 giorni**: ogni giorno alle 04:07
     `saas:crypt:upgrade-format:prune` le toglie e scrive una riga nel registro delle azioni dell'associazione.
  4. **Ritorno**: `php artisan saas:crypt:upgrade-format:undo` (`--org=`, `--dry-run`) rimette i vecchi `0x01` dalle copie,
     ma solo dove la riga è ancora quella scritta dal passaggio (confronto con l'impronta salvata); un campo modificato nel
     frattempo si salta e il comando lo dice. Dopo i 30 giorni non c'è più nulla da rimettere.
  5. Ogni esecuzione vera scrive una riga nel registro delle azioni di ogni associazione (`crypto.format_upgraded`,
     `crypto.format_failed`, `crypto.format_restored`, `crypto.format_pruned`: solo conteggi) e una nel registro della
     piattaforma. Il comando usa la connessione dell'applicazione dentro il contesto di ogni associazione, non quella del
     proprietario: lavora soltanto sulle righe dell'associazione corrente, sul database condiviso e sui dedicati.

  Il passaggio vero si lancia a mano sul server dopo l'esito della prova; non ha un'azione propria nel workflow.
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
| **`pg_dump` e `pg_restore` della versione 16** sul server (il client 13 non sa fare il dump di un server 16) | **fatto**: `SAAS_PG_DUMP` e `SAAS_PG_RESTORE` impostate, pg_dump 16.15 (letto dal .env di produzione il 24/11/2026) |
| Sottodominio di staging con HTTPS, cartella pubblica `saas/current/public` | Luca |
| Account cPanel separato per il servizio chiavi, con sottodominio HTTPS (`keyservice/README.md`) | Luca (in uso in produzione, .env letto il 24/11/2026: `SAAS_KEY_VAULT=remote`) |
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
- **Segreto condiviso (`SAAS_PROXY_SECRET`)**: il modo preferito. Il proxy del portale manda un segreto di almeno 32 caratteri nell'intestazione `X-Saas-Proxy-Secret` (stesso valore nel `.env` del SaaS e nella configurazione del portale, `saas_proxy.secret`); solo con quello il SaaS legge l'indirizzo del visitatore da `X-Forwarded-For`. Quando il segreto c'è, `SAAS_TRUSTED_PROXIES` non si usa più. L'host inoltrato (`X-Forwarded-Host`) non si legge mai, e un elenco con `*` non è accettato.
- **Cookie**: l'amministrazione usa un cookie proprio (`__Secure-as_admin`, percorso `/saas/admin`) **senza dominio**, anche se `SESSION_DOMAIN` è quello del gestionale: così vale solo per `associazionesemplice.it`.
- **`.env`**: se c'è `SAAS_ADMIN_PATH`, va tolto (il predefinito è `saas/admin`). `APP_URL` e `SESSION_DOMAIN` restano quelli del gestionale.
- **Tornare indietro**: si ripristina la copia dell'`.htaccess` di `public_html` fatta prima del cambio e si svuota `SAAS_TRUSTED_PROXIES`; il rinvio del portale a `app./admin` torna a funzionare da solo.

**Aggiornamento (11/11/2026): `SAAS_PUBLIC_URL`.** Dietro il proxy il web server dell'app sostituisce l'indirizzo del client e il SaaS non vede l'`X-Forwarded-Host`, quindi i moduli di `/saas/admin` puntavano ad `app.` (e la CSP `form-action 'self'` li bloccava). Gli indirizzi delle pagine `/saas/admin` e `/saas/staff` ora partono da `SAAS_PUBLIC_URL` (`https://associazionesemplice.it`), un valore fisso: nessuna intestazione della richiesta può spostarli. `SAAS_TRUSTED_PROXIES` resta ma non è più necessario per questo.

## Manutenzione del server: come leggere le uscite

Le azioni del workflow «SaaS · manutenzione server» (`saas/deploy/ops.sh`, lanciato via SSH) scrivono nel log del workflow, che è pubblico, solo l'esito (ok o errore) e il percorso del file. Ogni stato reale del server (variabili e interruttori, versioni, impostazioni, ruoli e database, privilegi, conteggi, coda, cancellazioni, identificativi, indirizzi) non compare nel log: viene salvato in un file di testo sul server, in `shared/`, e nel log resta solo «<azione>: salvato in …/shared/<azione>-20261121T103000.txt».

- **File**: `<azione>-<data UTC>.txt` (per esempio `misure-20261121T103000.txt`), creato con permessi 600, leggibile solo dall'utente del sub-account. Per ogni azione se ne conservano al massimo 5: i più vecchi si cancellano da soli.
- **Lettura**: con SSH, mai nel log del workflow: `ssh <utente>@<server> 'cat ~/saas/shared/misure-<data>.txt'` (il percorso esatto è quello scritto nel log; se il sub-account usa un'altra base, vale `$SAAS_BASE`). Per il più recente: `ls -t ~/saas/shared/misure-*.txt | head -1`.
- **Cosa va su file**: `diagnosi` (stato delle variabili, client PostgreSQL e ultimi errori), `variabili` e `interruttori` (vedi sotto), `ciclo-vita` (elenchi, verifica delle catene, interruttori), `prova-cancellazione`, `avvisa-catalogo`, `bozza-listino`, `conta-fotografie` (elenco per associazione), `cancellazioni` (registro, stato per associazione, righe del log), `coda` (numeri della coda), `verifica-registro`, `permessi-backup`, `permessi-db` (controllo di salute ed eventuali errori), `permessi-diagnosi`, `formato-dry-run`, `sblocca-utente`, `prova-recupero` (memoria), `accendi-ciclo-vita`, `spegni-cancellazione`, `spegni-avvisi`, `imposta-segreto-proxy` e `svuota-proxy-fidati` (stato dopo la modifica), `db-proprieta` (sola lettura, solo SELECT sul catalogo col ruolo proprietario: proprietari del database e degli oggetti, ruoli di gruppo di app/backup/owner, privilegi e chi li ha concessi, privilegi predefiniti, CREATE sul database, RLS e FORCE RLS per tabella, app NOINHERIT), `misure` e `db-connection-limit-60` / `db-connection-limit-off` (picco e ripartizione per ruolo).
- **Azione `variabili`** (sola lettura): nel file `variabili-<data>.txt` c'è prima lo stato di ogni variabile del `.env` (mai il valore, salvo interruttori e scelte non segrete; i commenti in riga del `.env`, come `# …`, non contano: una riga con solo un commento è «vuota») e poi la scelta effettiva letta dalla configurazione che l'applicazione ha caricato (per esempio `saas.crypto.vault = remote`, `mail.default = smtp`, `filesystems.disks.backups.driver = sftp`); per host, utenti, chiavi e impronte solo «impostata» o «vuota». `interruttori` fa lo stesso per le sole scelte non segrete, in `interruttori-<data>.txt`.
- **Limite di connessioni del database** (`db-connection-limit-60`, `db-connection-limit-off`): imposta o toglie il limite di connessioni del solo database del gestionale, col ruolo proprietario. Con `db-connection-limit-60` l'azione misura prima il picco di connessioni (20 campioni in circa 20 secondi) e non applica nulla se picco più un margine di 10 (backup, ripristino, migrazioni al rilascio) supera 60. Nel log compare solo l'esito («limite connessioni: applicato», «NON applicato» o «ERRORE»); picco, limiti e ripartizione per ruolo sono nel file `limite-connessioni-<data>.txt` in `shared/`. Il comando inverso è `db-connection-limit-off` (limite -1, nessun limite). Gli altri database e le impostazioni di memoria e di pianificazione non cambiano.
- **Cosa resta nel log**: l'esito di ogni azione (ok o errore, con lo stesso codice di uscita di prima) e il percorso del file. Niente conteggi, niente stato di variabili, interruttori, permessi o coda.
- **Copie del `.env`** (`shared/.env.bak-<AAAAMMGGHHMMSS>`): le azioni che cambiano una variabile (`chiave-catalogo`, `percorso-pgdump`, `imposta-segreto-proxy`, `svuota-proxy-fidati`) ne fanno una copia prima (permessi 600, come il `.env`). La rotazione ne conserva al massimo 2 (le più recenti) e cancella quelle di oltre 7 giorni; l'età si legge dal nome. Tocca solo file con quel nome esatto: mai il `.env` attivo, mai altri file (né in `shared/` né fuori, come `~/wega-support/.env.prima-portal-login`). **La rotazione parte da sola solo dopo l'azione `pulisci-copie-env`**, per non cancellare di sorpresa le copie già presenti: prima di questo passaggio le azioni non cancellano niente.
  - `copie-env`: sola lettura. Dice se la rotazione automatica è accesa e quali copie cancellerebbe (solo i nomi).
  - `pulisci-copie-env`: applica la rotazione una volta, accende quella automatica e scrive nel log quante copie ha cancellato. Da lanciare solo col via del titolare dopo aver visto l'elenco di `copie-env`. Con le copie esistenti può cancellarne subito la maggior parte (al massimo restano le 2 più recenti, e solo se non oltre 7 giorni).
  - Prova: `saas/tests/shell/ops-env-bak.sh` (lanciata dalla CI) esegue la rotazione su una cartella temporanea.
- **Cosa fanno le azioni**: invariato; è cambiato solo dove finisce l'uscita. I file non vanno copiati altrove né allegati a segnalazioni, e si cancellano a mano quando non servono più.
