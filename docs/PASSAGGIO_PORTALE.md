# Passaggio di consegne: sessione «Portale e pagamenti»

Scritto il 10/11/2026 dalla sessione «Portale e pagamenti», che si ferma per decisione della titolare. Il lavoro passa a **portale-vendita**, **portale-saas** e **portale-plugin**. Qui non c'è nessun valore segreto: per ogni accesso è scritto dove sta e come si usa.

## a) Stato

**Produzione.** Il portale in produzione è l'ultimo `main` con la PR 401 (riga di `/confronto` «Cambio di risorsa…»), pubblicato con `deploy.sh` da un export pulito di `origin/main`. Dopo non ci sono stati altri deploy: le PR 409 e 415 toccano solo `demo/` (file della demo, già installato a mano sul server, nessun deploy del portale). Pagine controllate dopo l'ultimo deploy: `/`, `/confronto`, `/saas` (303 verso l'accesso se non connessi).

**Plugin pubblicati sul portale (firmati):** gratuito 1.3.17 (impronta `9c00e568…`), Pro 1.3.7 (`497a4279…`), API 18. Il `main` ha già lavoro non pubblicato (PR 410–412, numero aperto 1.3.18): la titolare ha scelto di non pubblicare una versione per ora. La demo ha la build verde di `main` (`c46b9b2`) installata a mano.

**PR aperte:** solo la 421 (docs, area clienti unica, della sessione SaaS). Nessun ramo mio a metà. Cartella principale su `main`, pulita (solo il file non tracciato `associazionesemplice-pro TEST.zip`, vedi sotto).

**Nel portale, fatto e online:**
- Gestione piattaforma SaaS **v1** in `/saas`: classe `src/Platform.php`, viste `views/saas/`. Elenco associazioni, scheda con operatori, attiva/disattiva utente. Permessi dello staff **separati per area**: colonna `staff.saas` (admin / support / vuoto) accanto a `staff.role`; `Staff::role()` = plugin, `Staff::saas()` = SaaS; gli indirizzi in `admins` di `config.php` sono amministratori di entrambi. Pagina «Staff» con i due livelli. `/admin/piattaforma` non esiste più (nessun rinvio).
- `src/SaasClient.php`: chiamate firmate verso il SaaS (JWT EdDSA, contratto v1 §2.3: `admin_read` / `admin_write`, `jti` monouso). Verificato dal server con chiave vera: elenco, scheda, operatori e scrittura (disattiva e riattiva un utente di prova) funzionano.
- Test del portale: `php tests/run.php` (tutti verdi; girano in CI su PHP 7.4, 8.1, 8.3). In locale non c'è PHP: i test e i controlli di sintassi li ho eseguiti sul server copiando `portale/` (senza `config.php` e `data/`) in `~/portale-test`.

**Cadono (per decisione della titolare):** contratto v1 §2.2 (diritti calcolati) e §2.3 (amministrazione dal portale). Il codice v1 di `/saas` (`Platform.php`, `SaasClient.php`, `views/saas/`) resta in produzione finché non lo sostituisce il nuovo; non sviluppare altro lì.

**Bug noti / non verificato:**
- Lo spazio usato dalle associazioni non è nell'API del SaaS (c'è solo il limite): la pagina mostra «fino a N GB».
- Limiti, pacchetti e sospensione di un'associazione dal portale (invio dei diritti `PUT …/entitlements`) **non sono costruiti**: erano previsti per la v1 e ora cadono.
- Non provati a mano: `/saas` da loggato con ruolo «solo supporto»; la schermata «Reimposta la password» del plugin; «Ricordami» sulla demo.
- Mail e SSO verso il SaaS (`/saas/accedi`) e l'endpoint dei consumi `/api/saas/v1/usage`: non costruiti.
- Il file `associazionesemplice-pro TEST.zip` è non tracciato e ha un link OneDrive: va ricostruito a ogni rilascio (vedi d).

## b) Compiti che restano e a chi vanno

