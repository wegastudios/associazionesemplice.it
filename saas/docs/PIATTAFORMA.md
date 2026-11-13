# SaaS: amministrazione della piattaforma, catalogo e demo

Modulo `app/Modules/Piattaforma`. Specifica: [../../docs/ARCHITETTURA_DOMINI.md](../../docs/ARCHITETTURA_DOMINI.md) (§3.3, §4, §5, §8, §9).
Protocollo con il portale: [CONTRATTO_PORTALE.md](CONTRATTO_PORTALE.md) (v2). Questo documento dice **cosa è fatto, cosa
manca e le scelte prese**; si aggiorna a ogni PR del modulo.

## 1. Stato

| # (piano §10) | Cosa | Stato |
|---|---|---|
| 2 | Contratto v2 | scritto; ok di portale-vendita (PR 425); restano campi plugin e `admin_hop` con portale-plugin |
| 3 | Catalogo da file di configurazione + `GET /api/catalogo/v1` firmato | **fatto** (PR 1) |
| 3 bis | Catalogo dal database, versione del piano immutabile | da fare (con il passo 5) |
| 5 | `/saas/admin`: staff, 2FA, editor con bozza e «Pubblica», registro. **Solo la vendita** (decisione 10/11/2026): niente elenco associazioni, utenti o disattivazioni, che stanno nell'app | accesso, riepilogo, registro ed editor del catalogo **fatti**; gestione staff e `admin_hop` da fare |
| 6 | Ricezione di `subscription`, `409 unknown_plan`, limiti dal piano | **fatto** (PR 2) |
| 7 | `admin_hop` | da fare, dopo il passo 5 |
| 9 | Demo `demosaas.associazionesemplice.it` | comando, utenti e soci finti nella PR; altri dati e messa online da fare (§6) |

## 2. Catalogo (fatto)

- `Piattaforma\Contracts\Catalog::published()` restituisce un `PublishedCatalog` (`rev` + documento `catalogo/v1`).
  Oggi lo fornisce `ConfigCatalog` leggendo `config/catalogo.php`; il documento passa sempre da `CatalogValidator`
  (nessun campo sconosciuto, centesimi interi, testo senza HTML, piano senza caratteristiche non pubblicabile, fasce
  crescenti, promo con date coerenti). Un file non valido fa fallire la lettura, non pubblica niente.
- Il protocollo (firma, `ETag`, cache) sta in `Portale\Http\Controllers\CatalogController`: il modulo Portale conosce
  chiavi e JWT, Piattaforma conosce i piani. I due si parlano solo da `Contracts`.
- **Il contenuto iniziale** (`config/catalogo.php`) è il listino di `docs/PERIMETRO.md` §7.2 per ciò che è già deciso:
  base Gestionale a scaglioni, operatori e spazio aggiuntivi, i pacchetti, le tre tipologie di risorse (versione Web),
  dominio e database dedicato. **Mancano** (decisioni della titolare da prendere in `/saas/admin`): offerte composte
  (Associazione, Sportiva, Circolo), sconti per combinazione, risorse in versione Interna, ricariche di fatture, SMS.
- Le promo sono vuote. I prezzi non sono ancora «da vendere»: il portale non incassa finché non si decide.

## 3. Scelte prese

- **Piani come composizione**: il piano `gestionale` è la base a scaglioni di soci; i pacchetti sono `addons` di tipo
  `package` e concedono (`grants`) il pacchetto e i suoi limiti. Così i limiti di `Entitlements` si ricavano da piano +
  addon con una funzione sola (passo 6).
- **Dove stanno le tabelle di piattaforma** (passo 3 bis e 5): non sono di un'associazione, quindi niente `org_id` né
  RLS; vanno nello **schema `core`** (come `core.users` e `core.orgs`) con le sole concessioni necessarie al ruolo
  dell'applicazione e un test che verifica che una tabella senza `org_id` stia in `core`, non in `app`. Da scrivere in
  DATABASE.md insieme alla prima migrazione.
- **Staff della piattaforma separato dagli operatori**: tabella propria, guard proprio, cookie `as_admin` con percorso
  proprio (`/saas/admin`), 2FA obbligatoria (nemmeno spenta in sviluppo: il segreto di prova si imposta con un comando).

## 4. Prova di fattibilità: `associazionesemplice.it/saas/admin`

