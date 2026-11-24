# Demo del gestionale: piano operativo

La demo del gestionale vive su **un solo indirizzo**, `https://demosaas.associazionesemplice.it`, che è insieme:

- l'indirizzo dell'app (`APP_URL`): accesso degli operatori e gestionale;
- lo slug «demosaas» sotto `associazionesemplice.it` (`SAAS_MEMBERS_DOMAIN`): quindi anche l'area soci, `/area`;
- l'indirizzo dei moduli pubblici `/dona/demosaas` e `/adesione/demosaas`.

Il resto del contesto (cosa mostra la demo, dati finti, utenti pubblici) è in `PIATTAFORMA.md` §6. Questo documento dice come si mette in piedi.
Il gestionale resta **nascosto**: nessun collegamento dal portale o dalla vendita, nessun interruttore di produzione cambia.

## Account: oggi separato, la regola resta generale

Oggi la demo del gestionale ha un **account cPanel suo** (`demosaasassociaz`), diverso da quello della demo WordPress (`demoassociazione`). Le istruzioni valgono identiche se un giorno i due dividessero un account: in quel caso si aggiungono le regole della sezione «Se l'account è condiviso».

In ogni caso la demo del gestionale sta in `~/saas-demo`; mai in `~/saas` (la produzione, anche se un giorno stesse sullo stesso account).

## Requisiti