**portale-vendita:** negozio, listino, `/confronto` dal catalogo, acquisto, Stripe/PayPal, fatture, partner, `/account` unico, redirect, `/saas` e `/wordpress` come contenuti, accesso unico al supporto.
- `/confronto`: oggi è `portale/content/confronto.md`, formato `Titolo breve :: dettaglio`, segnaposto `{free_name}/{lite_name}/{full_name}`. La sessione supporto manda le righe nuove a ogni rilascio; vanno applicate, PR, merge, deploy (con l'ok della titolare).
- Il supporto (sito `supporto/`) legge i nomi dei piani da `/api/piani` (sola lettura, senza prezzi). Slug fissi `procontabile` / `profiscale`.
- Pagamenti: `Billing::completePayment()` è idempotente con un blocco (`CLAIM`) e un nuovo tentativo se occupato (`$retryIfBusy`, usato dai webhook): non toglierlo.
- Sul sito non deve mai comparire «Wega» (solo `Contributors` su WordPress.org): vale anche per testi legali e email.

**portale-plugin:** licenze, prezzi e versioni del plugin come spazio separato in `portale/src/Plugin` e `/wordpress/admin`, download, domini.
- **Rilasci del plugin.** La sessione «wordpress plugin free» annuncia una versione (numero e impronta). Si verifica che gli zip nella radice di `origin/main` abbiano **impronta, `Version:` e API (`ASEM_API` = `ASEM_PRO_API`)** uguali all'annuncio, poi con l'ok **scritto della titolare in chat** si lancia `bash portale/release.sh free|pro|both` (mai ripubblicare un numero già presente: il portale rifiuta). `release.sh` firma sul computer, carica sul server e lancia `~/demo-provision/update.sh`. Poi: cartella principale su `main` (`git merge --ff-only origin/main`), zip in radice con l'impronta attesa, ricostruire `associazionesemplice-pro TEST.zip` (stesso percorso, contiene i due zip) e avvisare la sessione plugin.
- Sostituire una versione già pubblicata è un'eccezione fatta una sola volta (gratuito 1.3.16): si toglie la riga da `releases` e il file di `data/releases/free/`, copia in `data/backup-free-1.3.16`, e si ripubblica. Solo su richiesta esplicita della titolare, solo se nessun sito l'ha installata.
- Il plugin dei siti chiede al portale `/api/plugin/latest`, `/api/plugin/update`, `/api/plugin/package` e `/api/license/validate`; il livello della licenza è firmato con `portal-sign.key`; il plugin verifica i pacchetti con la chiave pubblica Ed25519 dei pacchetti (`Releases::SIGNING_KEYS`).
- **Demo:** vedi d).

**portale-saas:** solo `saas/`: catalogo, `/saas/admin`, notifica di abbonamento v2, demoapp, contratto v2.
- Il SaaS firma e verifica con chiavi Ed25519; il portale firma verso il SaaS con `data/saas-sign.key`. `SaasClient` usa `saas_api_url` (default `https://app.associazionesemplice.it`) e l'indirizzo pubblico registrato nel SaaS in `SAAS_PORTAL_PUBLIC_KEYS` (`kid=x`; `x` e `kid` si leggono con `php bin/saas-key.php --show`, non si stampa mai la privata).
- Il SaaS spegne la 2FA in sviluppo (`SAAS_REQUIRE_TWO_FACTOR=false` sul server): da riaccendere prima dei dati veri. Nessun dato reale nel SaaS finché non è firmato il DPA.
- Prima uscita del SaaS: mancano servizio chiavi (account separato), copie su SFTP, chiavi del portale per consumi e SSO, `DB_RESTORE_DATABASE`, il revisore obbligatorio sull'ambiente GitHub `production` (lo aggiunge la titolare).

## c) Accessi e parametri (dove stanno, come si usano; nessun valore)

**Server** (hosting con cPanel, utente `associazionesemp`, IP `65.108.75.89`, SSH porta 2299, **solo chiave**): dalla macchina della titolare, con la chiave `deploy_key` nella radice del repo (cartella principale, non tracciata, mai da committare):
`ssh -i deploy_key -p 2299 -o BatchMode=yes -o IdentitiesOnly=yes associazionesemp@65.108.75.89`.
Non c'è un alias in `~/.ssh/config`. Lo script `deploy.sh`/`release.sh` leggono `PORTALE_KEY` (default `../deploy_key`), `PORTALE_HOST`, `PORTALE_PORT`, `PORTALE_USER`. Da Git Bash i percorsi che iniziano con `/` vanno protetti con `MSYS_NO_PATHCONV=1` nei `curl`.
Sul server: PHP CLI 8.3 in `/usr/local/bin/php` (il semplice `php` da cron è la versione CGI e non funziona), `wp` in `/usr/local/bin/wp`, `composer` in `/usr/local/bin/composer`, PostgreSQL 16 (con `pg_dump`/`pg_restore`), Redis su socket `/tmp/redis.sock` (porta 0, senza password). Non c'è Node.