Da fare **sul server** (accesso SSH: sessione Portale e pagamenti) prima di costruire l'amministrazione. Ordine di prova:

1. **Proxy** da `associazionesemplice.it/saas/admin` al sottodominio `app.` (reverse proxy del web server, o `.htaccess`
   con `[P]` se `mod_proxy` è attivo su cPanel): l'indirizzo resta. Da provare: cookie con percorso `/saas/admin`,
   `X-Forwarded-*` fidati solo dal proxy, asset sotto lo stesso percorso (`ASSET_URL`), reindirizzamenti.
2. **Sottocartella** con la stessa applicazione e `APP_URL` con percorso.
3. **Nessun ripiego** (decisione della titolare, 11/11/2026): l'indirizzo che si vede e si usa è `associazionesemplice.it/saas/admin`.
   `app.associazionesemplice.it/admin` è un'altra cosa (contenuti dell'app per clienti e utenti admin), mai la vendita: niente
   `app./admin/accesso` per lo staff. Anche `accesso`, `esci` e `sso` stanno sotto `/saas/admin` (il token `staff_sso` va a
   `https://associazionesemplice.it/saas/admin/sso`).

L'esito si scrive qui prima della prima riga di codice dell'amministrazione. Per costruire non serve: in sviluppo e nei
test l'amministrazione sta su `/saas/admin` e il prefisso è una sola costante (`config('saas.admin_path')`).

## 5. Amministrazione: accesso (PR 3)

