# Accesso staff unico: formato dei messaggi (SaaS ↔ aree)

Dettaglio tecnico di [UTENTI_E_ACCESSI.md](UTENTI_E_ACCESSI.md) §5 e §7. **Aggiornato l'11/11/2026**: l'elenco unico degli utenti staff vive nel **database del SaaS** (non nel portale) e il SaaS è il fornitore di identità; `/wordpress/admin` (portale) e il supporto sono **aree che consumano**.

Formato JWT come nel contratto portale↔SaaS (`saas/docs/CONTRATTO_PORTALE.md` §1): EdDSA, intestazione `{"typ":"JWT","alg":"EdDSA","kid":"…"}`, base64url senza `=`, vita massima 60 s, tolleranza sull'orologio 60 s; si rifiutano `alg` diverso, `kid` non fissato, firma, `iss`/`aud`/`typ` sbagliati, scaduto, campi sconosciuti.

## Il secondo fattore è facoltativo (decisione della titolare, 11/11/2026)

Opzionale, ognuno per sé, spento di default. `mfa` nel token è solo **informativo** (bool); `mfa_at` c'è solo se `mfa` è vero. Le aree **non** pretendono `mfa: true`. I segreti del secondo fattore di oggi (cifrati con la chiave del portale) **non si migrano**: chi lo usa lo riconfigura.

## Indirizzi (tutti sotto `https://associazionesemplice.it`)

| Cosa | Dove |
|---|---|
| Accesso staff | **nessuna pagina dedicata** (decisione della titolare): ogni area ha il proprio modulo di accesso (portale: `/wordpress/admin/accedi`) e manda la richiesta firmata `POST /saas/api/staff/v1/login` (`typ` `staff_login`: `email`, `password`, `code?`, `ip`, `area`) → il SaaS controlla password, limiti, secondo fattore (solo se la persona l'ha attivato) e registro, e risponde `{"token": "<staff_sso>"}` per quell'area (o un errore generico). La sessione centrale (`sid`) vive solo lato server. Riconferma password: `/saas/staff/riconferma` |
| «Vai a» un'area | l'area chiede `POST /saas/api/staff/v1/token` (`typ` `staff_token`: `sid`, `area`) e riceve `{"token": "<staff_sso>"}` per l'altra area; poi il browser lo manda in POST all'area di destinazione |
| Destinazioni del token (POST, campo `token`) | `/saas/admin/sso` · **`/wordpress/admin/sso`** · `https://supporto.associazionesemplice.it/staff/sso` |
| Richieste delle aree | `/saas/api/staff/v1/session-check` e `/saas/api/staff/v1/people/{list\|invite\|grant\|revoke\|deactivate}` |

## Chiavi

| Chi firma | Cosa | Chiave | Chi verifica |
|---|---|---|---|
| SaaS | `staff_sso` | **nuova**, kid `staff-…` (generata sul server alla pubblicazione, con il via della titolare) | ogni area, con `x` in configurazione (portale: `staff_sso.keys` in `config.php`) |
| Portale | `staff_session_check`, `staff_people` | **nuova**, `data/staff-area.key` (`php bin/staff-area-key.php` stampa solo `kid` e chiave pubblica) | SaaS (`SAAS_STAFF_AREA_KEYS`) |
| Supporto | idem | chiave sua, kid `supporto-…` | SaaS |

## Token `staff_sso` (SaaS → area)

Claim: `iss` = `https://associazionesemplice.it/saas` · `aud` = `associazionesemplice-saas` | `associazionesemplice-portale` | `associazionesemplice-supporto` · `typ` = `staff_sso` · `iat`, `exp` (≤ 60 s) · `jti` (monouso, l'area lo ricorda) · `sub` = email minuscola · `area` = `saas`|`wordpress`|`supporto` · `level` = `admin|vendita|supporto` (SaaS, WordPress) oppure `admin|agente` (Supporto) · `mfa` (bool) · `mfa_at` (solo se `mfa`) · `sid` (32 esadecimali, sessione centrale) · `areas` = mappa area → livello (l'area disegna da qui il selettore «Vai a»; la titolare le ha tutte).

L'area apre una sessione **propria** (inattività massima 2 ore, durata massima 8 ore), manda sempre alla home dell'area e non tiene password. Nel portale: `POST /wordpress/admin/sso` → `Plugin\StaffSso::receive` → 303 a `/wordpress/admin`; se il token non vale → 303 all'accesso staff. Tutto spento finché `staff_sso.enabled` in `config.php` non è `true`.

## `staff.session.check` (area → SaaS, ogni 5 minuti)

`POST /saas/api/staff/v1/session-check`, corpo JSON `{"token": "<JWT>"}` con `typ` = `staff_session_check`, `iss` = indirizzo dell'area, `aud` = `associazionesemplice-saas`, claim `sid`, `area`, `jti` (monouso). Risposta `200`: `{"valid": true, "level": "vendita"}` oppure `{"valid": false, "level": null}` (sessione centrale chiusa, persona disattivata o senza più ruolo in quell'area). Il livello cambiato si adotta subito. Risposta non-200 o rete assente = «non so»: **due controlli falliti di fila** chiudono la sessione (al massimo 10 minuti) e non si aprono sessioni nuove finché il SaaS non risponde.

## Richieste «Utenti» (area → SaaS)

`POST /saas/api/staff/v1/people/{list|invite|grant|revoke|deactivate}`, corpo `{"token": "<JWT>"}` con `typ` = `staff_people`, `aud` = `associazionesemplice-saas`, `jti`, `area`, `act` = email di chi agisce e, per `invite`/`grant`, `email` e `level`; per `revoke`/`deactivate`, `email`.

| Richiesta | Risposta `200` |
|---|---|
| `list` | `{"people":[{"email","name","level","active","locked"}]}` (solo l'area richiesta) |
| `invite`, `grant`, `revoke`, `deactivate` | `{"ok":true}` |

Errori: `403 {"error":"non_autorizzato"|"livello_troppo_alto"|"ultimo_admin"|"super_non_modificabile"|"riconferma_richiesta"}`, `422 {"error":"dati_non_validi"}`. **Decide il SaaS** (UTENTI_E_ACCESSI §4): `act` deve essere admin dell'area (o la titolare), nessuno assegna un livello più alto del proprio né modifica se stesso, l'ultimo admin resta, la titolare non si tocca. `riconferma_richiesta`: l'area manda la persona a `/saas/staff/riconferma` e poi riprova.

## Accesso e uscita del portale (SUPPORT_STAFF_PORTAL_LOGIN)

Decisione dell'11/11/2026: accesso, secondo livello, account e uscita sono del portale (`/accedi`, `/twofactor`, `/account`, `/account/esci`). Con `SUPPORT_STAFF_PORTAL_LOGIN=true` il supporto non serve più moduli di password, recupero o secondo livello: `/admin/login` rinvia a `/accedi` (o a `SUPPORT_STAFF_LOGIN_URL`) e «Esci» chiude la sessione locale e rinvia a `/account/esci` (o `SUPPORT_STAFF_LOGOUT_URL`). Spento di default: si accende quando `/accedi` del portale esiste.
