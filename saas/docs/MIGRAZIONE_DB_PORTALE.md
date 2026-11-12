# Migrazione del database del portale nel database del SaaS

Decisione della titolare (12/11/2026, in chat): si migra **solo il database** del portale (SQLite `portale/data/portale.sqlite`)
nel database del SaaS (PostgreSQL), poi si spegne il vecchio file. Il codice del portale (pagine di vendita, `/account`,
pagamenti, API dei plugin che fanno da proxy a Wega) **resta** e passa a leggere e scrivere nel SaaS. Le licenze restano
di Wega: il portale non le emette. Documento di riferimento: `LOGIN_UNICO_E_DATABASE.md` (tre database).

## 1. Cosa c'è oggi (inventario del 12/11/2026)

| Tabella | Righe | Destino |
|---|---|---|
| `customers` | 3 | **già nel SaaS** (import clienti, 3/3 verificati) |
| `staff_people`, `staff_roles`, `staff_sessions`, `staff_audit`, `staff_invites` | 1/2/0/0/0 | **già nel SaaS** (identità unica) |
| `staff`, `plugin_staff` | 0 | vecchi accessi: si scartano |
| `subscriptions` | 3 (licenza cifrata, dominio, scadenza) | `portal.subscriptions` |
| `orders` | 0 | `portal.orders` (vuota, scrivibile subito) |
| `saas_orgs`, `saas_outbox` | 0 | `portal.orgs`, `portal.outbox` |
| `releases` (20) + file `data/releases/` | 20 | `portal.releases`; i file restano su disco, percorso nella riga |
| `downloads` | 9 | `portal.downloads` |
| `plugin_catalog_revs`, `plugin_catalog_draft` | 0 | già gestiti dal catalogo del SaaS (rev 2): non si importano |
| `plugin_promos`, `plugin_promo_uses` | 0 | già `core.promo_redemptions` (PR 531): non si importano |
| `email_templates` | 0 | `portal.email_templates` |
| `settings` (6) | 6 | `portal.settings` (`wega_token` resta cifrato con la chiave del portale: il SaaS non lo decifra mai) |
| `audit` | 262 | `portal.audit` |
| `events` | 11 | `portal.events` (dedup dei webhook, evita doppi) |
| `login_tokens`, `seen_jti`, `rate_limits`, `proxy_cache` | – | transitori: si scartano |

## 2. Come

1. **Copia di sicurezza** (fatta il 12/11/2026): istantanea SQLite, `data/releases`, chiavi e `config.php` fuori dal
   server, con impronta verificata.
2. **Schema** (migrazioni centrali) e **comando di import** `saas:portal:import-db`, ripetibile (upsert per id) e con
   conteggi e somme a fine corsa, come l'import clienti.
3. **Connessione diretta**: il portale (stesso server, PostgreSQL su 127.0.0.1, `pdo_pgsql` attivo) usa un ruolo ristretto `portal_app` con diritti solo sullo schema `portal`. Niente API per query. Dati effimeri (`seen_jti`, limiti, cache, token di accesso, sessioni) restano locali. Il cambio nello strato Db del portale lo fa la sessione Portale: vendita e area clienti.
4. **Parallelo**: il portale scrive in entrambi per un periodo; confronto automatico dei conteggi.
5. **Prova con una licenza vera** dai plugin installati (attivazione, controllo, aggiornamento, scarico), identica a prima.
6. **Spegnimento** del vecchio file e dei vecchi accessi **solo dopo la prova e avvisando la titolare**. L'accesso
   di emergenza dal server resta.

## 3. Cosa non cambia

Indirizzi e percorsi pubblici (`/api/license/*`, `/api/plugin/*`, `/.well-known/jwks.json`), chiavi e firme del portale,
proxy verso Wega, pagamenti (webhook Stripe/PayPal agli stessi indirizzi).

## 4. Convenzioni dello schema `portal` (per il portale)

- Lo schema è il file `app/Modules/Portale/Database/Migrations/Central/2026_12_09_000100_create_portal_schema.php` (il DDL per la CI del portale).
- Id come `text` (quelli esadecimali del portale), date come `varchar(19)` (stringhe UTC `Y-m-d H:i:s`) e booleani come `integer` 0/1, identici allo SQLite: il portale
  legge e scrive come oggi (nessuna conversione). JSON (`quote`, `body`, `val`) come `text`, come oggi.
- `events` ha chiave `(provider, event_id)`: l'idempotenza dei webhook è `INSERT … ON CONFLICT DO NOTHING`.
- Credenziali del ruolo: file `~/portale/data/pg.php` (permessi 600), fuori dal repository; i valori non passano mai dalla chat.
- Cutover dietro interruttore nel portale (sqlite → doppia scrittura con confronto → solo Postgres), a «pronto» detto da qui.
