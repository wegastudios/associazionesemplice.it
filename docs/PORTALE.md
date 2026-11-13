# Portale: vendita e gestione degli abbonamenti

Applicazione PHP indipendente (cartella `portale/`, nessun framework, nessuna dipendenza): vende i piani, incassa con Stripe e PayPal,
chiede le licenze al server Wega (`https://license.wegastudios.com/api/v1`) e le fa gestire al cliente. Non fa parte degli zip del plugin.

Requisiti: PHP 7.4+ con `pdo_sqlite` (o `pdo_mysql`), `sodium`, `curl`, `intl` (domini con accenti). Cartella pubblica: `portale/public/`.

## Piani

| Piano | `plan_name` inviato a Wega | Licenza |
|---|---|---|
| Free | `Free` | tipo 3, 3650 giorni, gratuita |
| Pro Lite (senza contabilità) | `Pro Lite` | tipo 3, 31 giorni (mensile) o 366 (annuale), rinnovata a ogni pagamento |
| Pro Full | `Pro Full` | idem |

Prezzi e codici dei prodotti sono in `config.php` (`plans`). Il portale non decide l'importo: lo addebita il prezzo Stripe / il piano PayPal indicato.
I giorni in più rispetto al periodo (31/366) coprono i ritardi di pagamento; il server ha poi 5 giorni di tolleranza.

## Percorsi

- Licenza free: `/free` (email + dominio) → email con link → conferma → la chiave compare in `/account`. La licenza si emette solo dopo la conferma.
- Acquisto: `/acquista?plan=full&period=year` → Stripe Checkout o PayPal → webhook → licenza emessa → email con il link per vedere la chiave.
- Area riservata `/account`: chiave, scadenza, **cambio dominio** (`PUT /licenses/{id}/binding`; il cambio è libero, poi la chiave resta bloccata `domain_lock_days` giorni, 180 di default, regola del portale; il personale può sempre cambiarlo dal pannello; Wega aggiunge solo il suo limite di sicurezza di 3 cambi ogni 30 giorni), disdetta del rinnovo, carta e fatture (portale Stripe).
- Accesso: **email e password** (`/accedi`). Il link per email (monouso, 15 minuti; apre una pagina con pulsante così gli antispam non lo consumano) resta per il primo accesso e per la password dimenticata: entrando dal link si sceglie la password dall'area riservata senza digitare la vecchia. Password salvata come impronta bcrypt (con pre-impronta SHA-256: nessun limite di 72 byte), minimo 10 caratteri e dopo 5 errori/ora per email (4 volte tanto per IP) l'accesso con password si ferma; i limiti sono in Impostazioni → «Accesso dei clienti». Stesso errore per email sconosciuta e password sbagliata.

## Pagamenti: ricorrenti, singoli, prova

- **Ricorrente** (Stripe Subscriptions / PayPal Subscriptions): richiede il prezzo `price_…` su Stripe e/o il piano `P-…` su PayPal, con lo stesso importo del listino. Rinnovo automatico, disdetta dall'area riservata.
- **Singolo** (Stripe Checkout a pagamento unico / PayPal Orders): usa l'importo del listino, non serve nulla da creare sui provider. La licenza vale un periodo e non si rinnova da sola; il cliente la rinnova dall'area riservata (la **chiave resta la stessa**, si sposta la scadenza) e riceve un avviso prima della scadenza. Si può spegnere per piano e periodo.
- **Prova Pro**: chi ha una licenza gratuita attiva può provare un piano Pro per N giorni (default 30), **una sola volta per licenza gratuita** e una sola volta per dominio (`trial_used_at` e `external_ref` idempotente sul server Wega). La prova è una licenza separata con una chiave sua: il cliente la incolla al posto di quella gratuita e a fine prova rimette la gratuita o passa al Pro.
- **Emissione manuale** dal pannello: regali e accordi fuori portale, senza pagamento.

## Pannello di gestione (`/wordpress/admin`)

> **Confine logico (10/11/2026).** Secondo [ARCHITETTURA_DOMINI.md](ARCHITETTURA_DOMINI.md) §3.2 il lato server del plugin (licenze, piani, prezzi, versioni) è uno spazio proprio: codice in `portale/src/Plugin/`, viste in `portale/views/wordpress-admin/`, rotte sotto `/wordpress/admin`. Il vecchio `/admin` risponde con redirect permanente 301 (308 per i POST) alla stessa pagina nuova, query compresa. Il negozio e l'area clienti non devono interrogare le tabelle dei piani: parlano con questo spazio solo tramite l'interfaccia `Portale\Plugin\Catalog` (`plan()`, `price()`, `document()`), oggi realizzata da `ConfigCatalog` sopra i piani di `config.php`; la stessa cosa è pubblica in `GET /wordpress/api/catalogo/v1` (formato `catalogo/v1`, ETag, cache 5 minuti; firma EdDSA, caratteristiche, limiti e promo arrivano coi passi successivi). Migrare i punti del negozio che leggono ancora `Config::price`/`plans.*` spetta a portale-vendita. Lo spazio lo segue la sessione «Portale plugin».

Stesso accesso dei clienti (link per email), riservato agli indirizzi in `admins` di `config.php`; ogni richiesta ricontrolla l'indirizzo e ogni modifica va nel registro.

- **Cruscotto**: licenze attive, abbonamenti, incasso mensile ricorrente stimato, prove in corso, scadenze, pagamenti non riusciti, cosa manca in `config.php`.
- **Licenze**: ricerca, dettaglio con chiave, controllo sul server Wega, cambio dominio, rinnovo a mano, disdetta, stato, note; emissione manuale.
- **Piani e prezzi**: nome, descrizione, funzioni, prezzi mensile/annuale, id Stripe e PayPal, pagamento singolo sì/no, piano visibile o nascosto. I valori salvati sostituiscono quelli di `config.php`.
- **Email**: un modello per evento (accesso, conferma free, benvenuto, rinnovo, pagamento non riuscito, disdetta, scadenza, prova avviata e in scadenza, dominio cambiato) con segnaposto, anteprima, prova di invio, interruttore e ripristino.
- **Impostazioni**: durate, finestra di rinnovo PayPal, giorni di preavviso, prova (attiva, piano, durata), indicizzazione dei motori di ricerca, mittente. Ogni limite ha il suo valore di default, è limitato a un intervallo e spiega i rischi di alzarlo o abbassarlo.
- **Registro**: chi ha fatto cosa e quando.

Lavori giornalieri: `bin/cron.php` (avvisi di scadenza), installato da `deploy.sh` nel crontab del server alle 8:00.

## Webhook da configurare

- Stripe → `https://…/webhook/stripe`: `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`.
- PayPal → `https://…/webhook/paypal`: `BILLING.SUBSCRIPTION.ACTIVATED`, `PAYMENT.SALE.COMPLETED`, `BILLING.SUBSCRIPTION.PAYMENT.FAILED`, `BILLING.SUBSCRIPTION.CANCELLED`, `…EXPIRED`, `…SUSPENDED`.

Ogni evento si elabora una volta sola (tabella `events`); se l'elaborazione fallisce risponde 500 e il provider riprova.
Il rinnovo chiama `POST /licenses/renew` (non si ripete in automatico su errore 5xx: il server aggiungerebbe i giorni due volte; riprova il provider).
PayPal manda `PAYMENT.SALE.COMPLETED` anche per il primo pagamento: conta come rinnovo solo negli ultimi `renew_window_days` di validità.

## Sicurezza

Token Wega e chiavi dei provider solo in `config.php` (fuori dal repository, vedi `.gitignore`); chiavi di licenza cifrate nel database (`app_key`, libsodium);
firme dei webhook verificate (Stripe HMAC con tolleranza 5 minuti; PayPal con `verify-webhook-signature`); CSRF su ogni modulo; un dominio appartiene a un solo cliente
(altrimenti `external_ref` idempotente restituirebbe la chiave a chi non è il proprietario).

## Stato e da fare

Fatto: vendita (Stripe e PayPal, ricorrente e singolo), licenze Wega, area cliente, prova Pro, pannello `/wordpress/admin` (cruscotto, licenze, clienti, piani e prezzi, email, impostazioni, registro, pagamenti, cookie e script, pagine legali), cron degli avvisi, client nel plugin (`docs/LICENZE.md`).

Da fare:
1. Creare su Stripe i prezzi ricorrenti e su PayPal i piani, poi compilare `stripe_price` / `paypal_plan` (da `config.php` o da `/wordpress/admin/prezzi`); token Wega con scope `licenses:create`, `licenses:read`, `licenses:change-binding` e tipo 3 abilitato.
2. Passare il plugin al rimbalzo (vedi sotto): così non serve nessuna credenziale nel plugin. I nomi dei piani inviati al server devono contenere Free, Lite o Full.
3. Prova su staging dei percorsi reali (acquisto, rinnovo, webhook, prova Pro) con le chiavi di test.
4. Rimborsi e fatturazione elettronica: non ancora gestiti. Cambio piano: vedi sotto (con PayPal ricorrente non è ancora automatico).

