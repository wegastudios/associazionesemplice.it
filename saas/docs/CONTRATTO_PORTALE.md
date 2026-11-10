# Contratto tra portale e SaaS (v2)

> **Stato: v2 scritta dalla sessione Portale SaaS il 10/11/2026; la sessione portale-vendita ha dato l'ok con 8
> osservazioni, recepite in §2.1.** Resta da concordare con portale-plugin il formato dei campi plugin e `admin_hop`. Le parti già realizzate nel SaaS sono marcate **[fatto]**.
> Fondamento: [../../docs/ARCHITETTURA_DOMINI.md](../../docs/ARCHITETTURA_DOMINI.md) (§4 catalogo, §5 e §5 bis `subscription`
> e area clienti, §8 passaggio tra amministrazioni).

Come si parlano il portale (`portale/`: vendita, clienti, abbonamenti, pagamenti, partner) e il SaaS (`saas/`, il
gestionale e la sua amministrazione).

**Principio (deciso il 10/11/2026): il portale vende, il prodotto decide.** Piani, caratteristiche, limiti, prezzi e
promozioni del SaaS si definiscono **solo** in `/saas/admin`. Il portale li **legge** dal catalogo, incassa e comunica al
SaaS soltanto il **fatto commerciale** («questo abbonamento ha comprato questo piano»). Il SaaS ricava da sé i limiti
dalla versione del piano. Nessun dato personale dei soci passa dal SaaS al portale.

## 0. Cosa cambia rispetto alla v1

| Parte della v1 | Nella v2 |
|---|---|
| §1 Token e chiavi | **invariati** (si aggiungono solo nuovi valori di `typ`, §1) |
| §2.1 Creazione dell'associazione | **invariata** (`/api/portale/v1/orgs`) |
| §2.2 Diritti calcolati dal portale (`entitlements`) | **sostituiti** da `subscription` (§3.2). L'endpoint v1 resta attivo come **transitorio** finché il portale non ha migrato; poi si toglie (§8) |
| §2.3 Amministrazione dal portale (liste, disattivazione utenti) | **cancellata**: l'amministrazione sta in `/saas/admin`. Restano solo le letture dell'area clienti (§3.6). Le rotte `admin/*` della v1 si tolgono quando `/saas/admin` è in linea |
| §3.1 Consumi | **invariati** |
| §3.2 Accesso «Gestisci abbonamento» | invariato, cambia solo l'indirizzo di arrivo nel portale: `https://associazionesemplice.it/account/da-gestionale?t=<JWT>` (prima `/saas/accedi`) |
| Nuovo | catalogo (§2), `promo.check` e `promo.redeem` (§3.3), `plan_change.check` (§3.4), `domain.check` e `domain.set` (§3.5), `catalogo.changed` (§4.2), `admin_hop` (§5) |

## 1. Token (invariati) e tipi di messaggio

Tutto viaggia come JWT EdDSA (Ed25519), **lo stesso formato dell'attestazione delle licenze**
(`portale/src/PortalAttestation.php`): intestazione `{"typ":"JWT","alg":"EdDSA","kid":"…"}`, base64url senza `=`,
firma su `intestazione.corpo`.

Claim sempre presenti:

| Claim | Valore |
|---|---|
| `iss` | chi firma: portale `https://associazionesemplice.it`, SaaS `https://app.associazionesemplice.it` |
| `aud` | verso il SaaS: `associazionesemplice-saas`; verso il portale: l'indirizzo del portale |
| `typ` | tipo del messaggio (tabella sotto); un token di un tipo non vale per un altro |
| `iat`, `exp` | secondi Unix; **`exp − iat` al massimo 600** (SSO e `admin_hop`: 60); tolleranza sull'orologio 60 secondi |
| `jti` | identificativo casuale, per i registri; **monouso** (una sola volta) per tutte le richieste che cambiano qualcosa, per l'SSO e per `admin_hop` |

Si rifiuta: `alg` diverso da `EdDSA`, `kid` non fissato, firma, emittente, destinatario o tipo sbagliati, token scaduto,
emesso nel futuro o con vita più lunga del massimo, qualunque campo sconosciuto o fuori misura.

