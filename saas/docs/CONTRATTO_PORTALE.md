# Contratto tra portale e SaaS

Come si parlano il portale (`portale/`, centro commerciale: clienti, abbonamenti, pagamenti, partner) e il SaaS
(`saas/`, il gestionale). Il lato SaaS è fatto e provato (`app/Modules/Portale`, test in `tests/Feature/Portale`);
**il lato portale lo sviluppa la sessione Portale e pagamenti**, seguendo questo documento. Versione del contratto:
**v1** (negli indirizzi).

Principio: il SaaS non parla con Stripe o PayPal e non decide nulla sui prezzi. Riceve dal portale **diritti firmati**
e gli restituisce **numeri firmati**. Nessun dato personale dei soci passa dal SaaS al portale.

## 1. Token

Tutto viaggia come JWT EdDSA (Ed25519), **lo stesso formato dell'attestazione delle licenze**
(`portale/src/PortalAttestation.php`): intestazione `{"typ":"JWT","alg":"EdDSA","kid":"…"}`, base64url senza `=`,
firma su `intestazione.corpo`.

Claim sempre presenti:

| Claim | Valore |
|---|---|
| `iss` | chi firma: portale `https://associazionesemplice.it`, SaaS `https://app.associazionesemplice.it` |
| `aud` | verso il SaaS: `associazionesemplice-saas`; verso il portale: l'indirizzo del portale |
| `typ` | tipo del messaggio (sotto); un token di un tipo non vale per un altro |
| `iat`, `exp` | secondi Unix; **`exp − iat` al massimo 600** (SSO: 60); tolleranza sull'orologio 60 secondi |
| `jti` | identificativo casuale, per i registri (e per l'SSO: una volta sola) |

Si rifiuta: `alg` diverso da `EdDSA`, `kid` non fissato, firma, emittente, destinatario o tipo sbagliati, token scaduto,
emesso nel futuro o con vita più lunga del massimo, qualunque campo sconosciuto o fuori misura.

### Chiavi

| Chiave | Chi la tiene | Chi ha la parte pubblica |
|---|---|---|
| **Portale → SaaS** (nuova, separata da quella delle licenze) | portale, per esempio `data/saas-sign.key` (seme di 32 byte in base64, permessi 600, fuori dal repository) | SaaS: `SAAS_PORTAL_PUBLIC_KEYS="kid=x"` (più chiavi separate da virgole, per la rotazione) |
| **SaaS → portale** | SaaS: `SAAS_PORTAL_SIGNING_KEY` | portale: kid `saas-…` e `x`, in `config.php` |

Il `kid` si calcola come nel portale: prefisso, poi le prime 8 cifre di `sha256(x)` con `x` = chiave pubblica in
base64url. `php artisan saas:keys:generate` stampa la coppia del SaaS con il suo `kid=x`.
**Rotazione**: si aggiunge la nuova chiave pubblica dall'altra parte, poi si comincia a firmare con la nuova, poi si
toglie la vecchia.

## 2. Portale → SaaS

Corpo sempre `{"token": "<JWT>"}`, `Content-Type: application/json`. Nessun cookie, nessuna sessione, nessun CSRF: vale
solo la firma. Limite: 120 richieste al minuto per indirizzo. Errori: `{"error": "<codice>"}`.

### 2.1 Creazione dell'associazione

`POST https://app.associazionesemplice.it/api/portale/v1/orgs` — `typ: "provision"`

```json
{
  "ref": "sub_123",
  "org":   { "name": "Polisportiva Alfa", "slug": "polisportiva-alfa" },
  "owner": { "email": "titolare@example.com", "name": "Anna Rossi" }
}
```

- `ref`: identificativo dell'abbonamento nel portale (`[A-Za-z0-9_-]{1,64}`), stabile. Lega per sempre l'associazione
  all'abbonamento.
- `slug`: il sottodominio (`polisportiva-alfa.associazionesemplice.it`): minuscole, cifre, trattini, 3–40 caratteri.
  Il portale lo propone e controlla i nomi riservati (ARCHITETTURA.md §3); il SaaS ricontrolla il formato e l'unicità.
- Il SaaS crea l'associazione con la sua chiave di cifratura e manda al titolare l'invito a scegliere la password.

| Risposta | Quando |
|---|---|
| `201 {"org_id": "<uuid>", "created": true}` | creata |
| `200 {"org_id": "<uuid>", "created": false}` | quel `ref` esisteva già: **la richiesta si può ripetere senza effetti** |
| `409 {"error": "slug_taken"}` | sottodominio già usato da un'altra associazione |
| `401 invalid_token` · `422 invalid_claims` · `400 missing_token` | |

Il portale salva `org_id` accanto all'abbonamento e subito dopo manda i diritti (2.2). Finché non arrivano,
l'associazione ha solo la base e un operatore.

### 2.2 Diritti

`PUT https://app.associazionesemplice.it/api/portale/v1/orgs/{org_id}/entitlements` — `typ: "entitlements"`

```json
{
  "sub": "<org_id>",
  "ref": "sub_123",
  "rev": 7,
  "status": "active",
  "until": "2026-12-31",
  "trial_ends": null,
  "members": 150,
  "operators": 3,
  "storage_gb": 5,
  "packages": {
    "member_area": {},
    "resources": { "hourly": { "tier": 5, "web": true }, "seasonal": { "tier": 150, "web": false } },
    "site": {},
    "custom_domain": { "domain": "www.polisportivaalfa.it" },
    "dedicated_db": {},
    "einvoice": { "yearly": 50 },
    "communications": {},
    "advanced_accounting": {},
    "association_life": {},
    "sport": {}
  },
  "partner": "STUDIO-ROSSI"
}
```

| Campo | Significato |
|---|---|
| `sub`, `ref` | associazione e abbonamento: devono coincidere con l'indirizzo e con quanto creato in 2.1, altrimenti `404 unknown_org` |
| `rev` | revisione, **crescente per associazione**: il SaaS applica solo una revisione maggiore dell'ultima (`409 stale_revision` con `current_rev`) |
| `status` | `trial`, `active`, `past_due` (pagamento non riuscito, ancora aperta), `suspended`, `cancelled` |
| `until` | fine del periodo pagato (o della prova). Dopo `until` + **15 giorni** senza diritti nuovi, il SaaS sospende da sé |
| `trial_ends` | fine della prova, se in prova |
| `members` | fascia di soci acquistata (informativa: il prezzo lo calcola il portale dai consumi, §3) |
| `operators` | operatori ammessi in tutto (inclusi + aggiuntivi). Il SaaS blocca gli inviti oltre questo numero |
| `storage_gb` | spazio per i file |
| `packages` | solo i pacchetti attivi, con i loro limiti; un pacchetto assente non è attivo |
| `partner` | codice del partner che ha portato il cliente (`[A-Za-z0-9_-]{1,40}`), o `null` |

Limiti ammessi per pacchetto (nient'altro): `resources` → `hourly`, `daily`, `seasonal`, ciascuno
`{tier: intero, web: bool}` (`web: false` = versione Interna); `einvoice` → `yearly`; `custom_domain` → `domain`;
gli altri → `{}`.

Effetto nel SaaS:

| `status` | Associazione |
|---|---|
| `trial`, `active`, `past_due` | aperta (sospesa se `until` è passato da più di 15 giorni) |
| `suspended` | sospesa: il gestionale non si apre |
| `cancelled` | chiusa |

Il portale manda i diritti **a ogni cambio** (acquisto, rinnovo, cambio di piano o di pacchetti, pagamento non
riuscito, disdetta, scadenza della prova) e può rimandarli quando vuole: con la stessa `rev` la risposta è 409 e non
cambia nulla. Risposta: `200 {"org_id": "…", "rev": 7}`. Ogni cambio finisce nel registro delle azioni
dell'associazione.

Errore di rete o 5xx: il portale **ritenta** (per esempio dopo 1, 5, 30 minuti, poi ogni ora) finché ottiene 200 o 409.

## 3. SaaS → portale

### 3.1 Consumi

`POST https://associazionesemplice.it/api/saas/v1/usage` con `{"token": …}`, `typ: "usage"`, firmato dal SaaS.
Una volta al giorno (04:23) e a richiesta (`php artisan saas:portal:usage`).

```json
{
  "date": "2026-11-04",
  "orgs": [
    { "org_id": "<uuid>", "ref": "sub_123", "members": 142, "operators": 3, "einvoices_year": 0 }
  ]
}
```

- Solo numeri: nessun nome, email o dato dei soci.
- `members`: oggi le schede attive in anagrafica; con il modulo Soci saranno i soci con tessera valida (stesso campo).
- `einvoices_year`: fatture elettroniche emesse nell'anno solare (0 finché non c'è il pacchetto, fase 2).
- Il portale risponde `200` se ha salvato; con un altro codice il SaaS riprova al giro successivo.
- Il portale usa i numeri per la fascia di soci, gli operatori aggiuntivi e le ricariche di fatture.

### 3.2 Accesso unico «Gestisci abbonamento»

Dal gestionale, il titolare (solo lui: permesso `org.subscription`) va su
`https://associazionesemplice.it/saas/accedi?t=<JWT>`, `typ: "sso"`, vita **60 secondi**:

```json
{ "sub": "titolare@example.com", "org_id": "<uuid>", "ref": "sub_123", "role": "owner" }
```

Il portale deve:

1. verificare firma (chiave del SaaS), `iss`, `aud` (il proprio indirizzo), `typ`, scadenza;
2. usare il `jti` **una volta sola** (tenerlo per almeno 2 minuti);
3. controllare che `ref` sia un suo abbonamento e che l'email `sub` sia quella del cliente intestatario: se no, mandare
   alla pagina di accesso normale, senza dire perché;
4. aprire la sessione del cliente e portarlo alla pagina dell'abbonamento di quella associazione.

Come per il supporto, la destinazione è fissa: niente indirizzi di ritorno presi dalla richiesta.

## 4. Da definire più avanti

| Cosa | Quando |
|---|---|
| Fattura elettronica tramite il ponte SDI del portale (invio, esiti, fatture passive) | fase 2, con il pacchetto |
| Richiesta dei diritti dal SaaS (`POST /api/saas/v1/entitlements`, per riallinearsi dopo un guasto) | quando serve; per ora basta il ritento del portale |
| Migrazione di un'associazione dal plugin al SaaS e ritorno | fase 4 |

## 5. Lista di lavoro per la sessione del portale

1. Chiave Ed25519 nuova per il SaaS (come `bin/portal-key.php`), fuori dal repository; dare al SaaS `kid=x`.
2. Fissare in `config.php` la chiave pubblica del SaaS (`saas-…=x`).
3. Piani e pacchetti del SaaS nel listino (PERIMETRO.md §7.2), con `ref` stabile per abbonamento e `rev` per i diritti.
4. Alla prima attivazione: 2.1, salvare `org_id`, poi 2.2. A ogni cambio: 2.2 con `rev + 1`, con ritento.
5. Endpoint 3.1 (consumi) e pagina 3.2 (`/saas/accedi`).
6. Prove contro lo staging del SaaS; i test del SaaS in `tests/Feature/Portale` mostrano richieste e risposte esatte.