## Chiavi, cookie e pagine legali (dal pannello)

- **Pagamenti** (`/wordpress/admin/pagamenti`): token Wega, chiavi Stripe e PayPal si inseriscono da qui. Sono **cifrate** nel database con `app_key` (che resta in `config.php`), si vedono solo gli ultimi 4 caratteri, un campo vuoto non cambia nulla; le chiavi del pannello hanno la precedenza su `config.php`. Ogni servizio ha «Prova il collegamento» e la pagina mostra gli indirizzi dei webhook e gli eventi da selezionare.
- **Cookie e script** (`/wordpress/admin/cookie`): spazio per gli script di monitoraggio nell'header e nel footer, ciascuno con la sua categoria di consenso (statistiche, marketing, necessari). Gli script non necessari stanno in `<template>` e partono solo dopo il consenso (`assets/portale.js`); il banner compare solo se esiste uno script che richiede consenso; cambiando gli script a tutti viene richiesta di nuovo la scelta. Cookie della scelta: `asem_cc`, 6 mesi.
- **Pagine legali** (`/wordpress/admin/legale`): `/privacy` e `/cookie` con testi di base modificabili e dati del titolare. Sono un modello: vanno verificati da un consulente.

## Rimbalzo delle verifiche del plugin

Il plugin non parla con il server Wega: chiede la verifica al portale, che la gira a Wega con il proprio token (`LicenseProxy`). Nel plugin non restano né l'indirizzo di Wega né credenziali.