| `typ` | Direzione | Dove |
|---|---|---|
| `provision` | portale → SaaS | §3.1 |
| `entitlements` (transitorio) | portale → SaaS | v1, §8 |
| `subscription` | portale → SaaS | §3.2 |
| `promo_check`, `promo_redeem` | portale → SaaS | §3.3 |
| `plan_change_check` | portale → SaaS | §3.4 |
| `domain_check`, `domain_set` | portale → SaaS | §3.5 |
| `org_status` | portale → SaaS | §3.6 |
| `catalog` | SaaS → portale (intestazione della risposta del catalogo) | §2 |
| `catalog_changed` | SaaS → portale | §4.2 |
| `usage` | SaaS → portale | §4.1 |
| `sso` | SaaS → portale | §4.3 |
| `admin_hop` | tra le due amministrazioni | §5 |

### Chiavi

| Chiave | Chi la tiene | Chi ha la parte pubblica |
|---|---|---|
| **Portale → SaaS** (separata da quella delle licenze) | portale, per esempio `data/saas-sign.key` (seme di 32 byte in base64, permessi 600, fuori dal repository) | SaaS: `SAAS_PORTAL_PUBLIC_KEYS="kid=x"` (più chiavi separate da virgole, per la rotazione) |
| **SaaS → portale** (firma di catalogo, consumi, SSO, avvisi) | SaaS: `SAAS_PORTAL_SIGNING_KEY` | portale: kid `saas-…` e `x`, in `config.php` |
| **`admin_hop`** (§5) | ciascuna amministrazione, una chiave sua | l'altra |

Il `kid` si calcola come nel portale: prefisso, poi le prime 8 cifre di `sha256(x)` con `x` = chiave pubblica in
base64url. `php artisan saas:keys:generate` stampa la coppia del SaaS con il suo `kid=x`.
**Rotazione**: si aggiunge la nuova chiave pubblica dall'altra parte, poi si comincia a firmare con la nuova, poi si
toglie la vecchia.

## 2. Il catalogo: SaaS → portale, lettura **[fatto]**

`GET https://app.associazionesemplice.it/api/catalogo/v1`

Pubblico (nessun accesso, nessun cookie), **senza dati personali**, cacheabile. Il portale lo scarica ogni 10 minuti e a
ogni avviso `catalogo.changed` (§4.2), e salva l'ultima copia valida.

Intestazioni della risposta:

| Intestazione | Valore |
|---|---|
| `Content-Type` | `application/json; charset=utf-8` |
| `ETag` | `"<sha256 esadecimale del corpo>"`; con `If-None-Match` uguale la risposta è `304` senza corpo |
| `Cache-Control` | `public, max-age=300` |
| `X-Catalog-Signature` | JWT EdDSA del SaaS, `typ: "catalog"`, `aud` = indirizzo del portale, vita 600 s, claim `rev` e `sha256` (impronta **dei byte esatti del corpo**). Il portale verifica firma e impronta prima di fidarsi |

Il corpo non cambia finché non cambia la `rev`; la firma si rifà a ogni risposta (vita breve) sugli stessi byte.

### Documento `catalogo/v1`