- **Staff proprio**: `core.platform_staff` (ruoli `admin` e `readonly`) e `core.platform_audit` (registro: il ruolo
  dell'applicazione può solo aggiungere righe). Sono tabelle **di piattaforma**: niente `org_id`, schema `core`, nessuna RLS;
  la separazione dagli operatori è di tabella, guardia (`platform`) e sessione, non di riga.
- **Sessione propria**: sotto `config('saas.admin_path')` (`admin`; dietro il proxy `saas/admin`) il middleware
  `UsePlatformSession` usa il cookie `as_admin` (in produzione `__Secure-as_admin`) con `Path` uguale al percorso,
  `SameSite=Strict`, scadenza alla chiusura del browser e dopo 60 minuti, e la guardia `platform`.
- **Accesso**: email e password, poi codice a due passaggi **obbligatorio per tutti** (alla prima volta si attiva con il QR).
  Finché il codice non è giusto la persona non è autenticata (sta «in attesa» nella sessione per 10 minuti). Il segreto
  confermato non si sostituisce dalla pagina. Dopo 8 ore la verifica si ripete. Limiti: 5 tentativi al minuto per
  email+indirizzo, 20 per indirizzo, 5 codici al minuto. Stesso messaggio per email sconosciuta, password sbagliata o
  persona disattivata. Nemmeno in sviluppo si spegne (`SAAS_REQUIRE_TWO_FACTOR` riguarda solo gli operatori).
- **Staff**: nasce solo da riga di comando: `php artisan saas:admin:create email "Nome" --role=admin|readonly` (password
  casuale mostrata una volta); telefono perso: `php artisan saas:admin:reset-2fa email`.
- **Gestione della vendita** (dal 12/11/2026), tutto sulla stessa bozza e con lo stesso validatore del JSON:
  - *Abbonamenti* (`/saas/admin/abbonamenti`, sola lettura): piano e versione, stato, scadenza, pacchetti e promozione di
    ogni associazione, con filtri per stato, piano e testo. Sono i dati che il portale ha comunicato.
  - *Piani* e *Pacchetti* (`catalogo/piani`, `catalogo/pacchetti`): moduli in euro (non centesimi) con caratteristiche,
    limiti, pacchetti inclusi e prezzi (fisso, a fasce di soci, a scaglioni). Salvare un piano **già in vigore** crea
    la versione successiva nella bozza: le versioni pubblicate non cambiano mai. Per ogni piano c'è l'anteprima dei prezzi
    per 30, 50, 150, 400 e 800 soci, calcolata dallo stesso `PriceCalculator` del portale.
  - *Promozioni* (`catalogo/promozioni`): codice, sconto (% o euro), validità, usi massimi, piani. Non cumulabili.
  - *Catalogo*: prima di «Pubblica» elenca in italiano che cosa cambia rispetto alla revisione in vigore; il JSON resta
    come modalità avanzata.
  - Chi è in sola lettura vede tutto e non salva (403). Ogni pubblicazione finisce nel registro e avvisa il portale.
- **Pagine** (sola lettura, Blade senza JavaScript): riepilogo, catalogo (bozza e «Pubblica»), registro. Seguono: gestione dello
  staff, `admin_hop`. L'admin non mostra né modifica associazioni, operatori, soci o utenti: è solo la parte di vendita.

## 6. Demo `demosaas.associazionesemplice.it` (in corso)

Un'istanza separata del SaaS (ARCHITETTURA_DOMINI §9) con database proprio e solo dati finti.

- **Dove vive**: `APP_ENV=demo` (mai `production`), `SAAS_DEMO=true`, database e utenti PostgreSQL propri. Il comando rifiuta di
  partire se la demo è spenta o l'ambiente è la produzione: cancella tutto il database.
- **`php artisan saas:demo:reset`**: `saas:migrate --fresh` sul database condiviso, poi crea «Polisportiva Demo»
  (`saas.demo.org_name`), cinque utenti pubblici (`titolare@`, `amministratore@`, `segreteria@`, `dipendente@`, `commercialista@` su `SAAS_DEMO_MAIL_DOMAIN`, password
  `SAAS_DEMO_PASSWORD`, scritta anche nel pulsante «Prova la demo») e chiama i seeder dei moduli. Ogni notte alle 03:30 dallo scheduler.
- **Dati finti dei moduli**: un modulo implementa `Core\Contracts\DemoSeeder` e si registra col tag `DemoSeeder::TAG`.
  Fatto: soci e tessere (`Soci\Demo\DemoMembers`: codici fiscali e IBAN non validi apposta, email su dominio riservato).
  Fatto anche: due soci ente e tipo di ente APS (per «Dati per il RUNTS»). Da fare: attività, risorse, prima nota, domande aperte, avvisi, socio di prova con area soci.
- **Banner** in testa (`app.blade.php`) e `noindex` su tutte le pagine; nessuna statistica.
- **Sul server demo**: `SAAS_REQUIRE_TWO_FACTOR=false` (solo lì, documentato), `MAIL_MAILER=log` (posta simulata: nessuna mail
  esce), pagamenti simulati quando ci saranno, `SAAS_MEMBERS_DOMAIN=demosaas.associazionesemplice.it` (area soci demo: `polisportiva-demo.demosaas.associazionesemplice.it`)` e DNS jolly a parte.
- **Accessi visibili**: la pagina di accesso della demo elenca i cinque ruoli con email e password (`DemoEnvironment::credentials()`, vuoto fuori dalla demo) e un pulsante «Usa» che compila i campi. Su questo server l'accesso unico degli operatori (`SAAS_UNIFIED_OPERATORS`) resta spento, altrimenti la pagina di accesso rimanda al portale.
- **Rilascio**: il workflow «SaaS · rilascio» ha l'ambiente `demo` (segreti SSH propri). Il primo reset si lancia a mano dopo il primo rilascio: `php artisan saas:demo:reset`; poi lo fa lo scheduler alle 03:30.
- **Online**: serve il sottodominio `demosaas.associazionesemplice.it`, un sub-account con database PostgreSQL propri e il cron, dalla titolare. Link in home dal portale (sessione Portale). Non fatto.

## 7. Accesso unico dello staff (docs/UTENTI_E_ACCESSI.md; formato in docs/STAFF_SSO.md)

Dietro il flag `SAAS_STAFF_SSO` (spento di default; spento resta l'accesso locale della PR 433).

- **Ingresso**: `POST /admin/sso` (`/saas/admin/sso` dietro il proxy), campo `token`, JWT `staff_sso` del portale: `aud`
  `associazionesemplice-saas`, vita ≤ 60 s, `jti` monouso (tabella `core.platform_sso_tokens`, ripulita a ogni ingresso), `area`
  `saas`, `level` `admin|vendita|supporto`, `mfa` bool (il secondo fattore è **facoltativo**, scelto da ciascuno: `mfa_at` c'è solo se
  `mfa` è vero; non si pretende `mfa: true`). Niente CSRF (arriva da un altro sito, protetta dalla firma). Risposta: pagina che
  porta alla home (non una redirezione: il cookie `strict` non seguirebbe un salto partito dal portale).
- **Livelli** → ruolo locale: `admin` = `admin` (tutto, utenti compresi), `vendita` = `sales` (catalogo, prezzi, promo,
  abbonamenti; non gli utenti), `supporto` = `readonly`. La persona nasce o si aggiorna in `core.platform_staff` con password
  casuale che nessuno conosce; se è disattivata non entra.
- **Sessione**: inattività massima 2 ore, durata 8; ogni 5 minuti `POST {portale}/api/staff/v1/session-check` (`staff_session_check`,
  `sid`, `area`); sessione revocata o livello cambiato = si chiude; **due controlli falliti di fila** (portale giù) = si chiude.
  Senza sessione si rimanda a `{portale}/staff/accedi?area=saas`.
- **Da fare**: pagina «Utenti» (list/invite/grant/revoke/deactivate verso il portale), poi, a accesso unico funzionante,
  spegnimento dell'accesso locale in due tempi (resta spento di default un accesso di emergenza della sola titolare).

## 8. Funzioni di /wordpress/admin da portare anche nel SaaS (promemoria, 12/11/2026)

/saas/admin ha la stessa grafica e la stessa organizzazione di /wordpress/admin (schede su due livelli, cruscotto, tabelle).
Confronto delle funzioni: quelle già fatte e quelle che al plugin servono e al SaaS ancora mancano, in ordine di utilità.

**Già fatte nel SaaS**: cruscotto con i numeri, piani e prezzi, pacchetti, promozioni, catalogo con bozza e «Pubblica»,
abbonamenti, registro delle azioni, impostazioni (nameserver dei domini), accesso unico e sola lettura.

**Da fare, per utilità**

1. **Codici promo: usi e sospensione.** La pagina del plugin mostra «usi / massimo», lo stato (attivo, non in corso, sospeso) e
   un pulsante Sospendi/Riattiva. Nel SaaS gli usi esistono già (`core.promo_redemptions`) ma la pagina Promozioni non li
   mostra, e una promozione si toglie solo cancellandola dalla bozza.
2. **Dettaglio abbonamento** (come la pagina di una licenza): cronologia delle revisioni ricevute dal portale, stato del
   dominio personalizzato, pacchetti attivi, promozione usata, **nota interna** non visibile al cliente, collegamento
   all'associazione nell'app.
3. **Cruscotto: incasso mensile ricorrente** (somma dei `price_cents` degli abbonamenti attivi) e riquadro «Da completare»
   con gli avvisi di configurazione che oggi stanno solo nel controllo di salute (posta non configurata, servizio chiavi,
   nameserver vuoti).
4. **Riconciliazione portale ↔ SaaS** (la «Da Wega» del plugin): associazioni del SaaS senza abbonamento (create a mano) e
   abbonamenti del portale che qui non risultano; serve un endpoint del portale che elenchi i `ref`.
5. **Gestione dello staff** (la «Utenti» del plugin): elenco delle persone, livello, stato, invito; oggi si vede solo il
   numero e si crea da riga di comando (docs/UTENTI_E_ACCESSI.md).
6. **Email del SaaS modificabili** (la scheda «Email»): testo e interruttore per prova in scadenza, pagamento in ritardo,
   sospensione, riattivazione; oggi sono fisse nel codice.
7. **Promozione «in vetrina»** con prezzo di listino barrato (la scheda «Piani e prezzi» del plugin): un campo del catalogo
   `catalogo/v1` letto dal portale.
8. **Prova gratuita e promemoria configurabili**: giorni di prova e giorni prima della scadenza in cui avvisare; oggi c'è
   `trial_days` per piano e un solo valore di tolleranza (`grace_days`, oggi 0: sospesa il giorno dopo la scadenza).
9. **Esportazione CSV degli abbonamenti** e ricerca avanzata (per promozione, per piano e versione, per scadenza).
10. **Novità e manutenzione del SaaS** (la scheda «Versioni del plugin»): note di rilascio mostrate agli operatori nell'app e
    avvisi di manutenzione programmata.
11. **Pagine legali e consenso** (Privacy, cookie, script di monitoraggio): condizioni del servizio e informativa privacy del
    SaaS, modificabili da qui come nel portale.

Non portare: pagamenti Stripe/PayPal (restano del portale), emissione licenze, caricamento dei file del plugin.