- `POST /api/license/validate` con `{"license_key":…,"domain":…}`: il portale normalizza il dominio, chiama `POST /licenses/validate` e restituisce la risposta di Wega **invariata**, attestazione firmata compresa. Un `valid: false` è una risposta 200 come su Wega; gli errori 4xx di Wega passano con il loro codice; rete e errori 5xx diventano **502**, così il plugin tiene lo stato precedente. Il plugin può solo verificare: emissione, rinnovo e cambio dominio restano solo del portale.
- `GET /.well-known/jwks.json`: copia in cache (1 ora, ultima copia valida se Wega non risponde) delle chiavi pubbliche di Wega. Il portale **non firma nulla**: il plugin verifica la firma EdDSA con le chiavi di Wega, quindi un portale compromesso non può dichiarare valida una chiave scaduta.
- Memoria breve (5 minuti) delle risposte, per chiave e dominio, e limiti orari per IP (60) e per chiave (20), modificabili da Impostazioni → «Verifiche dal plugin» (con i rischi). Superato il limite: 429 con `Retry-After`. Dietro un proxy fidato impostare `trust_forwarded_for` in `config.php`.
- Registro: solo limiti superati ed errori di Wega, non ogni verifica.
- Plugin (da fare nella sessione del plugin): `LicenseClient::API` → `https://associazionesemplice.it/api/license`, `ISSUER` e JWKS → il portale (l'`iss` del JWT resta quello di Wega), togliere `ASEM_LICENSE_TOKEN`, aggiornare il readme (servizio esterno) e `docs/LICENZE.md`.

## Pacchetti del plugin (download riservato)

Il portale ospita gli zip del plugin gratuito e del Pro (`Releases`). Restano online le **ultime 10 versioni** di ciascuno (limite «Versioni dei plugin da tenere online» in Impostazioni, 3–50) per poter tornare indietro; le più vecchie si cancellano a ogni nuova pubblicazione.

- **Nessun link pubblico.** I file stanno in `data/releases/{free,pro}/VERSIONE.zip`, fuori dalla cartella pubblica. Si scaricano solo da `/scarica/{free|pro}/{versione}` con accesso eseguito (link per email) e licenza in regola: gratuito con qualunque licenza attiva, Pro con un piano a pagamento o di prova attivo (scaduta o pagamento non riuscito = niente). Il percorso è escluso da `robots.txt`, la risposta è `no-store` e `noindex`.
- **Limite di scarichi** per cliente ogni 24 ore (default 10, 1–200, in Impostazioni con i rischi); la gestione non ha limiti. Ogni scarico è registrato (tabella `downloads`).
- L'area cliente (`/account`) mostra «Scarica il plugin» con l'ultima versione e, in un menu a comparsa, le precedenti.
- **Pubblicare una versione**: `bash wordpress/build.sh` poi `bash portale/release.sh free|pro|both ["nota"]` (carica via ssh e lancia `bin/release.php`), oppure dal pannello **Versioni** (`/wordpress/admin/versioni`, richiede l'estensione `zip` di PHP e `upload_max_filesize` sufficiente). Il numero di versione si legge dall'intestazione del plugin dentro lo zip; una versione già presente non si sovrascrive.
- Caricate il 2026-10-16: gratuito 1.1.11, Pro 1.1.4.

## Gestione a sezioni e eliminazioni

Il pannello `/wordpress/admin` ha cinque sezioni, ciascuna con le sue schede: **Cruscotto**; **Piani** (piani e prezzi, versioni del plugin); **Clienti** (licenze, clienti e domini); **Impostazioni** (generali, pagamenti, email, registro); **Pagine** (privacy e cookie, script e consenso).

- L'elenco **Licenze** mostra anche quelle «in attesa» (pagamenti mai conclusi), che prima restavano invisibili ma occupavano il dominio (messaggio «dominio già registrato da un altro indirizzo email»). Filtro «In attesa» incluso.
- **Elimina licenza** (elenco e dettaglio) e **Elimina cliente** (scheda Clienti e domini, con elenco dei domini): tolgono dal portale email, domini, licenze, link di accesso e registro scarichi, così email e dominio tornano usabili. Si rifiuta se resta un rinnovo automatico attivo su Stripe/PayPal (prima va disdetto) e non si può eliminare il proprio account. **Il server Wega e i provider non vengono toccati**: la licenza resta su Wega com'è.

## Da Wega: confronto e importazione

Gestione → Clienti → **Da Wega** elenca le licenze che il server Wega ha emesso per il portale (`GET /licenses`, scope `licenses:list`, **ancora da realizzare lato Wega**: richiesta in `docs/WEGA-richiesta-elenco-licenze.md`), le confronta con il database e permette di **importare** quelle mancanti (una o tutte). L'elenco non contiene le chiavi: le licenze importate non mostrano la chiave al cliente. Non sono importabili (con il motivo in tabella) le licenze con cliente che non è un'email, piano sconosciuto al portale o dominio già di un altro cliente. In fondo, le licenze del portale che Wega non elenca. Finché Wega non offre l'elenco la pagina lo dice (404/403) senza errori.

## Rinnovo automatico delle licenze gratuite

Il lavoro giornaliero (`bin/cron.php`) rinnova (`POST /licenses/renew`, senza email) le licenze **gratuite** che scadono entro «giorni di anticipo» (default 2), **salvo** quelle senza accessi da più di «giorni senza accessi» (default 30): quelle scadono. «Accesso» = una verifica valida dal plugin (passa dal rimbalzo) o l'ingresso del cliente nell'area riservata; se non c'è mai stato, vale la data di emissione. Entrambi i limiti sono in Impostazioni (con i rischi). Le licenze a pagamento e di prova non sono toccate; un errore di Wega su una licenza non ferma le altre. La data dell'ultimo accesso è nella colonna `last_seen_at`.

## Avviso «ci sono aggiornamenti» (per il plugin)

`GET /api/plugin/latest`, pubblico e senza autenticazione: `{"free":{"version":"1.1.14","url":"https://…/accedi"},"pro":{…}}`, con l'ultima versione pubblicata in Versioni per ciascun prodotto (un prodotto senza versioni è omesso). Il link porta alla pagina di accesso: gli zip non hanno indirizzi pubblici. Cache pubblica di 5 minuti e lo stesso limite per IP delle verifiche (`/api/license/validate`). Il plugin non manda né chiave né dati del sito.

## Specchio su Wega (percorsi definitivi)

Wega è la copia di sicurezza delle licenze: ogni cambiamento del portale si riflette su Wega. Scope `licenses:manage` e `licenses:reveal-key` (assegnati ai portali esistenti). Usati dal portale: `POST /licenses/{id}/suspend|restore|revoke` (stato annullato/ripristinato e eliminazione di licenze e clienti), `GET /licenses/{id}/key` (recupero della chiave di una licenza che il portale non conserva: pulsante «Recupera la chiave da Wega» nel dettaglio e automatico all'importazione da Wega), `PATCH /licenses/{id}` (disponibile nel client per scadenza, cliente, piano, dominio). Un errore vero di Wega blocca l'operazione (nulla cambia nel portale); se l'operazione non esiste (404/405) si prosegue con un avviso.
## Staff e accesso unico al supporto

- **Staff** (`/wordpress/admin/staff`, tabella `plugin_staff` per il plugin; `staff` resta per la colonna SaaS e come ripiego in lettura finché la migrazione in due tempi non è verificata in produzione): due ruoli. *Amministratore* entra nella gestione del portale e nell'amministrazione del supporto; *Solo supporto* entra solo nell'amministrazione del sito di supporto, mai in `/wordpress/admin`. Gli indirizzi in `admins` di `config.php` restano amministratori e non si cambiano dal pannello. Aggiungere un indirizzo crea anche il cliente, così la persona entra col link per email.
- **Verifica in due passaggi (opzionale, per persona)**: da `/wordpress/admin/sicurezza` ognuno la attiva o la spegne per sé; di base è spenta per tutti. Codici a tempo (TOTP, qualunque app di autenticazione), segreto cifrato in `plugin_staff.totp_secret_enc`. Se attiva: codice a ogni nuovo accesso e poi ogni 8 ore, 5 tentativi all'ora, eventi nel registro. Per spegnerla serve un codice valido. Non c'è un interruttore globale che la renda obbligatoria (per ora).
- **Link «Supporto»** (menu e piè di pagina): da connessi porta a `/supporto/accedi`, che manda al sito di supporto con un token firmato; senza `support.sso_secret` (almeno 32 caratteri, in `config.php`) porta alla home del supporto.
- **Contratto del token** (lato sito di supporto, ancora da realizzare): `{support.url}/sso?t=<payload>.<firma>`, con `payload` = base64url del JSON `{v:1, iss, aud, sub:email, role: admin|support|customer, iat, exp = iat+60s, jti}` e `firma` = base64url(HMAC-SHA256(payload, segreto)). Il sito deve verificare firma, `aud`, `exp` e usare il `jti` una sola volta. `admin` e `support` aprono l'amministrazione (ticket da lavorare); `customer` mostra FAQ e i ticket con quell'email. L'email è già verificata dal portale.
- **Ruolo «Solo supporto»** nel portale: entra in `/wordpress/admin` ma vede solo Licenze e Clienti. Può rinnovare di un periodo, cambiare dominio, disdire, sospendere/riattivare e annotare le licenze. Non può emettere né eliminare licenze o clienti, e non ha accesso a piani e prezzi, versioni, pagamenti, email, pagine, impostazioni, registro, Da Wega e Staff.

## Ritorno al supporto dopo l'accesso

Se `/supporto/accedi` trova la persona non connessa, ricorda la destinazione fissa (`$_SESSION['after_login'] = '/supporto/accedi'`, mai presa dall'indirizzo: nessun redirect aperto) e la manda a `/accedi`. Dopo l'accesso (password o link per email) `startSession` la riporta lì e il token dell'accesso unico (60 secondi) si genera solo a quel punto; senza destinazione ricordata si va a `/account` o `/wordpress/admin` come prima.

## Gratuito unico e aggiornamento diretto solo per il Pro

Dalla 1.2.3 il gratuito è **un solo pacchetto** per tutti, quello per WordPress.org (senza aggiornatore): nel portale il prodotto resta «free» e lo zip da caricare in Versioni è `associazionesemplice.zip` (il Pro è `associazionesemplice-pro.zip`; nomi uguali in build, CI e radice del repository, `release.sh` li legge da `wordpress/dist/`). L'aggiornamento diretto da WordPress (`POST /api/plugin/update`) lo usa **solo il Pro**, con chiave e dominio; per il gratuito l'endpoint resta disponibile ma il plugin non lo chiama. `/api/plugin/latest` resta per gli avvisi. Ultimi caricati: gratuito 1.2.3, Pro 1.1.6.

## Revisione di sicurezza (17–19 ottobre 2026)

Corretto: intestazioni (`X-Content-Type-Options`, `Permissions-Policy`, CSP minima `object-src 'none'; base-uri 'self'; frame-ancestors 'none'`, oltre a HSTS e X-Frame-Options); le API, i webhook e le chiavi pubbliche non aprono più la sessione (niente cookie né file per ogni chiamata); sessioni in modalità rigida e con scadenza per inattività (`session_hours`, default 12); limite orario per IP sui moduli pubblici (licenza gratuita, acquisto, link di accesso: `form_ip`, default 10); i pagamenti Stripe non immediati (SEPA e simili) emettono la licenza solo a incasso avvenuto (`payment_status` paid o no_payment_required, evento `checkout.session.async_payment_succeeded`); una richiesta mai completata tiene occupato il dominio solo `pending_hold_hours` (48) e chi paga non è bloccato da una licenza gratuita o di prova altrui; il ruolo amministratore si rilegge dall'email anche per scarichi e dettagli degli errori (niente privilegi residui nella sessione); `deploy.sh` controlla la sintassi su una cartella d'appoggio e, se c'è un errore, non tocca il sito in funzione.

Verificato senza problemi: firme dei webhook (Stripe HMAC con tolleranza, PayPal con verifica dell'API), un solo trattamento per evento, query tutte preparate, escape dell'HTML, CSRF su ogni modulo, cookie `Secure`/`HttpOnly`/`SameSite`, nessun file sensibile raggiungibile dal web, token degli scarichi firmati e a scadenza breve, percorsi dei pacchetti validati, nessuna verifica dei certificati disattivata.

Da decidere / configurare (non risolvibile solo nel codice):
- **Proprietà del dominio**: oggi chiunque può registrare una licenza gratuita per un dominio non suo (serve solo un'email). Con la modifica sopra non blocca più gli acquisti, ma può occupare il dominio per la licenza gratuita. Una verifica vera richiede un controllo (record DNS TXT o file) da scegliere.
- **PayPal**: l'evento `BILLING.SUBSCRIPTION.ACTIVATED` emette la licenza; se il primo addebito dovesse fallire dopo l'approvazione il cliente avrebbe la licenza fino alla scadenza. Alternativa: emettere solo con `PAYMENT.SALE.COMPLETED`.
- **Ruolo «solo supporto»** può rinnovare di un periodo una licenza senza pagamento (e rileggere le chiavi): scegliere se limitarlo.
- Dashboard Stripe: abilitare solo i metodi di pagamento voluti e l'evento `checkout.session.async_payment_succeeded` sul webhook.

## Seconda revisione di sicurezza (28 ottobre 2026)

Riletti tutti i file di `portale/src`, le viste e gli script; le correzioni della prima revisione reggono.

Corretto:
- **Script nel pannello (alta)**: la conferma «Elimina» di clienti, staff, licenze, versioni e cambio piano metteva email o nomi dentro una stringa JavaScript con il solo `e()`. Il browser decodifica `&#039;` prima di eseguire lo script, e il controllo email di PHP accetta anche `"x');...//"@esempio.it` e `x'+setTimeout`…`+'@esempio.it`: chi si iscriveva con un indirizzo del genere poteva far eseguire codice nella sessione dell'amministratore al clic su «Elimina». Ora tutte le conferme passano da `js_confirm()` (JSON, mai concatenazione).
- **Email accettate**: `Billing::validEmail()` rifiuta la parte locale tra virgolette (virgole, parentesi, chiocciole): certi server di posta la leggono in modo diverso e finisce in pagine e messaggi. Vale per iscrizioni, acquisti, staff e importazione da Wega.
- **Limiti aggirabili dietro proxy**: con `trust_forwarded_for` si leggeva la prima voce di `X-Forwarded-For`, che sceglie chi chiama (un indirizzo diverso a ogni tentativo azzerava tutti i limiti). Ora `ClientIp` legge da destra (`forwarded_hops`, default 1) e accetta solo indirizzi validi. Con l'opzione spenta (default) non cambia nulla.

Regola per chi scrive le pagine: un dato dentro un attributo `on…` o dentro uno script va sempre da `json_encode`, mai da `e()`.

Resta da decidere (invariato): proprietà del dominio, evento PayPal che emette la licenza, ruolo «solo supporto» (rinnovo gratuito e lettura delle chiavi). Annotati e non toccati: l'ingresso con un solo clic dal link per email (`/entra/…`: un sito terzo può far entrare un visitatore nell'account di chi ha un link valido, effetto trascurabile) e le due `DELETE` a ogni richiesta pubblica dei limiti orari (costo minimo, nessun rischio di sicurezza).

## Demo pubblica (`associazionesemplice.it/demo/`)

WordPress vero con il gratuito e il Pro attivi, nella cartella `~/public_html/demo` del server del portale (database `associazionesemp_demo`). Le credenziali sono pubbliche e stanno nella pagina `/plugin` del portale: utenti `segreteria` (ruolo Segreteria) e `amministratore` (ruolo `asem_demo_admin` «Amministratore del plugin»: permessi `asem_manage` e `asem_operate`, quindi tutte le pagine del plugin, ma nessun permesso di WordPress su plugin, temi, utenti o impostazioni; nella demo è l'unico che scarica e ricarica la copia di sicurezza, per provare per più giorni; la chiave di licenza è nascosta e non si cambia, e un avviso dice di non inserire chiavi vere). Il socio di prova non è un utente WordPress: è la persona Mario Demo con email `socio@demo.invalid` e password impostata da `provision.php` (accesso proprio, pagina `/?asem_member_login=1`); password e recupero sono bloccati dal mu-plugin. Nessun utente `administrator` di WordPress: quello d'installazione (`setup_tmp`) si toglie a ogni azzeramento, e per la manutenzione si usa `wp` da riga di comando.

File in `demo/` (copiati sul server in `~/demo-provision/`, il mu-plugin in `wp-content/mu-plugins/`):
- `update.sh` porta i due plugin alle ultime versioni pubblicate (vedi sotto).
- `reset.sh` azzera tutto: database pulito, installazione, plugin attivi, dati inventati. Da lanciare ogni notte alle 4 (`0 4 * * * bash ~/demo-provision/reset.sh >> ~/logs/demo-reset.log 2>&1`, da mettere in cPanel → Cron Jobs).
- `provision.php` crea ente, pagine, utenti, 14 soci inventati, quote, spese, un corso e due eventi. Si lancia con `wp eval-file`.
- `asem-demo-style.php` (mu-plugin) e `assets/` (carattere e logo del portale, copiati in `wp-content/mu-plugins/asem-demo-assets/`): aspetto del sito, dell'accesso e del pannello nei colori del marchio. La pagina iniziale e il menu li crea `provision.php`. Le credenziali pubbliche compaiono in `portale/src/Demo.php`, `provision.php` (home) e nell'accesso (`asem-demo-style.php`): se cambiano, vanno cambiate tutte e tre.
- `asem-demo.php` (mu-plugin): nessuna email in uscita, profili e password bloccati (anche cambio e recupero della password del socio di prova, che accede con email e password senza utente WordPress), nessun file caricato, noindex, avviso in ogni pagina, la segreteria entra nella Bacheca del plugin.

Licenza: finché la verifica del plugin è in «standby» valgono tutte le funzioni e non serve nessuna chiave. Quando la verifica si attiva serve una licenza Pro Fiscale per il dominio `associazionesemplice.it` (emissione manuale da `/wordpress/admin/licenze/nuova`), da mettere in `~/.demo_key` (`provision.php` la legge).

Aggiornamento dei plugin: `update.sh` installa nella demo le ultime versioni pubblicate sul portale (le legge da `~/portale/data/releases`, prima il gratuito, poi il Pro). Parte da solo in fondo a `portale/release.sh` a ogni pubblicazione e a ogni azzeramento notturno; non serve fare altro.

## Livello della licenza firmato dal portale

Wega resta la fonte di nome, date e chiave; il **livello** (gratuito, contabile, Pro Full) lo decide e lo firma il portale. `/api/license/validate` aggiunge alla risposta di Wega il campo `portal_attestation`: un JWT EdDSA (intestazione `{alg: EdDSA, kid}`) con `iss` (indirizzo del portale), `aud` (`product_code` di Wega), `bid` (dominio registrato), `sub` (id licenza), `plan` (`free`, `lite`, `accounting` o `fiscal`), `iat` e `exp` (14 giorni). Si firma per **ogni** licenza che Wega dice valida: se il portale non la conosce, l'ha annullata o il piano non ha un livello, la prova c'è lo stesso con livello `free` (il plugin non guarda il nome del piano su Wega e senza la nostra prova non accetterebbe la licenza); non si firma una licenza non valida. Anche l'aggiornamento diretto del Pro (`/api/plugin/update`) usa questo livello. Il livello viene dal codice del piano del portale (tabella `subscriptions`) (`free` → free, `lite` = Pro Contabile → accounting, `full` = Pro Fiscale → fiscal; si cambia con `plan_levels` in `config.php`), mai dal nome su Wega.

Chiave: `data/portal-sign.key` sul server (base64 di 32 byte, permessi 600, fuori dalla cartella pubblica e dal repository) oppure `portal_sign_key` in `config.php`. Si crea con `php bin/portal-key.php`, che stampa solo `kid` e chiave pubblica (`x`) da fissare nel plugin; la privata non si stampa mai. Finché la chiave non c'è il campo non compare e il plugin usa il livello dell'attestazione di Wega. Una chiave sul server vale quanto il database: chi entra nel server potrebbe firmare un livello a piacere (come potrebbe già cambiare i piani). Per cambiare chiave: nuovo `kid` nel plugin, poi sostituire il file.

## Pacchetti firmati (aggiornamento diretto)

L'aggiornamento diretto offre **solo versioni firmate**. La firma è Ed25519 sul messaggio `prodotto|versione|sha256` (UTF-8, sha256 in esadecimale minuscolo dello zip), in base64url senza padding; il plugin verifica impronta e firma con la chiave pubblica del `kid` prima di installare (`POST /api/plugin/update` risponde con `sha256`, `signature`, `kid`).

- **Chiave pubblica** (in `Releases::SIGNING_KEYS`): `pkg-2026-10-85dce7` = `ld_4NilmHT-TtaH8vUw_YGq7eEt4a0e4L4YAxSzP6oI`.
- **Chiave privata**: solo sul computer di chi pubblica, `~/.asem/pacchetti-ed25519.pem` (identificativo in `…kid`), **mai sul server e mai nel repository**: va conservata anche in un secondo luogo sicuro (se si perde non si possono firmare nuove versioni e tocca ruotare la chiave con un rilascio del plugin).
- `bash portale/release.sh free|pro|both` firma e carica; `bash portale/release.sh sign free|pro VERSIONE` firma una versione già pubblicata (serve lo zip in `wordpress/dist`); il pannello Versioni mostra «firmata / non firmata» e un caricamento dal pannello resta senza firma (disponibile per lo scarico dall'area riservata, ma non per l'aggiornamento diretto).
- **Rotazione**: si aggiunge la nuova chiave pubblica a `SIGNING_KEYS` (e al plugin, che deve conoscerle entrambe) prima di firmare con la nuova.

## Dominio registrabile: Public Suffix List completa

`Domain::registrable()` usa la Public Suffix List **completa** (sezioni ICANN e PRIVATE) con l'algoritmo ufficiale (eccezioni «!», regole «*.», regola più lunga): `staging.esempio.it` → `esempio.it`, `www.esempio.co.uk` → `esempio.co.uk`, ma `sito.altervista.org` o `utente.github.io` restano sé stessi, così i clienti di uno stesso ospite non condividono la licenza. Un dominio che è già un suffisso pubblico non è valido. Dati: `portale/src/PublicSuffixData.php` (generato da `public_suffix_list.dat`, scaricato il 2026-10-19, 338207 byte, 10398 regole in punycode; licenza della lista MPL 2.0). Il plugin usa la stessa copia.

## Cambio di piano (upgrade e downgrade)

Dall'area riservata (`/account`), su una licenza acquistata con carta o PayPal, attiva e non disdetta (`src/PlanChange.php`):
- **Upgrade** (piano più caro, stesso periodo): paga subito la differenza `(nuovo − vecchio) × giorni residui / giorni del periodo` (minimo 0,50 €, quello di Stripe) con un pagamento singolo; a pagamento riuscito il tipo di licenza cambia su Wega (`PATCH plan_name`), la **chiave e la scadenza restano le stesse**; su Stripe ricorrente anche il prezzo dei rinnovi passa al piano nuovo (senza proporzioni né addebiti immediati). Ripetibile senza effetti: se un passaggio esterno fallisce il webhook ritenta.
- **Downgrade** (piano più economico): programmato (`next_plan`). La licenza resta com'è fino alla scadenza pagata; al rinnovo (`Billing::renew`) cambia tipo su Wega e prezzo. Su Stripe ricorrente il prezzo del prossimo addebito cambia subito alla programmazione; con pagamento singolo il rinnovo è già proposto al prezzo nuovo. Si può annullare prima del rinnovo.
- Email: `plan_upgraded`, `plan_downgrade_scheduled`, `plan_downgraded` (modificabili da `/wordpress/admin/email`).
- **Non gestito**: PayPal ricorrente (il cambio di prezzo richiede una nuova approvazione del cliente su PayPal: per ora «scrivici dal supporto»), licenze manuali/di prova, passaggio tra periodi (mese ↔ anno), rimborsi. Se al rinnovo Wega non risponde al cambio di tipo, l'errore finisce nel log (`[portale] downgrade: …`) e il tipo va allineato dalla gestione.

## Prezzi e piani creati al volo
Basta l'importo del piano (da `/wordpress/admin/prezzi`): il rinnovo automatico non richiede di preparare prezzi su Stripe o piani su PayPal. **Stripe:** il Checkout in modalità abbonamento usa un prezzo in linea (`price_data` con `recurring.interval`); nei cambi piano di un abbonamento Stripe il nuovo prezzo si crea con `POST /prices` sullo stesso prodotto. **PayPal:** al primo acquisto si crea un prodotto («Associazione Semplice») e un piano per ogni coppia importo/periodo (`/v1/billing/plans`), ricordati in `settings.paypal_auto` (separati tra reale e sandbox) e riusati. I campi «Stripe · prezzo ricorrente» e «PayPal · piano ricorrente» restano come scelta facoltativa per usare prezzi già esistenti. Gli abbonamenti già attivi non cambiano: restano al prezzo con cui sono stati creati.

## Sito pubblico: guida a provare e acquistare (novembre 2026)
- **Un solo punto del layout.** `portale/views/layout.php` genera menu, barra in basso da telefono e fascia di chiusura; i contenuti (funzioni, domande, tappe, inviti, testi brevi dei piani) stanno in `portale/src/Site.php`. Le pagine di presentazione sono elencate in `Site::MARKETING`: solo lì compaiono fascia e barra.
- **Menu:** «Prova la demo» (primario) e «Prezzi» (secondario) in evidenza; «Area riservata» discreto. Da telefono: pulsante «Menu» con tutte le voci e barra in basso con due scorciatoie (`Site::bar`), nascosta quando la fascia di chiusura è in vista.
- **Percorso a tappe:** Funzioni → Demo → Prezzi → Licenza. Ogni pagina ha il suo «Prossimo passo» in `Site::band` (anche `/domande`, `/free`, `/accedi`, `/plugin`).
- **Home più ricca** (funzioni con link, tre piani in sintesi da `views/_plans.php`, tre domande scelte); `/prova` ha il blocco «Ti è piaciuta?» con la scelta del piano (stesso partial).
- **Nomi dei piani:** i testi leggono il nome dal pannello (Prezzi → «Nome sul sito»). Slug fissi `procontabile` e `profiscale` negli indirizzi (`/acquista?plan=…`, `Site::SLUGS`); i codici interni `lite`/`full` restano e i vecchi link funzionano ancora.
- **Logo:** il logo riporta sempre alla home del dominio principale (`base_url`), anche nella demo (`demo/asem-demo-style.php`: sito e pagina di accesso). Il sito di supporto ha il suo logo verso il centro assistenza: da allineare dalla sessione Supporto.

## Confronto tra le versioni: schede a differenze (novembre 2026)
- **Un solo script** (`portale/public/assets/confronto.js`) per `/confronto`, per il popup (`data-confronto`) e per la home della demo; legge `/confronto-demo`, che parte da `portale/content/confronto.md`. Nessuna copia della tabella.
- **Di default solo le funzioni che differiscono** tra i piani; le righe uguali in tutti i piani stanno dietro «Mostra anche le funzioni comuni». La ricerca cerca anche tra le comuni. Intestazione fissa con nome, prezzo (con listino barrato in promozione) e pulsante di ogni piano; da telefono un selettore di piano e una colonna per volta. Il filtro per area è una fila di pulsanti.
- **Righe a due livelli:** nel testo `Titolo breve :: dettaglio`; il dettaglio si apre con il «+». Senza `::`, un titolo oltre 70 caratteri viene diviso in automatico alla prima virgola, parentesi o due punti (il testo intero resta come dettaglio).
- **Nomi dei piani:** nelle tabelle e nei testi di `confronto.md` si scrivono i segnaposto `{free_name}`, `{lite_name}`, `{full_name}` (nome dal pannello, Prezzi → «Nome sul sito»); i prezzi `{lite_month}`… restano. Nome, prezzo e pulsanti dell'intestazione arrivano in un attributo `data-cmp` (JSON) generato da `views/confronto.php`.
- **Formato di `confronto.md`:** invariati i titoli `##` e le tabelle `| Funzione | piano | piano | piano |`; la riga «Prezzo» non compare più (il prezzo è nell'intestazione). Chi aggiorna il file (sessione Portale e pagamenti; Supporto per la tabella gemella) usa `::` per i titoli brevi delle nuove righe.

## Indirizzi del sito e sezioni di contenuti (novembre 2026)

Schema di [ARCHITETTURA_DOMINI.md](ARCHITETTURA_DOMINI.md) §2. Il portale **vende e racconta**; l'amministrazione di ciascun prodotto non è sua.

- **`/saas`** e **`/wordpress`**: sezioni di contenuti (`views/saas_section.php`, `views/wordpress_section.php`); **`/wordpress/changelog`** legge le versioni pubblicate in Versioni (`Releases::all`) e le novità dal `CHANGELOG.md` dello zip (`Sections::releases`). Le pagine sono indicizzabili (`robots.txt` esclude solo `/saas/admin`).
- **`/saas/admin`**: amministrazione del SaaS, codice di `saas/`. Se il server non la serve già sotto il dominio principale (prova di fattibilità, §3.3), il portale reindirizza (302, finché la decisione non è presa) a `saas_admin_url` di `config.php` (default `https://app.associazionesemplice.it/admin`), conservando percorso e query.
- **Vecchi indirizzi** (`src/Sections.php`, provato in `tests/run.php`): `/saas/accedi?t=…` → `/account/da-gestionale?t=…` (301; 308 per POST); `/saas/<id associazione>` e `/saas/utenti/…` (la vecchia gestione delle associazioni dal portale, ritirata: contratto v1 §2.3 cade) → `/saas/admin`. `/admin` → `/wordpress/admin` è nel confine del plugin (`portale/src/Plugin`).
- **Rimossa** la vecchia gestione SaaS del portale (`Platform`, `views/saas/`): l'amministrazione ora è del SaaS. `SaasClient` resta per le chiamate firmate che servono all'acquisto e all'area clienti.
- **`/account/da-gestionale`**: nuovo indirizzo dell'accesso «Gestisci abbonamento» dal gestionale. Per ora rimanda alla pagina di accesso; accetterà il token firmato (contratto §3.2) con il passo di acquisto e abbonamenti.
- Dopo l'accesso, chi ha un livello SaaS nello staff e non quello del plugin va a `/saas/admin`.

## Catalogo del SaaS: listino e confronto generati (novembre 2026)

Il portale **legge** piani, caratteristiche e prezzi del SaaS dal suo catalogo (`catalogo/v1`, contratto `saas/docs/CONTRATTO_PORTALE.md` §2) e non ha un listino SaaS proprio: ogni modifica si fa in `/saas/admin`.

- **Lettura** (`src/Catalog.php`): `GET {saas_api_url}/api/catalogo/v1` con `If-None-Match`. La risposta vale solo se `X-Catalog-Signature` (JWT EdDSA, `typ: catalog`, claim `rev` e `sha256` dei byte ricevuti) torna con una chiave di `saas_public_keys` (`config.php`, `kid => x`). **Anti-rollback**: una `rev` più vecchia di quella salvata, o la stessa `rev` con contenuto diverso, si rifiuta. L'ultima copia valida sta nella tabella `catalog` e serve anche se il SaaS non risponde.
- **Quando si aggiorna:** la prima visita a `/saas` dopo 10 minuti dall'ultimo controllo ricarica il catalogo *dopo* aver consegnato la pagina (il visitatore non aspetta); l'avviso firmato `POST /api/saas/v1/catalog-changed` (`typ: catalog_changed`, `jti` monouso nella tabella `seen_jti`) lo forza subito.
- **Pagina `/saas`:** schede dei piani («da X € al mese + IVA», prova gratuita) e **tabella di confronto** costruita dalle `features` del catalogo (`views/_catalog_saas.php`): nessun prezzo o funzione scritti a mano. Senza chiavi fissate o senza catalogo salvato la sezione dice «a breve».
- **Prezzi** (`src/PriceCalculator.php`): stesse regole del SaaS (centesimi IVA esclusa, scaglioni marginali sui soci, IVA una sola volta sul totale, metà in su); casi comuni in `tests/fixtures/catalogo-casi.json` (copia di `saas/tests/Fixtures/catalogo-casi.json`: se cambia lì, va ricopiata).
- Il confronto del **plugin** resta `content/confronto.md` finché il plugin non espone il suo catalogo (passo 4, sessione portale-plugin); la regola «tabella confronto sempre aggiornata» vale per entrambi.
- Da fare: pagina staff con `rev` e «aggiornato il…», `promo.check`, carrello (passo 6).
- **Due prodotti, stesse pagine** (decisione della titolare, 10/11/2026): `/prezzi` mostra le schede del plugin e, sotto, quelle del gestionale online dal catalogo; `/confronto` la tabella del plugin e sotto quella del gestionale. I due prodotti si distinguono con un'etichetta (`eyebrow`), la navigazione `product-nav` in cima a `/prezzi` e un filetto di separazione; `/saas` e `/wordpress` restano approfondimenti brevi. Il partial `views/_catalog_saas.php` si include con `$show_cards` / `$show_table`.

## Accesso unico al supporto: elenco dei piani (v2)

Il token dell'accesso unico (HMAC, `src/SupportSso.php`) porta, se il cliente ha licenze, `v: 2` e `plans: [{product, plan, support_level, status}]` (al massimo 20 voci, senza prezzi né dati di soci): `product` `plugin|saas`; `plan` lo slug fisso (`free`, `procontabile`, `profiscale`); `support_level` `free|paid|top` (dal catalogo del prodotto; etichette per i clienti «Gratuita», «Standard», «Top»); `status` `active|trial|past_due|cancelled`. Senza licenze il token resta `v: 1`. Il supporto verifica firma, `aud` e `exp` prima di leggere `plans` e ignora i valori sconosciuti. Gli abbonamenti SaaS compariranno con il passo 6.

## Acquisto del SaaS: preventivo, ordine e coda verso il SaaS (novembre 2026)

Fondamenta del passo 6 (nessuna pagina pubblica ancora: il pagamento arriva nel passo successivo).

- **Preventivo** (`src/Quote.php`): dal catalogo corrente e solo da lì. Piano + periodo + soci (+ addon, + promo) → righe, imponibile, sconto, IVA una sola volta sul totale (centesimi, metà in su), `plan_version` e `rev`. Lo sconto percentuale o a importo si applica all'imponibile e non lo porta mai sotto zero.
- **Ordine** (`src/Orders.php`, tabella `orders`): `open` ricarica il catalogo (senza risposta valida non si vende) e fissa nell'ordine prezzo, `plan_version`, regole di prezzo e `rev`. `confirm`, prima di incassare, ricontrolla la `rev`: se è cambiata ricalcola l'istantanea e, se il totale è diverso, mostra «I prezzi sono stati aggiornati: il nuovo totale è …» e chiede di confermare di nuovo. I rinnovi restano sull'istantanea dell'ordine.
- **Client del contratto v2** (`SaasClient`): `provision` (§3.1), `subscription` (§3.2, `PUT`), `promoCheck` e `promoRedeem` (§3.3). Token firmati di 60 secondi con `jti` monouso.
- **Coda** (`src/Outbox.php`, tabella `saas_outbox`, `bin/saas-outbox.php`): le `subscription` si accodano e si ritentano dopo 1, 5, 30 minuti, poi ogni ora, finché 200 o 409 `stale_revision`; con `unknown_plan` si ricarica il catalogo. Senza chiave di firma i messaggi restano in coda. `deploy.sh` aggiunge al crontab `*/5 * * * *` per lo script.
- Da fare: pagamento con PayPal «una tantum», fatture, partner/commissioni, accesso dal gestionale (`/account/da-gestionale`), prova gratuita senza carta (il listino la annuncia, il flusso d'acquisto oggi incassa subito).

### Pagina d'acquisto e pagamento (`/saas/acquista`)

- **Pagina** (`App::saasBuy`, `views/saas_buy.php`): piano, periodo, numero di soci, componenti in più e codice promo dal listino del momento. «Calcola il totale» mostra righe, sconto, IVA e totale (IVA inclusa); solo dopo compaiono i dati (nome associazione, indirizzo `xxx.associazionesemplice.it`, email) e il metodo di pagamento. Le schede del listino (`_catalog_saas.php`) rimandano qui con `?plan=`.
- **«Ordina e paga»** (`SaasCheckout::start`): ricalcola dal catalogo ricaricato ora; se il totale non è più quello visto (`seen_total`) l'ordine non si apre e il cliente rivede il nuovo prezzo. Il codice promo si controlla con `promo.check` (non consuma nulla). Apre l'ordine (istantanea di prezzo e `plan_version`), la riga in `saas_orgs` (`ref` = `o` + id ordine, stabile) e manda a Stripe Checkout (abbonamento, importo = totale dell'ordine) o PayPal (piano creato e ricordato per importo e periodo). Il rinnovo è sempre automatico e allo stesso prezzo.
- **Pagamento riuscito** (`SaasCheckout::paid`, dai webhook `checkout.session.completed` di Stripe e `BILLING.SUBSCRIPTION.ACTIVATED` di PayPal): `pending → paid` con aggiornamento condizionato, poi `provision` (ripetibile; con `slug_taken` riprova con un suffisso), `promo.redeem` (una volta per ordine) e `subscription` in coda con `rev` crescente per associazione; infine `active`. Se un passo fallisce il webhook risponde errore e il provider riconsegna: i passi già fatti non si ripetono. Un identificativo che non è un ordine del gestionale passa alle licenze del plugin come prima.
- **Rinnovi e disdetta** (`SaasCheckout::event`): rinnovo (`invoice.paid` ciclo, `PAYMENT.SALE.COMPLETED`) → `active` con nuova scadenza; pagamento fallito → `past_due` con la scadenza precedente; disdetta/chiusura → `cancelled`. Ogni cambio è una `subscription` nuova.

### Il gestionale nell'area clienti (`/account`)

- **Elenco** (`SaasAccount::forCustomer`): per ogni abbonamento pagato mostra associazione, piano, periodo, soci, importo (IVA inclusa), stato e scadenza dall'ultimo messaggio `subscription` in coda, e «Apri il gestionale» (`https://<slug>.associazionesemplice.it`, dominio base in `saas_tenant_domain`). Compare nello stesso `/account` delle licenze del plugin.
- **Disdetta** (`POST /account/gestionale-disdici`): a fine periodo su Stripe, PayPal annulla l'abbonamento; il gestionale resta attivo fino alla scadenza già pagata. **Fatture e metodo di pagamento**: portale di Stripe (`/account/gestionale-fatturazione`). Ogni azione controlla che l'ordine sia del cliente in sessione.
- **Accesso dal gestionale** (`GET /account/da-gestionale?t=<JWT>`, contratto §4.3): firma, `iss`, `aud`, tipo `sso`, vita massima 2 minuti, `jti` usato una sola volta, `ref` = una sua associazione e `sub` = l'intestatario. Apre la sessione e porta sempre a `/account#gestionale` (nessun indirizzo di ritorno dalla richiesta); se qualcosa non torna, accesso normale senza dire perché.
- Da fare: cambio piano (`plan_change.check`), domini (`domain.check/set`), persone con ruoli, download firmato, fatture del gestionale, email di benvenuto con il link di accesso dopo l'acquisto.

## Catalogo del plugin: bozza e «Pubblica» (`/wordpress/admin/catalogo`)

Sezione **Piani → Catalogo**, solo per gli amministratori. Cosa si decide qui: a chi è rivolto ogni piano, visibilità e ordine, livello di assistenza (`free`/`paid`/`top`), siti ammessi, elenco delle caratteristiche e, per ogni piano, se ciascuna è inclusa, con limite (con nota) o non inclusa.

- **Bozza e pubblicazione.** Le modifiche vanno nella bozza (`plugin_catalog_draft`, una sola) e non le vede nessuno. «Pubblica» crea una **revisione immutabile** (`plugin_catalog_revs`, numero crescente) e svuota la bozza; «Scarta» la butta. Ogni azione è nel registro con chi l'ha fatta.
- **Versione del piano.** `plan_version` sale di uno solo per i piani il cui contenuto è cambiato; le versioni passate restano valide per chi le ha già comprate (la notifica di abbonamento accetta qualunque versione ≤ quella corrente).
- **Controlli prima di pubblicare:** codici caratteristica validi e unici, testi senza HTML (max 160), livello di assistenza valido, siti 1–1000, almeno una caratteristica per ogni piano visibile, almeno un piano visibile.
- **Assistenza per piano:** il gratuito è sempre «Gratuita» (`free`); ogni piano a pagamento sceglie «Standard» (`paid`) o «Top» (`top`); se non si sceglie vale Standard. Finché il catalogo non è pubblicato vale la regola di partenza: il piano a pagamento più economico è Standard, gli altri Top.
- **Confronto tra piani:** ogni caratteristica può avere `group` (il titolo della sezione), `compare` (chiave comune ai prodotti: members, ledger, activities, member_area, online_payments, resources, einvoice, fiscal, site, custom_domain, two_factor, operators, trial; vedi `docs/CONFRONTO_PIANI.md`) e `detail` (descrizione lunga). Per piano: `state` included|excluded|limit e `note`. Il pulsante «Importa nella bozza» carica le righe di `content/confronto.md` con codici stabili (titolo breve in minuscolo con trattini bassi); non pubblica niente. `confronto.md` e il popup `/confronto-demo` restano come sono finché portale-vendita non li sposta sul catalogo.
- **Cosa NON c'è qui (per ora):** nomi e prezzi restano in «Piani e prezzi» (il catalogo li legge da lì in tempo reale) finché il negozio non passa dal catalogo; le promozioni; la firma EdDSA.
- **Nessun effetto prima del primo «Pubblica».** Finché non si pubblica niente, `GET /wordpress/api/catalogo/v1` risponde come prima (dalla configurazione). Classi: `Plugin\CatalogStore` (bozza, controlli, pubblicazione), `Plugin\DbCatalog` (lettura per il portale), `Plugin\ConfigCatalog` (base viva: nomi e prezzi).

### Codici promo del plugin (`/wordpress/admin/codici-promo`)

Decisione 10/11/2026: una promo è **sempre di un prodotto** e vale solo per i suoi piani (nessuna offerta combinata plugin + SaaS). Qui si creano codici con sconto in percentuale (1–100) o in euro, piani validi (nessuno = tutti), date (la fine è obbligatoria, ora italiana, compresa), limite d'uso e stato (sospendi/riattiva). Le promozioni sono dati vivi: non passano da bozza/Pubblica e quelle attive compaiono in `promos[]` del catalogo pubblico (senza dati interni, con gli usi rimasti).

Per il negozio (in processo, `Plugin\Promos`): `check($db,$catalogo,$codice,$piano,$periodo)` risponde «vale?» con sconto, importo finale e scadenza **senza consumare nulla** (motivi: `unknown_code`, `unknown_plan`, `inactive`, `out_of_dates`, `exhausted`, `plan_not_covered`); `redeem($db,$codice,$ordine)` conferma l'uso a pagamento riuscito, **una volta sola per ordine** (richiamarla con lo stesso ordine non consuma altro; se nel frattempo il codice si è esaurito: errore `exhausted` e niente viene registrato). Non ancora: promo automatiche senza codice e cumulabilità (c'è un solo codice per ordine). L'aggancio dal carrello lo fa portale-vendita; la vecchia promozione «in vetrina» (prezzo barrato) resta in «Piani e prezzi».
### Firma del catalogo (EdDSA)

`GET /wordpress/api/catalogo/v1` porta l'intestazione `X-Catalog-Signature: alg="EdDSA", kid="catalogo-plugin-xxxxxxxx", sig="…"`: firma Ed25519 sui **byte esatti** del corpo. Chi legge il catalogo (il negozio, altri siti) fissa la chiave pubblica e rifiuta ciò che non torna (`Plugin\CatalogSigner::verify`).

- **Chiave nuova e separata** da quelle delle licenze (`portal-sign.key`), del contratto col SaaS (`saas-sign.key`) e da quella futura di `admin_hop`. Sta fuori dal repository in `data/catalog-sign.key` (permessi 600) o in `catalog_sign_key` di `config.php`.
- **Creazione:** `php bin/catalog-key.php` sul server (crea se manca, stampa SOLO `kid` e chiave pubblica `x`; `--show` per rileggerli). Prima del primo uso la chiave non esiste: finché manca, il catalogo si serve non firmato (l'ETag resta). Creare la chiave e comunicare `x` ai lettori è un passo di rilascio, da fare con il via della titolare.
- **Rotazione:** nuovo file, nuovo `kid`; il `kid` nell'intestazione dice con quale chiave verificare.

## Elenco centrale dello staff (passo 1 di `docs/UTENTI_E_ACCESSI.md`)

Tabelle nuove `staff_people`, `staff_roles`, `staff_invites`, `staff_sessions`, `staff_audit` e classe `Plugin\Directory`. In questo passo **si aggiunge soltanto**: a ogni `Db::migrate()` gli staff di oggi (`plugin_staff`, colonna `saas` di `staff`, `admins` di `config.php`) vengono copiati nell'elenco con i livelli nuovi (support → supporto); la copia è ripetibile, non rimette un livello cambiato nell'elenco e si ferma per sempre quando nel registro compare un'azione `cutover` (accesso unico in funzione). Gli accessi continuano a essere decisi da `Plugin\Staff` e dalla tabella `staff`: nessun permesso cambia. La super amministratrice sta solo in `super_admins` di `config.php` (non nel repository), è admin di tutte le aree e non ha righe nel database.

### Amministrazione del SaaS sotto il dominio principale e demo del gestionale

- `/saas/admin` e `/wordpress/admin` sono le due amministrazioni delle vendite, su `associazionesemplice.it`. Il rimando 302 di `/saas/admin` verso `saas_admin_url` è provvisorio: con `saas_admin_url` **vuoto** in `config.php` il portale non rimanda più (`Sections::legacy` restituisce `null`) e `/saas/admin` lo serve il SaaS. Il passaggio lo fa portale-saas sul server; al portale basta svuotare la voce al momento giusto.
- Demo del gestionale: `saas_demo_url` (default `https://demo.associazionesemplice.it`) alimenta il pulsante «Prova la demo del gestionale» in `/saas`. La demo del plugin resta `associazionesemplice.it/demo` (`demo_url`). La riga della demo del gestionale in `/confronto` (`confronto.md`) è della sessione «Sviluppo plugin WordPress».

## Accesso staff unico: lato portale (`Plugin\StaffSso`, spento)

L'elenco degli utenti staff vive nel SaaS; `/wordpress/admin` è un'area che consuma (formato in `docs/STAFF_SSO.md`). `Plugin\StaffSso` verifica il token `staff_sso`, apre la sessione dell'area (2 ore di inattività, 8 di durata), riconferma ogni 5 minuti al SaaS (due controlli falliti di fila chiudono) e manda le richieste «Utenti» firmate con la chiave del portale (`data/staff-area.key`, `php bin/staff-area-key.php`). Rotta `POST /wordpress/admin/sso`. Acceso solo con `staff_sso.enabled = true` in `config.php`; **nel codice attuale non cambia nessun permesso**: il collegamento dell'identità alla sessione di `Plugin\Admin` (e il livello «vendita») è il passo successivo. Le tabelle `staff_*` della PR 461 non sono usate e si tolgono a importazione nel SaaS completata. Il secondo fattore resta facoltativo.

### Proxy in PHP verso il SaaS (`/saas/admin`, `/saas/staff`, `/saas/api`, `/saas/vai`)

Il [P] di LiteSpeed sull'hosting non funziona: l'amministrazione del gestionale sotto `associazionesemplice.it` la serve il portale con un proxy (`src/SaasProxy.php`, chiamato da `public/index.php` **prima** della sessione, così non crea né legge la sessione del portale). Si accende con `saas_proxy.enabled` in `config.php`; spento, tutto resta come prima.

- **Verso il SaaS:** stesso percorso, metodo, query e corpo, su `saas_proxy.upstream` (fisso, https, mai preso dalla richiesta). Timeout 10 s, nessuna cache, redirect non seguiti. Solo i quattro prefissi; `..` nel percorso (anche codificato) → 400.
- **Intestazioni:** si inoltrano solo quelle di un elenco (tipo di contenuto, lingua, `Authorization`, `Origin`, `Referer`, token CSRF…) e il `Cookie` **senza** il cookie di sessione del portale. Le `X-Forwarded-*` del client si scartano: il portale mette `X-Forwarded-Host` (dominio del portale), `X-Forwarded-Proto: https` e `X-Forwarded-For` con l'IP vero del client (`ClientIp`). Il SaaS si fida di queste solo dall'IP da cui esce il portale (`SAAS_TRUSTED_PROXIES`).
- **Dal SaaS:** status e corpo così come sono; `Location` verso `app.` riscritta sul dominio del portale (fuori dai prefissi proxati finisce sotto `/saas`); `Set-Cookie` senza `Domain` e mai quello con il nome della sessione del portale; passano solo le intestazioni di contenuto e sicurezza dell'elenco. SaaS che non risponde → 502 con messaggio.
- **Interruttore:** con il proxy acceso il rinvio 302 di `/saas/admin` verso `saas_admin_url` non c'è più (`Sections::legacy`); spegnendolo si torna al rinvio.

## Accesso unico: lato portale (`Identity`, spento — `identity.enabled`)

Specifica del SaaS: `saas/docs/IDENTITA_API.md`. Il portale non conserva password di chi sta nell'elenco unico: le manda al SaaS in una richiesta firmata (chiave `staff_area_sign_key`, `typ = identity_<azione>`, scadenza 60 s, `jti` unico, `ip` del browser) a `POST /saas/api/staff/v1/identity/<azione>`.
- **`/accedi`** (POST email+password): se `identity.enabled`, prima si prova il SaaS. `ok` → sessione unica: il `sid` sta solo nella sessione lato server del portale (cookie HttpOnly/Secure/Lax), mai al browser; si apre anche la scheda cliente se esiste la stessa email e la gestione del plugin se c'è il ruolo `wordpress`. `code_required` → `/twofactor` (ticket 5 minuti). Se il SaaS non conosce l'email o non risponde si prosegue **come oggi con i clienti** (finché i clienti non sono importati). Il link via email resta com'è.
- **`/twofactor`** (codice a sei cifre), **`/accedi/password-dimenticata`** e **`/accedi/reimposta/<codice>`** (la mail la manda il SaaS; `link_base` è fisso `https://associazionesemplice.it/accedi/reimposta/`; il reset chiude tutte le sessioni).
- **`/account`**: se la persona ha la sessione unica mostra «Le tue aree» (pulsanti che fanno `POST /account/area`: il SaaS dà il token `staff_sso` per l'area, il browser lo manda all'indirizzo fisso dell'area). Una persona senza scheda cliente vede solo questo punto d'arrivo.
- **Controllo della sessione**: `identity_session` ogni 5 minuti (e subito prima di «Entra» in un'area); revocata/scaduta = chiusa; due mancate risposte di fila = chiusa.
- **`/account/esci`** revoca la sessione centrale (`identity_logout`) e distrugge quella del portale; anche `/esci` lo fa.
- `/wordpress/admin/accedi` rinvia a `/accedi` quando l'accesso unico è acceso. Ancora da fare: profilo (password, email, 2FA, sessioni) in `/account`.

## Grafica e UX unica (11/11/2026)
Regola per tutti i sistemi: `docs/GRAFICA_UX.md`; valori in `docs/brand/tokens.css`. Nel portale: `portale.css` ha i valori dei token; la voce d'accesso della barra e del piede è «Accedi» / «Il mio account» (mai «Area riservata»); le pagine d'errore hanno «Torna al tuo account» e «Vai alla home»; `bin/link-check.php [indirizzo]` apre le pagine pubbliche e segue i link di barra e piede (esce con 1 se uno non risponde 200).

**Profilo e sicurezza** (`/account/profilo`, con l'accesso unico acceso e la persona nell'elenco unico): password (`identity_password`: chiude le altre sessioni), email (`identity_email`: il SaaS scrive al nuovo indirizzo un link `https://associazionesemplice.it/accedi/conferma-email/<codice>`; la GET mostra solo il pulsante, la POST conferma), secondo livello (`identity_2fa_start/confirm/disable`: il QR arriva dal SaaS e non si conserva), sessioni aperte con «Chiudi» (`identity_profile`, `identity_session_revoke`). Prima di ogni cambio la sessione si riconferma al SaaS. I messaggi di errore sono quelli del SaaS.

**Password dimenticata, una sola** (accesso unico acceso): in `/accedi` c'è solo «Password dimenticata o prima volta qui?». `/accedi/password-dimenticata` chiede il reset al SaaS e, se l'email è anche di una scheda cliente del portale (registrata dopo l'importazione nel SaaS), manda pure il link di accesso del portale; la risposta è la stessa per tutti. **`/supporto/accedi`** funziona anche per chi ha solo la sessione unica (staff senza scheda cliente) e dopo `/accedi` riporta al supporto. Tolto il rinvio al ponte `/saas/staff/accedi` (non esiste più).

## «Gestisci» il gestionale: contratto v2 §3.4–3.6 (spento — `saas_v2.enabled`)
Pagina `/account/gestionale/<id>` (pulsante «Gestisci» in `/account`): **stato** (`org_status`: soci, operatori, spazio, pacchetti, dominio; solo numeri), **indirizzo personalizzato** (se il pacchetto `custom_domain` c'è: `domain_check` poi `domain_set`, mostra i record DNS da creare e lo stato; «Togli» = `domain` null; il certificato lo emette ancora il server, non il SaaS), **cambio piano** (`plan_change_check`: solo la verifica «ci sto dentro?» con ostacoli ed effetti; il cambio vero si fa dal supporto e vale dal rinnovo). Si accende (`saas_v2.enabled` in `config.php`) solo quando il SaaS ha in produzione gli endpoint v2 (PR 531). I codici promo (`promo_check`/`promo_redeem`) sono già nell'acquisto.
**Dominio personalizzato: spento a parte** (`saas_v2.domain`, 12/11/2026): i certificati dei domini dei clienti non sono automatici, quindi il modulo non si mostra ai clienti (né le sue POST rispondono) finché la titolare non decide come gestirli sull'hosting. Stato, cambio piano e promo non hanno questo limite.
Strada decisa il 12/11/2026: il cliente delega il dominio ai nostri nameserver (record NS in `dns`) e il certificato lo emette il SaaS con AutoSSL; stato `active` solo con delega e HTTPS validi, `error` dopo 72 ore senza delega. La pagina avvisa di ricreare la posta (MX) prima di cambiare i nameserver. Resta spento finché la PR 540 del SaaS non è in produzione e la titolare non dice che il passo sul server è pronto.

## Impostazioni del portale: interruttori (`/account/impostazioni`)
Pagina per chi ha il ruolo admin (sessione unica con `wordpress = admin`, o amministratore del portale): caselle da spuntare per le funzioni (`Switches::ALL`: «Gestisci» del gestionale e indirizzo personalizzato). Il valore salvato nel database (`settings`, nome `switches`) sostituisce quello di `config.php` a ogni avvio, vale subito, ed è nel registro. L'accesso unico e il proxy restano in `config.php` di proposito (il proxy parte prima del database; spegnere l'accesso unico potrebbe chiudere fuori tutti). Per aggiungere un interruttore: una riga in `Switches::ALL`.

**«Apri il gestionale»** (12/11/2026): porta sempre a `https://app.associazionesemplice.it/gestionale` (config `saas_app_url`); `<slug>.associazionesemplice.it` (`site_url`) è il sito pubblico/area soci dell'associazione, non il gestionale. Finché gli operatori hanno il login locale (passo 6) entrano dal `/accedi` di `app.`.

## Database: solo PostgreSQL (decisione della titolare, 12/11/2026)

Il portale non deve più avere un database SQL proprio: tutto sta su PostgreSQL, schema `portal`, ruolo `portal_app` (lo crea la sessione SaaS insieme al comando di importazione).
- `Db::connect()` capisce il DSN `pgsql:`; opzioni `schema` (imposta `search_path`) e `pass_file` (password letta da un file fuori dal repository, mai nel `config.php`).
- Su PostgreSQL `Db` traduce da solo `GROUP_CONCAT(x)` in `string_agg` e `LIKE` in `ILIKE`: il resto dell'SQL del portale è già comune.
- La suite di test gira due volte in CI: SQLite in memoria (PHP 7.4/8.1/8.3) e PostgreSQL 16 (job «Portale (PostgreSQL)», variabili `PORTALE_TEST_PG_*`, uno schema nuovo per ogni database di prova).
- Il passaggio dei dati di produzione e lo spegnimento di SQLite avvengono solo con il via libera scritto della titolare, dopo il «pronto» della sessione SaaS e una prova in parallelo.

**Stato (12/11/2026 19:30):** il portale in produzione usa PostgreSQL (schema `portal`, ruolo `associazionesemp_portal`, credenziali in `~/portale/data/pg.php` fuori dal repository, lette dal `config.php` del server). Il database SQLite è stato importato, verificato tabella per tabella e cancellato dal server; la copia finale è stata conservata fuori dal server dalla sessione SaaS. SQLite resta solo per le prove locali e per i test veloci della CI.

## Sostituire un pacchetto già pubblicato (eccezione)

Di norma una versione già presente si rifiuta: per cambiare il pacchetto si aumenta il numero. Solo con l'**ok scritto della titolare** (es. un solo sito che aggiorna a mano) si può sostituire zip, impronta e firma di una versione esistente con lo stesso numero:

```
bash wordpress/build.sh && bash portale/release.sh replace free "nota facoltativa"   # oppure pro
```

Il comando firma sul computer di chi pubblica (come `release.sh free`), carica lo zip e lancia `php bin/release.php replace …` sul server. La firma è obbligatoria e deve corrispondere al nuovo zip; un pacchetto identico o una versione non ancora pubblicata si rifiutano. Il vecchio zip resta sul server come `<versione>.zip.<impronta8>.replaced` (non scaricabile) e l'audit registra `release.replace` con le due impronte. I siti che hanno già installato il vecchio pacchetto non lo vedono come aggiornamento (stesso numero): chi vuole il nuovo lo reinstalla a mano.

## Vendita: prima tranche delle correzioni dell'audit (13/11/2026)

- Dove i pagamenti online non sono attivi (nessuna chiave Stripe né PayPal: `Billing::paymentsOn()`), le pagine dei piani non mostrano «Scegli» ma «Avvisami quando si può acquistare» (porta a `/free`); `buy.php` dice la verità invece di «riprova tra poco». Si riaccende da solo con le chiavi vere.
- Home: sotto la promessa il bivio «Hai già un sito WordPress? Plugin» / «Non hai un sito? Gestionale online»; voce «Gestionale online» nel menu (anche da telefono).
- `/saas/prezzi` → `/prezzi#prezzi-saas` e `/wordpress/confronto` → `/confronto` (301 in `Sections::legacy`).
- Piccoli testi: «ci sono le domande frequenti», «Guarda il gestionale online» (niente sigle).