```json
{
  "schema": "catalogo/v1",
  "product": "saas",
  "name": "Associazione Semplice · Gestionale",
  "description": "Il gestionale per associazioni e circoli.",
  "rev": 1,
  "published_at": "2026-11-10",
  "currency": "EUR",
  "features": [ { "id": "member_area", "text": "Area soci e pagamenti online", "group": "Pacchetti" } ],
  "plans": [ {
    "code": "gestionale", "plan_version": 1, "name": "Gestionale", "audience": "Associazioni, APS, ODV",
    "order": 1, "visible": true, "support_level": "paid", "trial_days": 30,
    "features": { "member_area": { "state": "excluded" }, "operators": { "state": "limit", "text": "1 incluso" } },
    "limits": { "members": null, "operators": 1, "storage_mb_per_member": 5, "storage_mb_min": 250 },
    "prices": [ { "period": "month", "kind": "members_tiers", "vat_percent": 22,
                  "base_members": 50, "base_cents": 1500,
                  "tiers": [ { "up_to": 150, "unit_cents": 18 }, { "up_to": 400, "unit_cents": 12 }, { "up_to": null, "unit_cents": 6 } ] },
                { "period": "year", "kind": "members_tiers", "months_billed": 10, "vat_percent": 22, "…": "…" } ]
  } ],
  "addons": [ {
    "code": "operators_extra", "name": "Operatore aggiuntivo", "kind": "operators", "visible": true,
    "grants": { "operators": 1 },
    "prices": [ { "period": "month", "kind": "flat", "amount_cents": 300, "vat_percent": 22 } ]
  } ],
  "promos": []
}
```

| Parte | Regole |
|---|---|
| `rev` | intero crescente: cambia a **ogni** pubblicazione |
| `features[]` | `id` stabile (`[a-z0-9_]{1,40}`), `text` senza HTML, `group` per il raggruppamento nella tabella. Per ogni piano `features` dice `state`: `included`, `limit` (con `text`) o `excluded`. Da qui il portale costruisce da solo la tabella di confronto |
| `plans[]` | `code` stabile (`[a-z0-9_]{2,40}`), `plan_version` intero **immutabile**: una modifica crea la versione successiva, quella vecchia resta valida per chi l'ha comprata e **non compare più nel catalogo** (il portale la tiene nelle istantanee degli ordini). `visible: false` = non si vende ma esiste |
| `limits` (piano) | `members` (tetto soci, `null` = nessuno: il prezzo cresce a scaglioni), `operators` inclusi, `storage_mb_per_member` e `storage_mb_min`; **`packages`**: pacchetti inclusi nel piano, con i loro limiti (stesse chiavi dei diritti v1: `resources.hourly.tier` ecc.) |
| `prices[]` | `period` `month` o `year`; `vat_percent` intero; importi **sempre in centesimi interi**. `kind`: `flat` (`amount_cents`), `members_tiers` (`base_members` soci compresi nel `base_cents`, poi `tiers[]` con `up_to` e `unit_cents` per socio al mese; ultimo `up_to` = `null`; `months_billed` per l'annuale, p. es. 10) oppure `step_tiers` (`steps[]` con `up_to` e `amount_cents`, scatto per fascia; l'ultimo `up_to` `null` = «oltre») |
| `addons[]` | `code` stabile, `kind`: `package` (attiva un pacchetto: `grants.package` e i suoi limiti), `operators` (`grants.operators` per unità), `storage` (`grants.storage_gb` per unità), `domain`, `dedicated_db`, `recharge`. Quello che l'addon concede è in `grants` |
| `promos[]` | solo le **attive**: `code` (o `null` = automatica), `name`, `discount` (`{"type":"percent","value":20}` o `{"type":"amount_cents","value":500}`), `plans[]` (codici) o `null` = tutti, `valid_from`, `valid_to` (date), `max_uses`, `stackable` |
| `support_level` | enum `free`, `paid`, `top` (tempi di risposta in ARCHITETTURA_DOMINI §7). Le promo sono sempre di un prodotto: nessuna offerta combinata tra prodotti |

Il portale **non mostra e non incassa mai** un prezzo che non viene dal catalogo. Se il catalogo non risponde usa
l'ultimo salvato per le pagine di vendita, segnando «aggiornato il …» nel pannello staff; **per acquistare** serve il
catalogo corrente: all'apertura del carrello chiede la `rev` (con `If-None-Match`) e la confronta con quella
dell'ordine. Se è cambiata mentre il cliente decideva, lo dice e ricalcola prima di incassare.

### 2.1 Regole di calcolo dei prezzi (concordate con il portale, 10/11/2026)

