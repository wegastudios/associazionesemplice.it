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
| `subscriptions` | 3 (licenza cifrata, dominio, scadenza) | `core.portal_subscriptions` |
| `orders` | 0 | `core.portal_orders` (vuota, schema pronto) |
| `saas_orgs`, `saas_outbox` | 0 | `core.portal_orgs`, `core.portal_outbox` |
| `releases` (20) + file `data/releases/` | 20 | `core.plugin_releases`; i file restano su disco, percorso nella riga |
| `downloads` | 9 | `core.plugin_downloads` |
| `plugin_catalog_revs`, `plugin_catalog_draft` | 0 | già gestiti dal catalogo del SaaS (rev 2): non si importano |
| `plugin_promos`, `plugin_promo_uses` | 0 | già `core.promo_redemptions` (PR 531): non si importano |
| `email_templates` | 0 | `core.portal_email_templates` |
| `settings` (6) | 6 | `core.portal_settings` (il segreto `wega_token` resta cifrato, non in chiaro) |
| `audit` | 262 | `core.portal_audit` |
| `events` | 11 | dedup dei webhook: si importa (evita doppi) |
| `login_tokens`, `seen_jti`, `rate_limits`, `proxy_cache` | – | transitori: si scartano |

## 2. Come

1. **Copia di sicurezza** (fatta il 12/11/2026): istantanea SQLite, `data/releases`, chiavi e `config.php` fuori dal
   server, con impronta verificata.
2. **Schema** (migrazioni centrali) e **comando di import** `saas:portal:import-db`, ripetibile (upsert per id) e con
   conteggi e somme a fine corsa, come l'import clienti.
3. **API di dati del SaaS** firmata come le altre (`/saas/api/portale/v3/...`): il portale non apre più il file, legge e
   scrive qui. Cambio nel portale (lo fa la sessione Portale: vendita e area clienti).
4. **Parallelo**: il portale scrive in entrambi per un periodo; confronto automatico dei conteggi.
5. **Prova con una licenza vera** dai plugin installati (attivazione, controllo, aggiornamento, scarico), identica a prima.
6. **Spegnimento** del vecchio file e dei vecchi accessi **solo dopo la prova e avvisando la titolare**. L'accesso
   di emergenza dal server resta.

## 3. Cosa non cambia

Indirizzi e percorsi pubblici (`/api/license/*`, `/api/plugin/*`, `/.well-known/jwks.json`), chiavi e firme del portale,
proxy verso Wega, pagamenti (webhook Stripe/PayPal agli stessi indirizzi).