- Dominio principale `demosaas.associazionesemplice.it` dell'account, con HTTPS valido (i cookie `__Host-` lo richiedono). La cartella pubblica è `public_html` com'è, preparata come spiegato sotto («soluzione B»).
- PHP 8.3 (CLI e web) e PostgreSQL 16, come in produzione. **Niente Redis**: `CACHE_STORE=database`.
- Un database PostgreSQL e **tre utenti con nomi e password diversi**: applicazione (`DB_USERNAME`), proprietario (`DB_OWNER_USERNAME`, migrazioni e azzeramento), sola lettura (`DB_BACKUP_USERNAME`). Diversi da quelli di ogni altro posto.
- Cron di cPanel, una riga sola, scritta dal rilascio: `* * * * * cd ~/saas-demo/current && php artisan schedule:run` (`remote.sh` la aggiunge se manca; se `crontab` non c'è, la metti tu).
- La licenza della demo è dedicata: la demo non usa nessuna chiave o licenza di produzione (`SAAS_KEY_VAULT=local`, associazione con piano «demo» interno; nessun collegamento col portale).

## L'account della demo e la cartella pubblica

**Account**: `demosaasassociaz` (cPanel), separato da quello della demo WordPress. Stesso server e stessa porta SSH della produzione; la chiave per entrare a mano sta sul PC del titolare, fuori da OneDrive (`C:\Users\lucar\.ssh\demosaas_ed25519`). Il deploy usa `SAAS_SSH_HOST`, `SAAS_SSH_PORT`, `SAAS_SSH_USER` dell'ambiente GitHub `demo`; l'utente è `demosaasassociaz`. Codice, `.env`, storage e versioni stanno in `~/saas-demo`.

**PHP**: CLI 8.3.36 con `pdo_pgsql`, `sodium`, `intl`, `gd`, `pcntl` (senza Redis né APCu: non servono, la cache è nel database); PHP del dominio 8.3.36 (`ea-php83`). `psql`/`pg_dump` 16. Il numero di versione di PHP non compare mai in `/up` né in altre pagine pubbliche.

**Il problema**: il document root è `/home/demosaasassociaz/public_html`, dominio **principale** dell'account: non si cambia da cPanel. L'applicazione però vuole come cartella pubblica `~/saas-demo/current/public`, che cambia a ogni rilascio.

| | A. `public_html` = collegamento a `current/public` | **B. `public_html` vera, con file stabili** (scelta) |
|---|---|---|
| Come | si toglie la cartella e si crea il collegamento | `public_html` resta com'è; dentro: `.htaccess` (blocchi di cPanel intatti + il nostro blocco), `index.php` di avvio, collegamenti a `assets`, `build`, `favicon.*`, `.well-known` vero |
| AutoSSL / DCV | scrive in `public_html/.well-known` = dentro la versione: sparisce al rilascio successivo e il rinnovo del certificato può fallire | `.well-known` è una cartella vera, mai toccata |
| `.htaccess`, `.user.ini`, `php.ini` di cPanel (log errori, `ea-php83`) | spariscono o finiscono nella versione | restano dove cPanel li ha messi |
| Cosa si vede dal web | tutto `public/` della versione | solo ciò che c'è in `public_html`: niente `.env`, `storage`, `vendor`, `composer.*`, versioni (stanno fuori) |
| Ritorno indietro | rimettere la cartella | `ripristina`: `.htaccess` e `index.php` come prima, via i collegamenti |

**Perché B**: la A mette `.well-known` e i file di cPanel nella cartella che il rilascio sostituisce; la B tiene tutto ciò che cPanel gestisce dove sta e porta fuori dal web tutto il resto.

### Come funziona la B

- **`index.php` di avvio**: file vero di poche righe, uguale a ogni rilascio: `clearstatcache(true);` poi `require dirname(__DIR__).'/saas-demo/current/public/index.php';`. Il file incluso ha `__DIR__` = il percorso **reale** della versione (PHP risolve i collegamenti), quindi `index.php` dell'app (che usa `__DIR__.'/../vendor/autoload.php'`, `../bootstrap/app.php`, `../storage/framework/maintenance.php`) trova `vendor`, `bootstrap` e `storage` della versione in uso, senza nessuna modifica. Non si usa un collegamento a `index.php`: con un collegamento il percorso reale dipende dal server; con il `require` no.
- **Cache dei percorsi**: PHP e OPcache ricordano il percorso risolto di `current`. `clearstatcache(true)` azzera la prima a ogni richiesta; per OPcache, **dopo ogni cambio di `current`** si lancia `php artisan saas:web:refresh` (la lancia già `deploy/remote.sh` subito dopo il cambio; a mano: `cd ~/saas-demo/current && /usr/local/bin/php artisan saas:web:refresh`; con `--no-warm` solo l'azzeramento). Il comando chiama una rotta firmata dell'app che azzera OPcache e la cache dei percorsi del PHP web, poi riscalda le pagine principali. Lo si lancia anche dopo `ripristina` o dopo un ritorno a una versione precedente (`rollback.sh`).
- **Collegamenti**: `assets`, `build`, `favicon.ico`, `favicon.svg` (solo quelli che la versione ha) puntano a `~/saas-demo/current/public/…`: il percorso del collegamento non cambia perché `current` è esso stesso un collegamento. `robots.txt` non è un file: lo risponde l'app (nella demo: `Disallow: /`).
- **`.htaccess`**: gli si **aggiunge in fondo** il blocco di `deploy/demo-htaccess.conf` tra i marcatori `# BEGIN AssociazioneSemplice demo` / `# END AssociazioneSemplice demo`. Tutto il resto, compresi `# php -- BEGIN cPanel-generated handler … END` (`AddHandler ea-php83`) e `php_value error_log` / `log_errors`, resta com'è e dov'è. Ripetere l'installazione sostituisce solo il nostro blocco.
- **Mai toccati**: `.user.ini`, `php.ini`, `.well-known`, `cgi-bin`. Un file o cartella vera con il nome di un collegamento non viene sovrascritto (avviso, lo sposti tu).
- **Divieti**: il blocco nega solo file di configurazione e di servizio con nome preciso: `.user.ini`, `php.ini`, `.htaccess`, `.env*`, `composer.json`/`composer.lock`, `package.json`/`package-lock.json`, `artisan`, `phpunit.xml` e ogni `*.log`, `*.sh`, `*.sql`, `*.bak`; poi ogni file o cartella nascosti (tranne `.well-known`), e disattiva elenco cartelle e MultiViews. **Non** nega gli altri `.json`, `.md`, `.yml`, `.lock`: in `public_html` possono servire a manifest e file statici (per esempio `manifest.webmanifest`, `manifest.json`, `site.webmanifest` dell'area soci o di `build/`). Il controllo (CI, `DemoWebrootTest`) verifica che un `manifest.json` non sia negato e che `composer.json` e `package.json` lo siano. Il resto che non è un file vero va all'app (404 dell'app).

### Comandi esatti (solo quando il titolare dà il via; niente si installa prima)

Dopo il primo rilascio (serve `~/saas-demo/current`). Sul server, come `demosaasassociaz`:

```
bash ~/saas-demo/current/deploy/demo-webroot.sh stato        # sola lettura
bash ~/saas-demo/current/deploy/demo-webroot.sh installa     # copia di sicurezza, poi scrive .htaccess, index.php, collegamenti
bash ~/saas-demo/current/deploy/demo-webroot.sh prova        # richiede dal dominio ciò che non deve rispondere
```

La copia di sicurezza è in `~/saas-demo/shared/webroot-backup/<data>/` (solo `.htaccess` e `index.php`, com'erano **prima** della nostra installazione).

**Ritorno indietro**:

```
bash ~/saas-demo/current/deploy/demo-webroot.sh ripristina   # .htaccess e index.php come prima, via i collegamenti
cd ~/saas-demo/current && /usr/local/bin/php artisan saas:web:refresh --no-warm
```

`.user.ini`, `php.ini` e `.well-known` non vengono mai toccati né in un senso né nell'altro. Dopo `installa` si fa `prova`; dopo `ripristina` si controlla che il dominio risponda come prima.

### Chiusura temporanea (demo non ancora aperta)

Finché la demo non è pronta, `public_html` si può chiudere a tutti tranne che a un indirizzo IP:

```
bash ~/saas-demo/current/deploy/demo-webroot.sh chiudi            # solo l'IP della sessione SSH in corso
bash ~/saas-demo/current/deploy/demo-webroot.sh chiudi 203.0.113.7  # oppure un IP (IPv4 o IPv6) a scelta
bash ~/saas-demo/current/deploy/demo-webroot.sh apri              # toglie la chiusura
bash ~/saas-demo/current/deploy/demo-webroot.sh stato             # dice se è chiuso e a quale indirizzo
```

La chiusura è una regola di riscrittura messa **in cima** a `.htaccess`, tra i marcatori `# BEGIN chiusura temporanea` e `# END chiusura temporanea`; `.well-known` resta raggiungibile (AutoSSL). Chiamare `chiudi` due volte lascia un solo blocco; i blocchi di cPanel e quello della demo non si toccano. Ogni `chiudi` e `apri` fa una copia di `.htaccess` in `~/saas-demo/shared/webroot-backup/chiusura/`. **Non si usa `Require ip`**: su questo server la direttiva in `.htaccess` non viene rispettata (verificato), il sito resterebbe aperto. `installa` e `ripristina` non aprono il sito se era chiuso. Con il sito chiuso `prova` non dichiara «ok»: i controlli partono dal server e darebbero 403 su tutto, quindi la prova vera si fa da un computer con l'indirizzo autorizzato, oppure dopo `apri`.

### Cosa NON deve essere raggiungibile dal web

`prova` richiede sul dominio, con `curl`, questi indirizzi e si aspetta **400, 403 o 404** (mai 200 né un reindirizzamento): `/.env`, `/.env.backup`, `/.git/config`, `/.user.ini`, `/php.ini`, `/.htaccess`, `/composer.json`, `/composer.lock`, `/artisan`, `/vendor/autoload.php`, `/storage/logs/laravel.log`, `/storage`, `/bootstrap/app.php`, `/app`, `/config/app.php`, `/database`, `/deploy/remote.sh`, `/releases`, `/saas-demo`, `/shared/.env`, `/current`, `/public/index.php`, `/../.env`, `/%2e%2e/.env`, `/index.php/.env`. E si aspetta **200** da `/up`, `/accesso`, `/area/accesso`, `/robots.txt` (con `Disallow: /`). Esce con errore se anche solo uno non torna. Va lanciata dopo ogni installazione e quando si cambia qualcosa in `public_html`.

Prove automatiche (CI): `tests/Feature/Core/DemoWebrootTest.php` prova il blocco `.htaccess`, l'installazione su una finta `public_html` con i blocchi di cPanel, la ripetizione senza duplicati, il ritorno indietro identico, che `.user.ini`, `php.ini` e `.well-known` restano uguali, e `chiudi`/`apri` (un solo blocco, apertura identica all'originale, installa e ripristina che non aprono). Il test sul dominio vero (`prova`) si fa a mano dopo l'installazione.

## Posta: niente esce

Con `SAAS_DEMO=true` (ambiente non di produzione) l'app, all'avvio, rende **«array» ogni mailer** (`DemoMailGuard`): `MAIL_MAILER`, le impostazioni della piattaforma, `smtp`, `emailit`, `failover`, `ses`, `log`… il messaggio resta in memoria per la durata della richiesta e poi sparisce; `SAAS_MAIL_PRIMARY` torna `cpanel`. Non «log»: il visitatore scrive indirizzi veri nei moduli e codici e link dell'area soci arriverebbero in `storage/logs/laravel.log`. Anche se per errore nel `.env` ci fossero `MAIL_MAILER=smtp` o Emailit, inviti, codici e link non escono e non restano scritti. Il controllo del `.env` pretende `MAIL_MAILER=array` e segnala il resto.

Il registro non tiene niente dei visitatori: `LOG_LEVEL=warning`, `LOG_DAILY_DAYS=2` e l'**azzeramento notturno svuota anche `storage/logs`** (ogni `*.log`), i file temporanei (`storage/app/tmp`) e la cache su disco, oltre al database e ai file cifrati (solo se la cartella sta sotto `saas-demo`). Prove: `tests/Feature/Core/DemoMailTest.php` (nessun invio, nessun errore con smtp finto, nel log di prova non restano indirizzi, codici né link; l'azzeramento toglie i log solo nella cartella della demo).

## Cosa gira nello scheduler della demo e cosa no

Con `SAAS_DEMO=true` (ambiente non di produzione) i lavori pianificati sono un **elenco di permessi** (`DemoSchedule`): quello che non c'è è spento, anche un lavoro nuovo di domani, finché non lo si mette in elenco. `tests/Feature/Core/DemoScheduleTest.php` fallisce se un lavoro pianificato non è classificato né tra quelli che girano né tra quelli spenti.

| Gira | Perché |
|---|---|
| lavoratori delle code (`queue:work`), `queue:prune-failed` | solo lavori interni; la posta è scartata |
| `saas:tmp:purge`, `saas:files:usage`, `saas:crypt:upgrade-format:prune` | file di lavoro e contatori del suo disco |
| `saas:demo:reset` (03:30) | azzeramento notturno |
| `saas:audit:partitions`, `saas:audit:prune-security`, `saas:receipts:fix`, `saas:applications:prune`, `saas:member-access:prune` | lavorano solo sul suo database |

| **Non** gira | Perché |
|---|---|
| `saas:backup:dump`, `saas:backup:prune`, `saas:backup:restore-test` | niente copie di sicurezza (comunque pianificate solo in produzione e staging) |
| `saas:keys:monitor` | servizio chiavi: la demo usa la chiave locale |
| `saas:reminders:send` | promemoria ai soci: nessuna posta |
| `saas:payments:requeue-webhooks` | pagamenti online: nessun Stripe |
| `saas:portal:usage`, `suspend-expired`, `purge-expired`, `lifecycle-notices`, `verify-domains` | portale, ciclo di vita, domini: nessun collegamento verso l'esterno |
| `saas:night`, `saas:audit:prune` | controlli e potature inutili su dati che si azzerano ogni notte |

Il catalogo verso il portale e gli aggiornamenti esterni non hanno lavori pianificati: partono solo da chiamate dello staff, chiuse (sezione seguente).

## Percorsi chiusi nella demo

`CloseDemoPaths` risponde **404** a: `/saas/admin` e `/saas/…` (amministrazione e staff), `/api/…` (API del portale, catalogo, esecuzione), `/accesso-unico/…`, `/area/webhook/…` (Stripe), `/gestionale/abbonamento`. Succede prima di qualunque controllo di firma o di sessione, quindi anche una chiamata con una firma valida non entra. Unica eccezione: `POST /api/portale/v2/runtime/reset`, l'azzeramento locale della cache usato da `saas:web:refresh`. Il guardiano `DemoRestrictions` toglie comunque `/saas/admin` e `/saas/staff` anche se un domani la prima barriera mancasse. `tests/Feature/Core/DemoClosedPathsTest.php` elenca le rotte registrate e fallisce se ne resta aperta una sensibile.

## Moduli pubblici (adesione, donazione, area soci)

- **Limiti di frequenza**: ogni invio (`POST/PUT/DELETE`) delle rotte pubbliche ha un limite (`throttle:applications`, `applications-confirm`, `donations`, `member-*`) o conta i tentativi da sé (accesso dell'area soci: codice e link, per indirizzo e per email). La prova elenca le rotte e fallisce se una non ne ha.
- **Nessun file**: i controller pubblici non leggono file e, nella demo, una richiesta con un file in arrivo su una rotta pubblica è rifiutata («il caricamento dei file è spento»).
- **Niente sopravvive all'azzeramento**: tutto ciò che i visitatori scrivono sta nel database (ricreato ogni notte), nei file dell'associazione (`SAAS_FILES_ROOT`, svuotato ogni notte), nei log e nella cache su disco (svuotati ogni notte). La donazione online e Stripe sono spenti. `tests/Feature/Core/DemoPublicModulesTest.php`.

## Il database della demo (procedura generica)

Nessun nome, valore o password in questo documento: i nomi scelti stanno solo nel `.env` sul server.

1. **Creare** database e tre utenti (applicazione, proprietario, sola lettura) con `uapi Postgresql` (`create_database`, `create_user`), nomi diversi tra loro. Le password si generano sul server, si scrivono **solo in un file con permessi 600 fuori dalla web root** (per esempio in `~/saas-demo/shared/`), mai in chat, nel repository o nei log.
2. **Dare a tutti e tre** i permessi con `uapi Postgresql grant_all_privileges` sul database: serve perché cPanel scriva la riga di `pg_hba` per ciascun utente (senza, l'utente non si collega). Poi si restringe come sotto.
3. **`DB_HOST=localhost`** (non `127.0.0.1`: `pg_hba` lo rifiuta su questo server) e **nessun `DB_SSLMODE=require`**: il server non offre SSL su `localhost`/socket.
4. **Ruolo di gruppo**: cPanel fa risultare i tre utenti membri (con ereditarietà) del ruolo di gruppo che possiede il database, e non si può evitare. Rimedio per oggetto, come amministratore del database:
   - dare al proprietario `CREATE` sul database; `ALTER SCHEMA public OWNER TO <proprietario>`; `REVOKE ALL ON SCHEMA public FROM PUBLIC`;
   - poi, come gruppo (`SET ROLE <gruppo>`): `REVOKE CREATE, TEMPORARY ON DATABASE <db> FROM <gruppo>` e `REVOKE ALL ON DATABASE <db> FROM PUBLIC`; infine `GRANT CONNECT ON DATABASE <db>` ad applicazione e sola lettura (e al proprietario).
5. **Migrazioni come proprietario** (`saas:migrate` usa `DB_OWNER_*`): così tabelle e schemi sono del proprietario, non del gruppo.
   - **Dopo le migrazioni**, come proprietario: `GRANT USAGE ON SCHEMA public TO <applicazione>, <sola lettura>` (solo `USAGE`, mai `CREATE`). Le estensioni (`citext`, `pg_trgm`) stanno in `public`: senza `USAGE`, dopo il `REVOKE ALL ... FROM PUBLIC` del punto 4, applicazione e sola lettura non le risolvono («permission denied for schema public»). Lo stesso `USAGE` serve al ruolo usato dalle copie di sicurezza (backup).
6. **Limite noto, accettato per la demo** (dati finti): applicazione e sola lettura possono fare `SET ROLE` al gruppo e quindi `ALTER DATABASE` o `GRANT` sul database; non toccano le tabelle del proprietario. Non è accettabile in produzione.

## Ambiente GitHub `demo`

- Ambiente **`demo`** nelle impostazioni del repository, con la regola «solo il ramo `main`».
- Segreti **propri** dell'ambiente (non quelli della produzione): `SAAS_SSH_PRIVATE_KEY`, `SAAS_SSH_KNOWN_HOSTS`, `SAAS_SSH_HOST`, `SAAS_SSH_PORT`, `SAAS_SSH_USER`.
- Il workflow «SaaS · rilascio» ha già l'ambiente `demo` e rilascia in `~/saas-demo` (mai `~/saas`).

## Chiavi SSH: due coppie

Le genera il titolare con **Git Bash**, **fuori da OneDrive** (per esempio in `C:\chiavi\`), e non le incolla mai in chat.

| Coppia | A cosa serve | Dove va la parte pubblica | Dove va la parte privata |
|---|---|---|---|
| **sessioni** | entrare per lavorare a mano sul server | `~/.ssh/authorized_keys` dell'account | il tuo computer (passphrase **facoltativa**) |
| **Actions** | rilascio automatico dal workflow | `~/.ssh/authorized_keys` dell'account | segreto `SAAS_SSH_PRIVATE_KEY` dell'ambiente `demo` (senza passphrase: Actions non può digitarla) |

```
ssh-keygen -t ed25519 -f /c/chiavi/demo-sessioni -C "demo sessioni"
ssh-keygen -t ed25519 -f /c/chiavi/demo-actions  -C "demo actions"
```

La passphrase si può mettere sulla prima coppia; la seconda resta senza. Le due coppie sono diverse tra loro e da quelle della produzione.

## Il file `.env`

Lo **scrive il titolare sul server**, a mano, partendo dal modello `saas/deploy/env.demo.example`: copia in `~/saas-demo/shared/.env` (permessi 600) e compila i punti segnati `DA COMPILARE`. Il file non passa mai da chat, repository o log.

Prima di ogni rilascio, e ogni volta che lo modifichi:

```
cd ~/saas-demo/current && php artisan saas:demo:check-env
```

Il comando legge il file e dice **quali** variabili mancano, sono vuote, sono ancora sul valore d'esempio o rompono una regola; **non stampa mai i valori**. Controlla anche:

- **indirizzo unico**: `APP_URL` = `SAAS_DEMO_SLUG` + `.` + `SAAS_MEMBERS_DOMAIN`, in HTTPS;
- **cookie**: `SESSION_COOKIE` comincia con `__Host-`, `SESSION_DOMAIN` vuota/`null`, `SESSION_SECURE_COOKIE=true`;
- **isolamento** (vedi sotto);
- `APP_ENV=demo`, `SAAS_DEMO=true`, `SAAS_DEMO_DATABASE` uguale a `DB_DATABASE`, `MAIL_MAILER=array`, `SAAS_UNIFIED_OPERATORS=false`, `SAAS_REQUIRE_TWO_FACTOR=false`, `SAAS_KEY_VAULT=local`;
- tre utenti PostgreSQL con nomi diversi, password diverse tra loro e diverse da `APP_KEY`, `SAAS_MASTER_KEY` e `SAAS_DEMO_PASSWORD`; chiavi non vuote.

### Regola di isolamento

Nel `.env` della demo **nessun valore deve valere altrove**: niente chiavi o token verso GitHub, portale, Emailit/SMTP, Stripe, produzione, Redis, servizio chiavi. Il controllo accetta solo le variabili del modello (più poche opzioni): ogni altra variabile con un valore è un errore, e quelle che sembrano collegamenti esterni sono segnalate come tali. Motivo: la demo è pubblica e vicina ad altri siti pubblici (la demo WordPress, oggi in un altro account); se un giorno stessero insieme, o se un difetto lasciasse leggere il file, questo non deve contenere nulla che si possa usare contro qualcosa di vero.

## Cookie

Tutti i cookie dell'app sono **solo dell'indirizzo** (nessun `Domain`) e non sono sostituibili da un cookie del dominio padre `.associazionesemplice.it` scritto da un sottodominio fratello (per esempio la demo WordPress):

| Cookie | Nome | Note |
|---|---|---|
| sessione operatore | `SESSION_COOKIE` = `__Host-as_demo` (produzione: `__Host-as_gestionale`) | `Secure`, percorso `/`, senza `Domain`: il browser rifiuta un cookie omonimo scritto da un altro indirizzo |
| pagine pubbliche (area soci, `/dona`, `/adesione`) | `__Host-as_pubblico` | vale ovunque ci sia HTTPS (produzione, staging, demo); `as_pubblico` solo in locale |
| «ricordami» | `remember_web_<hash>` | senza `Domain`, `Secure`, `HttpOnly`; il nome lo decide Laravel e non ha il prefisso; il valore è cifrato con `APP_KEY`: non si può falsificare, al massimo un cookie omonimo ne sostituirebbe uno con quello di un altro account valido |
| `XSRF-TOKEN` | fisso | senza `Domain`; il token si confronta con quello della sessione sul server: un cookie omonimo scritto altrove non può far passare una richiesta, al massimo la fa rifiutare (419) |
| socio nell'area soci | `as_socio` | percorso `/area`, senza `Domain`, `HttpOnly`; il valore è un codice casuale riconosciuto dal database |

I cookie dell'amministrazione (`/saas/admin`) e dello staff non esistono nella demo (404).

## noindex

Con `SAAS_DEMO=true` (e ambiente non di produzione): ogni risposta ha `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` e `/robots.txt` risponde `Disallow: /` (il file lo serve l'applicazione, non il file statico: lo garantisce `public/.htaccess`). Fuori dalla demo non cambia nulla.

## Azzeramento notturno e orari

| Ora | Cosa |
|---|---|
| 02:23 | controlli notturni dell'app (`saas:night`) |
| **03:30** | **azzeramento della demo del gestionale** (`saas:demo:reset`, dallo scheduler) |
| **04:00** | **azzeramento della demo WordPress** |

Regola: **nessuna sovrapposizione**. L'azzeramento del gestionale parte alle 03:30 e deve finire prima delle 04:00; quello di WordPress parte alle 04:00 e non prima. Non si spostano le due ore senza cambiare l'altra: tra l'una e l'altra restano almeno 30 minuti. Se l'azzeramento del gestionale dovesse superare i 25 minuti (con `SAAS_DEMO_PEOPLE` più alto o un server lento), si scende di numero di persone, non si sposta WordPress sopra. Altri lavori dello scheduler vicini: `saas:applications:prune` alle 03:41 e, il lunedì, `saas:audit:prune-security` alle 03:27; sono brevi e non toccano i dati dell'azzeramento.

L'azzeramento cancella **tutto** il database della demo, i file cifrati, i log, i file temporanei e la cache su disco, ed è protetto: si rifiuta se `SAAS_DEMO_DATABASE` non è il database collegato, se ci sono altre associazioni o se la cartella non si chiama `saas-demo`. Si lancia a mano solo sul server demo, e solo se il titolare lo chiede (il primo dopo la prima installazione).

## Se l'account è condiviso con la demo WordPress

- Cartelle **separate**: `~/saas-demo` per il gestionale, quelle di WordPress altrove (sue); mai file in comune, mai un collegamento simbolico dall'una all'altra.
- `~/saas-demo/shared/.env` con permessi 600 e **nessun segreto vero** nell'account: né chiavi di produzione, né token GitHub, né password di posta o Stripe (il controllo del `.env` lo verifica). Nemmeno nei file di WordPress.
- Orari: WordPress azzera alle **04:00**, il gestionale alle **03:30**.
- Licenza dedicata alla demo; il database PostgreSQL e i suoi tre utenti sono solo della demo.
- Il gestionale resta nascosto: nessuna pagina di WordPress lo collega, nessun collegamento al portale.
- Le chiavi SSH di rilascio sono quelle dell'ambiente `demo`, diverse da quelle della produzione.

## Installazione, in ordine

Sul server **niente si installa finché il titolare non lo chiede**. Quando sarà il momento:

1. Account (o cartelle) e sottodominio `demosaas.associazionesemplice.it` con HTTPS.
2. Database PostgreSQL e tre utenti; niente Redis.
3. Due coppie di chiavi SSH; parte pubblica in `authorized_keys`; privata di Actions nell'ambiente GitHub `demo`.
4. `.env` dal modello, compilato dal titolare; `php artisan saas:demo:check-env` senza problemi.
5. Rilascio dal workflow «SaaS · rilascio» con ambiente `demo` (solo `main`).
   - **Primo rilascio su database vuoto**: `remote.sh` lancia `saas:health --skip-migrations` *prima* delle migrazioni, quindi il primo rilascio si ferma con «relation ... does not exist». La release però è già estratta in `~/saas-demo/releases/<ultima>`. Procedura: (a) lasciar fallire il primo rilascio al controllo di salute; (b) dalla cartella di quella release, come proprietario, `php artisan saas:migrate --force`; (c) dare il `USAGE` su `public` (vedi «Il database della demo», punto 5); (d) rilanciare il rilascio: stavolta passa. Poi `saas:demo:check-env` e il primo `saas:demo:reset` (senza opzioni).
6. Primo `php artisan saas:demo:reset`, a mano; poi lo fa lo scheduler alle 03:30. Cron: `schedule:run` ogni minuto.
7. Prove: `/accesso` con gli accessi della pagina, `/area/accesso`, `/adesione/demosaas`, `https://demosaas.associazionesemplice.it/robots.txt` (deve dire `Disallow: /`).

## Cosa è provato dai test

`tests/Feature/Core/DemoSameHostTest.php`: sullo stesso indirizzo ogni percorso va alla rotta giusta (`/area` all'area soci, `/accesso` e `/gestionale` all'operatore, `/dona` e `/adesione` ai moduli pubblici), i cookie sono senza `Domain` e con prefisso `__Host-`, noindex e robots. `tests/Feature/Core/DemoCheckEnvTest.php`: il controllo del `.env` e il modello. `DemoMailTest`, `DemoScheduleTest`, `DemoClosedPathsTest`, `DemoPublicModulesTest`, `DemoWebrootTest`: posta e log, scheduler, percorsi chiusi, moduli pubblici, cartella pubblica e `.htaccess`.