Il portale calcola l'importo dalle regole `prices[]`; il SaaS non ricalcola (`price_cents` della `subscription` è
informativo). Implementazione di riferimento: `Piattaforma\Catalogo\PriceCalculator`; **casi comuni** (soci = base,
= `up_to`, +1, anno, fasce a scatto, IVA) in `saas/tests/Fixtures/catalogo-casi.json`, da usare tali e quali nei test
del portale.

- Tutti gli importi del catalogo sono in **centesimi, IVA esclusa**. Si sommano gli imponibili di tutte le voci
  dell'ordine **senza arrotondare** (sono interi); l'IVA si calcola **una sola volta sul totale** e si arrotonda al
  centesimo più vicino, metà in su (`intdiv(netto × % × 2 + 100, 200)`). Il portale addebita imponibile + IVA.
- `members_tiers`: `base_members` soci sono compresi in `base_cents`. Ogni socio oltre costa il `unit_cents` della
  fascia in cui cade (**scaglioni marginali**, come le aliquote IRPEF). `up_to` è il **numero totale di soci** in cui la
  fascia finisce (non la sua ampiezza); la prima fascia parte da `base_members` e deve finire oltre; l'ultima è `null`
  (= «oltre»). Importo mensile = `base_cents` + Σ (soci nella fascia × `unit_cents`). Importo del periodo = mensile ×
  `months_billed` (annuale = 10 mensilità). Esempio: 150 soci = 1500 + 100 × 18 = 3300.
- `step_tiers`: l'importo della prima fascia con `quantità ≤ up_to`. Oltre l'ultima fascia chiusa (nessuna aperta) il
  prezzo **non è a listino**: il portale non lo vende (preventivo).
- `flat`: l'importo, per periodo.
- **Soci all'acquisto**: alla prima attivazione il cliente dichiara la fascia (o parte dalla base); poi il prezzo si
  ricalcola dai consumi (§4.1) al rinnovo. **Il SaaS non blocca mai per superamento dei soci**: cresce solo il prezzo
  (`limits.members` è `null` nel piano iniziale).
- **Rinnovi**: un abbonamento resta sulla `plan_version` e sulle regole `prices[]` del suo ordine anche se quella
  versione esce dal catalogo; il portale conserva l'istantanea delle regole nell'ordine. Il catalogo elenca solo le
  versioni **in vendita**, ma **il SaaS conosce tutte le versioni mai pubblicate** (archivio): `409 unknown_plan` solo
  per una versione mai esistita.
- **Anti-rollback**: il portale rifiuta un catalogo con `rev` minore dell'ultimo salvato e con firma o impronta non
  valide, e mostra nel pannello staff `rev` e «aggiornato il …». Il SaaS non fa mai scendere la `rev`.
- **Prova** (`trial_days`): **senza metodo di pagamento**. L'abbonamento nasce `status: trial` con `trial_ends`; alla fine,
  senza pagamento, il portale manda `suspended`.
