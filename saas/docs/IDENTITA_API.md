# Identità: le richieste del portale al SaaS (specifica)

**Stato: PROPOSTA per portale-vendita.** Segue la decisione della titolare (11/11/2026, `ARCHITETTURA_DOMINI` §14): l'accesso è **uno solo** e sta nel portale; il SaaS è l'unico elenco di persone e risponde a richieste firmate. Questo documento risponde ai quattro punti di portale-vendita.

## 1. Chi fa cosa

| Indirizzo pubblico (portale, alla radice) | Chi lo serve | Cosa fa |
|---|---|---|
| `/accedi` | portale | email e password; se la persona ha il secondo livello passa a `/twofactor`; poi `/account` (o la pagina che voleva) |
| `/accedi/password-dimenticata` | portale | chiede il link; il SaaS manda la mail |
| `/twofactor` | portale | il codice a sei cifre, solo se attivo |
| `/account` | portale | arrivo di tutti: dati, abbonamenti, collegamenti alle aree, profilo (password, email, 2FA, sessioni) |
| `/account/esci` | portale | chiude la sessione unica e quindi tutte le aree |

Il SaaS **non serve pagine alle persone** per l'accesso. Sotto `/saas` restano le sole richieste tecniche tra le aree. La pagina provvisoria `/saas/staff/accedi` (PR 501) è un ponte: resta finché `/accedi` del portale non la sostituisce, poi si toglie (così nessuno resta fuori).

## 2. Come il portale sa chi è (punto 1)

- Alla login riuscita il SaaS risponde con un **`sid`** (32 caratteri esadecimali): la sessione centrale (`core.staff_sessions`, massimo 8 ore, revocabile). Il portale lo tiene **nella sua sessione lato server**; al browser dà un cookie del portale (`HttpOnly`, `Secure`, `SameSite=Lax`, percorso `/`, dominio `associazionesemplice.it`) con un identificativo suo, mai il `sid`.
- Ogni 5 minuti (e prima di ogni azione che cambia qualcosa) il portale chiede `identity_session` : se la sessione è revocata o scaduta, il portale la chiude. Le aree (WordPress, SaaS, Supporto) continuano a ricevere il token `staff_sso` (60 secondi, un solo uso) già esistente.
- Nessuna password e nessun segreto del secondo livello passano per il portale se non nell'attimo della richiesta; il portale non li conserva.

## 3. Le richieste (punti 2 e 3)

Formato già in uso: `POST /saas/api/staff/v1/<azione>`, corpo JSON `{"token": "<JWT EdDSA>"}`, firma con la chiave del portale (area `wordpress`), `iss` del portale, `aud` del SaaS, `exp` entro 60 secondi, `jti` unico (un solo uso), tipo (`typ`) uguale all'azione. Risposta JSON; gli errori sono generici (`{"error":"denied"}`), mai «email inesistente».

| Azione (`typ`) | Dati nella richiesta | Risposta |
|---|---|---|
| `identity_login` | `email`, `password`, `ip` | `{status:"ok", sid, person, areas}` oppure `{status:"code_required", ticket}` (ticket di 5 minuti, un solo uso) |
| `identity_code` | `ticket`, `code`, `ip` | `{status:"ok", sid, person, areas}` |
| `identity_session` | `sid` | `{valid, person, areas, two_factor}` |
| `identity_profile` | `sid` | persona, ruoli per area (`wordpress`/`saas`/`supporto`), secondo livello sì/no, sessioni aperte (data, indirizzo, questa sì/no) |
| `identity_password` | `sid`, `current`, `new` | ok; chiude le altre sessioni |
| `identity_email` | `sid`, `password`, `new_email`, `link_base` | ok; il SaaS scrive al **nuovo** indirizzo un link di conferma (1 ora, un solo uso) |
| `identity_email_confirm` | `code` | ok |
| `identity_2fa_start` | `sid`, `password` | `{secret, qr_svg}` (segreto non ancora attivo) |
| `identity_2fa_confirm` | `sid`, `code` | ok: da qui il secondo livello è attivo |
| `identity_2fa_disable` | `sid`, `password`, `code` | ok |
| `identity_sessions` / `identity_session_revoke` | `sid` / `sid`, `target` | elenco / ok (solo sessioni della stessa persona) |
| `identity_logout` | `sid` | ok (revoca la sessione centrale: le aree lo scoprono al controllo successivo) |
| `identity_reset_request` | `email`, `link_base`, `ip` | sempre ok; la mail parte solo se l'email esiste |
| `identity_reset_complete` | `code`, `password` | ok; chiude tutte le sessioni |
| `identity_token` | `sid`, `to` | `staff_sso` per l'area `to` (solo se la persona ha il ruolo): è il collegamento di `/account` |
| `identity_operator_entry` | `sid` | `{url}`: indirizzo d'ingresso `<app>/accesso-unico?t=…` nel gestionale (biglietto `operator_sso`, 60 s, monouso, porta con sé se il secondo livello è verificato). 403 se la persona non è operatore attivo di un'associazione o se `SAAS_UNIFIED_OPERATORS` è spento (default). Il portale lo usa per «Apri il gestionale» |

Il blocco dei tentativi (5 al minuto per email e indirizzo) e il registro (`core.staff_audit`) sono nel SaaS; l'indirizzo che il portale manda in `ip` è quello del browser.

## 4. Clienti già nel portale (punto 4)

Oggi i clienti stanno nel database del portale. Con la decisione dei tre database confluiscono **nello stesso elenco di persone** del SaaS: una persona, una email, una password, uno o più ruoli (cliente; staff per area). Un cliente che è anche staff ha un'identità sola.

Finché i clienti non sono importati (passo 4 di `LOGIN_UNICO_E_DATABASE.md`, con backup ripristinato e confronto dei conteggi) **`/accedi` accetta lo staff dal SaaS e i clienti dal portale come oggi**; nulla cambia per i clienti. Dopo l'importazione `/accedi` parla solo col SaaS e il portale smette di conservare password. Per l'importazione serve lo schema del database del portale (chiesto a portale-vendita).

## 5. Cosa serve da portale-vendita

1. `/accedi`, `/twofactor`, `/accedi/password-dimenticata`, `/account`, `/account/esci` come da §1, che parlano col SaaS con le richieste di §3.
2. Il cookie di sessione del portale come da §2.
3. `/wordpress/admin/accedi`, il vecchio accesso staff e `/saas/admin/accesso` che rinviano a `/accedi`; il pulsante «Esci» delle aree che punta a `/account/esci`.
4. La chiave di firma del portale (già c'è per `wordpress`) e l'`ip` del browser in ogni richiesta.

## 6. Cosa fa il SaaS

Le richieste di §3 (le tre che esistono già — `login`, `token`, `session-check` — restano valide finché le aree le usano), l'invio delle mail, il registro, i blocchi, il secondo livello (segreto cifrato), la rimozione della pagina provvisoria quando il portale è pronto. **Non** costruisco altre pagine di accesso.
