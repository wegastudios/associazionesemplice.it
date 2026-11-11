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
| 9 | Demo `demoapp.associazionesemplice.it` | comando, utenti e soci finti nella PR; altri dati e messa online da fare (§6) |

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
3. Se nessuna regge: `app.associazionesemplice.it/admin` con redirect 301 da `/saas/admin` (cambia solo l'indirizzo visibile).

L'esito si scrive qui prima della prima riga di codice dell'amministrazione. Per costruire non serve: in sviluppo e nei
test l'amministrazione sta su `/admin` e il prefisso è una sola costante (`config('saas.admin_path')`).

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
- **Pagine** (sola lettura, Blade senza JavaScript): riepilogo, catalogo (bozza e «Pubblica»), registro. Seguono: gestione dello
  staff, `admin_hop`. L'admin non mostra né modifica associazioni, operatori, soci o utenti: è solo la parte di vendita.

## 6. Demo `demoapp.associazionesemplice.it` (in corso)

Un'istanza separata del SaaS (ARCHITETTURA_DOMINI §9) con database proprio e solo dati finti.

- **Dove vive**: `APP_ENV=demo` (mai `production`), `SAAS_DEMO=true`, database e utenti PostgreSQL propri. Il comando rifiuta di
  partire se la demo è spenta o l'ambiente è la produzione: cancella tutto il database.
- **`php artisan saas:demo:reset`**: `saas:migrate --fresh` sul database condiviso, poi crea «Polisportiva Demo»
  (`saas.demo.org_name`), tre utenti pubblici (`titolare@`, `segreteria@`, `commercialista@` su `SAAS_DEMO_MAIL_DOMAIN`, password
  `SAAS_DEMO_PASSWORD`, scritta anche nel pulsante «Prova la demo») e chiama i seeder dei moduli. Ogni notte alle 03:30 dallo scheduler.
- **Dati finti dei moduli**: un modulo implementa `Core\Contracts\DemoSeeder` e si registra col tag `DemoSeeder::TAG`.
  Fatto: soci e tessere (`Soci\Demo\DemoMembers`: codici fiscali e IBAN non validi apposta, email su dominio riservato).
  Da fare: attività, risorse, prima nota, domande aperte, avvisi, socio di prova con area soci.
- **Banner** in testa (`app.blade.php`) e `noindex` su tutte le pagine; nessuna statistica.
- **Sul server demo**: `SAAS_REQUIRE_TWO_FACTOR=false` (solo lì, documentato), `MAIL_MAILER=log` (posta simulata: nessuna mail
  esce), pagamenti simulati quando ci saranno, `SAAS_MEMBERS_DOMAIN=demoapp.associazionesemplice.it` e DNS jolly a parte.
- **Online**: serve il via libera della titolare e l'accesso al server (sottodominio, database, cron). Non fatto.

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