- **Plugin**: il plugin usa lo stesso `catalogo/v1` con `product: "plugin"`; i campi propri delle licenze
  (`domain_changes` all'anno, siti ammessi, `download`) li definiscono la sessione portale-plugin e il suo contratto; il
  formato qui sopra non cambia (campi aggiuntivi solo nel piano plugin).
- **Promo**: nessun minimo di spesa per ora; se servirà, `promo_check` avrà un `amount_cents` opzionale.

## 3. Portale → SaaS

Corpo sempre `{"token": "<JWT>"}`, `Content-Type: application/json`. Nessun cookie, nessuna sessione, nessun CSRF: vale
solo la firma. Limite: 120 richieste al minuto per indirizzo. Errori: `{"error": "<codice>"}`; i token sono sempre in
un `POST`/`PUT` col corpo, mai nell'indirizzo.

Codici comuni: `400 missing_token` · `401 invalid_token` · `404 unknown_org` · `409 replayed` (`jti` già usato) ·
`422 invalid_claims` (con `detail`).

### 3.1 Creazione dell'associazione **[fatto, invariata]**

`POST /api/portale/v1/orgs` — `typ: "provision"` — claim `ref`, `org {name, slug}`, `owner {email, name}`.

- `ref`: identificativo dell'abbonamento nel portale (`[A-Za-z0-9_-]{1,64}`), stabile. Lega per sempre l'associazione
  all'abbonamento.
- `slug`: sottodominio (`polisportiva-alfa.associazionesemplice.it`), minuscole, cifre, trattini, 3–40 caratteri.
- Risposte: `201 {"org_id","created":true}`, `200 {"org_id","created":false}` (stesso `ref`: **la richiesta si può
  ripetere senza effetti**), `409 slug_taken`, `503 key_service_not_configured` (piattaforma di prova).

Subito dopo il portale manda `subscription` (§3.2). Finché non arriva, l'associazione ha solo la base e un operatore.

### 3.2 Abbonamento

`PUT https://app.associazionesemplice.it/api/portale/v2/orgs/{org_id}/subscription` — `typ: "subscription"`

```json
{
  "sub": "<org_id>", "ref": "sub_123", "rev": 7,
  "status": "active", "until": "2026-12-31", "trial_ends": null,
  "plan": "gestionale", "plan_version": 1,
  "members": 150,
  "addons": [ { "code": "operators_extra", "qty": 2 }, { "code": "member_area", "qty": 1 } ],
  "promo": "PRIMAVERA", "price_cents": 29000, "partner": "STUDIO-ROSSI"
}
```

| Campo | Significato |
|---|---|
| `sub`, `ref` | associazione e abbonamento: devono coincidere con l'indirizzo e con quanto creato in 3.1, altrimenti `404 unknown_org` |
| `rev` | revisione **crescente per associazione**, la stessa numerazione dei diritti v1: si applica solo una `rev` maggiore dell'ultima (`409 stale_revision` con `current_rev`) |
| `status`, `until`, `trial_ends`, `partner` | **invariati rispetto alla v1** (stati `trial`, `active`, `past_due`, `suspended`, `cancelled`; dopo `until` + 15 giorni il SaaS sospende da sé) |
| `plan`, `plan_version` | codice e versione **del catalogo**. Se il SaaS non conosce quella versione: **`409 unknown_plan`, nulla viene applicato** (il portale deve ricaricare il catalogo e riprovare) |
| `members` | fascia di soci acquistata (informativa: il prezzo lo calcola il portale dai consumi, §4.1) |
| `addons[]` | `code` di un addon del catalogo e `qty` ≥ 1. Codice sconosciuto → `409 unknown_addon` |
| `promo`, `price_cents` | informativi, per il registro del SaaS (il SaaS non ricalcola i prezzi) |

**I limiti non arrivano più dal portale.** Il SaaS li ricava dalla `plan_version` (campo `limits` del piano) sommando ciò
che concedono gli addon (`grants` × `qty`):

- operatori ammessi = `limits.operators` del piano + Σ `grants.operators` × `qty`;
- spazio = `max(storage_mb_min, soci × storage_mb_per_member)` + Σ `grants.storage_gb`;
- pacchetti attivi = quelli del piano (`limits.packages`) + quelli degli addon `package`, con i loro limiti.

Il risultato si salva come i diritti v1 (stesso `Entitlements`), quindi tutto il resto del SaaS non cambia. Ogni cambio
finisce nel registro delle azioni dell'associazione (`subscription.updated`: piano, versione, `rev`, stato).

Le associazioni **create a mano** (pilota, `saas:org:create`) non hanno abbonamento: valgono i limiti di base finché non
ne ricevono uno, e una `subscription` le tratta come qualunque altra.

Per la `subscription` non si tengono i `jti` usati: una ripetizione ha una `rev` non maggiore ed è già rifiutata con `409
stale_revision` senza effetti. Risposta: `200 {"org_id", "rev", "plan", "plan_version"}`. Errore di rete o 5xx: il portale **ritenta** (1, 5, 30 minuti,
poi ogni ora) finché ottiene 200, 409 `stale_revision` (già applicata) o `unknown_plan` (poi ricarica il catalogo).

### 3.3 Promozioni

`POST /api/portale/v2/promo/check` — `typ: "promo_check"` — claim `code`, `plan`, `plan_version`, `period`
(`month`/`year`), `ref` (opzionale, abbonamento esistente):

```json
{ "valid": true, "discount": { "type": "percent", "value": 20 }, "valid_to": "2026-12-31", "applies_to": ["gestionale"], "stackable": false }
```

Non valido: `{"valid": false, "reason": "expired"|"unknown"|"wrong_plan"|"exhausted"}` (con `200`: non è un errore del
protocollo). **Non consuma nulla.**

`POST /api/portale/v2/promo/redeem` — `typ: "promo_redeem"` — claim `code`, `order` (identificativo dell'ordine nel
portale, `[A-Za-z0-9_-]{1,64}`), `ref`. **Una volta sola per `order`**: ripetuta restituisce lo stesso esito. Si chiama
al pagamento riuscito. Risposta `200 {"redeemed": true, "uses_left": 41}` o `409 exhausted`.

### 3.4 Cambio di piano

`POST /api/portale/v2/orgs/{org_id}/plan-change/check` — `typ: "plan_change_check"` — claim `ref`, `to_plan`,
`to_plan_version`:

```json
{ "allowed": false, "blockers": ["Hai 180 soci e il piano ne ammette 150"], "effects": ["Si perde il pacchetto Sito"] }
```

Testi in italiano chiaro, senza dati dei soci (solo numeri). Il downgrade vale a fine periodo (decide il portale);
questa richiesta dice solo se i dati rientrano nei limiti del piano più basso. Nessun dato si cancella mai.

### 3.5 Dominio personalizzato (pacchetto `custom_domain`)

- `POST /api/portale/v2/orgs/{org_id}/domain/check` — `typ: "domain_check"` — claim `ref`, `domain`: formato valido, non
  già di un'altra associazione, non nostro. Risposta `{"ok": true}` o `{"ok": false, "reason": "invalid"|"taken"|"reserved"|"no_package"}`.
- `POST /api/portale/v2/orgs/{org_id}/domain/set` — `typ: "domain_set"` — claim `ref`, `domain` (o `null` per toglierlo):
  salva e restituisce lo stato `{"domain", "state": "pending"|"active"|"error", "dns": [{"type":"CNAME","name":"…","value":"…"}], "message": "…"}`.
  Il SaaS verifica il DNS e attiva il certificato; lo stato si rilegge con `org_status` (§3.6). `slug.associazionesemplice.it` non si cambia da qui.

### 3.6 Stato per l'area clienti

`POST /api/portale/v2/orgs/{org_id}/status` — `typ: "org_status"` — claim `ref`:

```json
{ "org_id": "<uuid>", "status": "active", "plan": "gestionale", "plan_version": 1, "rev": 7,
  "members": 142, "members_limit": null, "operators": { "active": 2, "limit": 3 }, "storage_limit_gb": 5,
  "packages": ["member_area"], "domain": { "name": "www.polisportivaalfa.it", "state": "active" } }
```

Solo numeri e stato; nessun nome di operatore o socio. Sostituisce le letture `admin/*` della v1.

## 4. SaaS → portale

### 4.1 Consumi **[fatto, invariati]**

`POST https://associazionesemplice.it/api/saas/v1/usage` con `{"token": …}`, `typ: "usage"`, una volta al giorno (04:23)
e a richiesta (`php artisan saas:portal:usage`). Corpo: `date` e `orgs[]` con `org_id`, `ref`, `members`, `operators`,
`einvoices_year`. Solo numeri. Il portale risponde `200` se ha salvato; altrimenti il SaaS riprova al giro successivo.

### 4.2 Avviso di catalogo cambiato

`POST https://associazionesemplice.it/api/saas/v1/catalog-changed` — `typ: "catalog_changed"` — claim `rev`. Il SaaS lo
manda **alla pubblicazione** di una nuova `rev` dal pannello; il portale risponde `200` e scarica il catalogo (§2) entro
un minuto. Se il portale non risponde, la pubblicazione **riesce lo stesso** (il portale ricarica comunque ogni 10 minuti)
e l'avviso si ritenta; l'esito sta nel registro dell'amministrazione.

### 4.3 Accesso unico «Gestisci abbonamento» **[fatto, cambia l'arrivo]**

Dal gestionale il titolare (permesso `org.subscription`) va su
`https://associazionesemplice.it/account/da-gestionale?t=<JWT>`, `typ: "sso"`, vita **60 secondi**, claim `sub` (email),
`org_id`, `ref`, `role`. Il portale verifica firma, `iss`, `aud`, `typ`, scadenza; usa il `jti` una volta sola (tenuto 2
minuti); controlla che `ref` sia un suo abbonamento e che `sub` sia l'intestatario (se no: accesso normale, senza dire
perché); apre la sessione e porta alla pagina di quell'abbonamento. Destinazione fissa: niente indirizzi di ritorno dalla richiesta.