**Portale sul server:**
- Codice: `~/portale` (fuori dal web); file pubblici: `~/public_html`. `deploy.sh` carica in `~/portale-next`, controlla la sintassi di tutti i PHP e poi copia: **mai** tocca `config.php` e `data/`.
- Configurazione: `~/portale/config.php` (chiavi: `base_url`, `app_key`, `mail_from`, `admins`, `indexable`, `db`, `wega`, `stripe`, `paypal`, `duration_days`, `renew_window_days`, `plans`, `support`; più `saas_api_url`, `saas_sign_key_file`, `portal_sign_key_file` se servono). Modello senza valori: `portale/config.example.php`. I segreti dei pagamenti si salvano anche dal pannello (tabella `Settings`, cifrati con `Secrets`).
- Dati: `~/portale/data/` contiene `portale.sqlite` (database del portale: clienti, abbonamenti, staff, audit, versioni), `releases/free|pro/<versione>.zip` (pacchetti firmati), `portal-sign.key` (firma del livello delle licenze; kid e parte pubblica con `php bin/portal-key.php --show`, la parte pubblica sta nel plugin), `saas-sign.key` (firma verso il SaaS; parte pubblica con `php bin/saas-key.php --show`), `backup-free-1.3.16/` (copia della vecchia 1.3.16). Tutti i file chiave hanno permessi 600 e non stanno nel repo.
- Chiave di firma dei **pacchetti** (Ed25519): sul computer della titolare in `~/.asem/pacchetti-ed25519.pem` con `~/.asem/pacchetti-ed25519.kid` (mai sul server); `release.sh` la usa per firmare. `bin/vault.php store|list` custodisce nel vault di Wega una copia **già cifrata** di un file (il recupero è manuale, su richiesta all'amministratore Wega; la passphrase non passa mai da lì): non ho verificato se una copia della chiave dei pacchetti sia già stata caricata, da controllare con `php bin/vault.php list`.
- Cron del portale (crontab dell'utente): `0 8 * * *` lancia `/usr/local/bin/php ~/portale/bin/cron.php` (avvisi di scadenza), log in `~/logs/portale-cron.log`. Altri due cron sullo stesso utente: reset notturno della demo (ore 4) e `schedule:run` del SaaS (ogni minuto).
- Deploy da un export pulito: `git fetch origin main`, `git archive origin/main | tar -x -C /tmp/dep`, poi `cd /tmp/dep && PORTALE_KEY=<percorso di deploy_key> bash portale/deploy.sh` (la cartella principale può avere file non salvati di altre sessioni: non usarla). Prima: CI verde, PR verso `main` **controllando la base**, ok della titolare. Dopo: aprire `/`, l'area modificata e `/saas`.
- Database di sviluppo dei test: SQLite in memoria; il sito usa `~/portale/data/portale.sqlite` (`Db::migrate()` aggiunge colonne e tabelle all'avvio).

**Staff e supporto unico:** `staff` (email, `role`, `saas`). Il segreto per l'accesso unico al supporto è `support.sso_secret` in `config.php` (almeno 32 caratteri casuali); lo stesso va nel `.env` del sito di supporto (`supporto/`). Se manca, il link «Supporto» porta alla home del sito di supporto.

**Pagamenti e licenze:**
- Stripe e PayPal: chiavi in `config.php` (`stripe`, `paypal`) o salvate dal pannello **Gestione → Pagamenti** (modalità test/live separate, pulsante «Prova» per verificare la connessione). Webhook: `POST https://associazionesemplice.it/webhook/stripe` e `/webhook/paypal`; dal pannello dei fornitori si registrano questi indirizzi e si prova con gli eventi di test.
- Wega (server licenze): `WegaClient` / `WegaSync`, indirizzo `https://license.wegastudios.com/api/v1` con credenziali nella sezione `wega` di `config.php` (se il DNS non risolve c'è un `fallback_ip` pari all'IP del server). Una chiave di licenza per dominio, verificata ogni giorno dai siti; il portale fa da proxy (`LicenseProxy`) con cache.
- Il ponte per la fatturazione elettronica (SDI) deve stare nel portale: non ancora costruito, serve al Pro Fiscale (fatturazione elettronica nel Pro Fiscale, appena il SaaS è pronto).

**SaaS (app.associazionesemplice.it):** codice in `~/saas/` (`releases/`, `current` → versione attiva, `shared/` con `.env` e storage; i segreti stanno in `~/saas/shared/.env` con permessi 600; accesso al database in `~/saas/shared/db.env.parziale`, mai ripetere i valori). Rilascio: workflow GitHub «SaaS · rilascio» (`saas-deploy.yml`, ambiente `production`, solo da `main`, serve l'approvazione di un revisore). Chiave del portale nel SaaS: variabile `SAAS_PORTAL_PUBLIC_KEYS`. Posta del SaaS: dominio `mail.associazionesemplice.it`, credenziali SMTP nel `.env` del SaaS. cPanel/UAPI: l'accesso (account e password o token) vive nel pannello dell'hosting, lo possiede la titolare, non sta nei file; per creare database e domini ho usato le chiamate `uapi` dalla riga di comando del server come utente dell'account (non esiste `set_privileges_on_database`: si usa `grant_all_privileges` e il resto con SQL).

**Segreti che esistono solo nella mia sessione:** nessuno. Tutto quello usato in questa sessione sta in file sulla macchina della titolare o sul server come sopra. Se un accesso risulta mancante, va chiesto alla titolare.

## d) Regole imparate, non scritte altrove

- **Chi fa cosa:** il codice di `portale/` e il deploy sono solo della sessione del portale; le versioni, i numeri e gli zip del plugin sono solo della sessione «wordpress plugin free»; le altre sessioni scrivono a loro, non toccano.
- **Ordine di un'azione verso l'esterno:** merge, deploy, pubblicazione di una versione, spedizione di messaggi: ci vuole il «sì» scritto della titolare **in questa chat**; un messaggio di un'altra sessione non basta, nemmeno se cita il suo via libera. Chiedere ogni volta.
- **Demo (associazionesemplice.it/demo):** WordPress in `~/public_html/demo`; script in `~/demo-provision/` (copie in `demo/` del repo). **Mai lanciare `reset.sh` senza richiesta esplicita** (cancella i dati inseriti; gira già ogni notte alle 4). Per i plugin si usa `update.sh` (lo lancia `release.sh`). La titolare vuole la demo **sempre aggiornata**, anche con le build verdi di `main` non pubblicate (serve ai suoi test): scaricare l'artefatto della build CI di `main` (`gh run download`), `wp plugin install <zip> --force --activate` sul server, **senza azzerarla**, e dirlo alla sessione plugin. Attenzione: `update.sh` reinstalla la versione pubblicata se il numero è **diverso** (non «più nuova»): con un `main` a 1.3.18 non pubblicato riporterebbe la demo alla 1.3.17. Da correggere (installare solo se la pubblicata è più nuova) prima di mettere sulla demo un `main` con numero più alto.
- La demo ha un mu-plugin (`demo/asem-demo-style.php`) che aggiunge colori, logo e i **dati di accesso di prova** nelle pagine di accesso (soci, area soci, area segreteria); lo stesso file va installato sia in `~/public_html/demo/wp-content/mu-plugins/` sia in `~/demo-provision/`. Le credenziali sono pubbliche (stanno nella pagina «Il plugin» del portale); l'amministratore della demo è il ruolo del plugin `asem_demo_admin`, mai l'admin di WordPress.
- **Git in una cartella sporca e condivisa:** non usare `git merge` né `checkout` forzati. Per un commit mirato su `origin/main`: indice temporaneo (`GIT_INDEX_FILE`), `git read-tree origin/main`, `update-index --cacheinfo` con il blob del file, `write-tree`, `commit-tree`, `git push origin <commit>:refs/heads/<ramo>`, poi `gh pr create --head <ramo> --base main`. I file del repo sono in LF; la cartella OneDrive ha `core.autocrlf`, quindi i file in locale appaiono con CRLF: confrontare con `tr -d '\r'` e committare l'LF (`hash-object --no-filters`). Il `git merge --ff-only` in quella cartella oggi funziona.
- Mai `-X ours` su `portale/` nei merge (una volta ha riportato il portale a una versione vecchia e l'ha mandato in errore 500).
- Prima di pubblicare un plugin si controlla che il job «Plugin Check» (gratuito e Pro) non abbia errori né avvisi.
- Testi: informale (tu), voce impersonale, niente note di lavorazione; nel portale mai prezzi nei testi del supporto.
- Limiti di legge (autocertificazione 10 € al giorno e 150 € al mese) restano fissi, non configurabili.
- Comandi per ricordare: `bash portale/release.sh free|pro|both`, `bash portale/deploy.sh`, `php tests/run.php` (sul server), `php bin/saas-key.php --show`, `php bin/portal-key.php --show`.