## 5. Passaggio tra le amministrazioni (`admin_hop`)

Come ARCHITETTURA_DOMINI §8: JWT `typ: "admin_hop"`, 60 secondi, `jti` monouso, `sub` email, `mfa: true`, `from`/`aud`,
inviato con POST da una pagina intermedia; l'amministrazione di arrivo cerca l'email nei **propri** utenti staff (il
ruolo non viene mai dal token) e chiede il codice a due passaggi se `mfa` non è vero o l'ultima verifica ha più di 8
ore. Chiave Ed25519 propria per ciascuna amministrazione. Il dettaglio degli indirizzi (`POST /saas/admin/hop` e
`POST /wordpress/admin/hop`) si fissa con la sessione portale-plugin quando entrambe hanno la loro amministrazione.

## 6. Cosa non arriva mai al portale

Dati dei soci (anagrafica, codice fiscale, contatti, residenza), dati sanitari e certificati, tutori, iscrizioni,
movimenti e ricevute, consensi, contenuto dei file, chiavi di cifratura, password, segreti della verifica in due
passaggi, nomi ed email degli operatori. Passano solo numeri (soci, operatori, spazio), stato e codici di piano.

## 7. Prove e test comuni

- I test del SaaS (`saas/tests/Feature/Portale`, `…/Piattaforma`) mostrano richieste e risposte esatte.
- Valori di prova condivisi tra i due progetti (calcolo delle fasce, casi di `subscription`): `saas/tests/Fixtures/`.
- Prima di ogni rilascio che tocca il contratto: prova del portale contro lo staging del SaaS.

## 8. Migrazione dalla v1

1. SaaS: catalogo in linea (§2), `subscription` accettata (§3.2), `entitlements` v1 ancora accettato.
2. Portale: legge il catalogo, manda `subscription` al posto di `entitlements`; scrive nel registro quando passa.
3. SaaS: l'endpoint `entitlements` v1 risponde `410 gone` un mese dopo che il portale ha migrato **tutti** gli abbonamenti;
   le rotte `admin/*` della v1 si tolgono quando `/saas/admin` è in linea.
4. La `rev` è una sola serie per associazione, così un messaggio v1 vecchio non sovrascrive uno v2 nuovo.

## 9. Lista di lavoro per la sessione del portale

1. Chiave Ed25519 nuova per il SaaS (come `bin/portal-key.php`), fuori dal repository; dare al SaaS `kid=x`; fissare in
   `config.php` la chiave pubblica del SaaS.
2. Lettore del catalogo (§2): cache, verifica `X-Catalog-Signature`, `If-None-Match`, endpoint `catalog-changed` (§4.2).
3. Listino, pagina di confronto e carrello dal catalogo; istantanea di `plan`/`plan_version`/prezzo nell'ordine.
4. Alla prima attivazione: 3.1, salvare `org_id`, poi `subscription` (3.2) con `rev + 1` a ogni cambio, con ritento.
5. `promo.check` / `promo.redeem`, `plan_change.check`, `domain.check` / `domain.set`, `org_status`.
6. Endpoint consumi (4.1) e pagina `/account/da-gestionale` (4.3).
