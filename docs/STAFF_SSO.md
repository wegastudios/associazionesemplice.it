# Accesso staff unico: formato dei messaggi (portale ↔ aree)

Dettaglio tecnico di [UTENTI_E_ACCESSI.md](UTENTI_E_ACCESSI.md) §5 e §7, scritto da portale-plugin (11/11/2026). Stesso formato JWT del contratto portale↔SaaS (`saas/docs/CONTRATTO_PORTALE.md` §1): EdDSA, intestazione `{"typ":"JWT","alg":"EdDSA","kid":"…"}`, base64url senza `=`, `exp − iat` al massimo 60 secondi per `staff_sso`, 120 per le richieste; tolleranza sull'orologio 60 s; si rifiutano `alg` diverso, `kid` non fissato, firma, `iss`/`aud`/`typ` sbagliati, scaduto, campi sconosciuti.

## Il secondo fattore resta facoltativo (decisione della titolare, 11/11/2026)

Il codice a due passaggi è **opzionale, ognuno per sé, spento di default**. Quindi il claim `mfa` del token dice solo **se la persona l'ha usato in questa sessione** (`mfa: true|false`) e `mfa_at` è presente solo se `mfa` è vero. **Le aree non devono pretendere `mfa: true`**: accettano entrambi i valori e possono mostrarlo. Se in futuro lo si renderà obbligatorio sarà una modifica del solo portale.

## 1. Chiavi

| Chi firma | Cosa | Chiave | Chi verifica |
|---|---|---|---|
| Portale | `staff_sso` (portale → area) | **nuova**, solo per lo staff: `data/staff-sign.key`, `kid` `staff-xxxxxxxx` (si crea con `php bin/staff-key.php`, stampa solo `kid` e chiave pubblica `x`) | ogni area, con `x` in configurazione |
| SaaS | `staff_session_check`, `staff_people` | la chiave del SaaS già in uso (`kid` `saas-…`), parte pubblica già in `saas_public_keys` del portale | portale |
| Supporto | `staff_session_check`, `staff_people` | chiave **sua**, nuova (kid `supporto-xxxxxxxx`), parte pubblica in `support_public_keys` di `config.php` del portale | portale |

## 2. Token `staff_sso` (portale → area)

Il portale lo emette quando la persona sceglie l'area nel selettore «Vai a» e lo manda con una **POST** (campo `token`) a:

| Area | Indirizzo |
|---|---|
| SaaS | `https://app.associazionesemplice.it/admin/sso` |
| Supporto | `https://supporto.associazionesemplice.it/staff/sso` (la sessione supporto può cambiarlo e dirmelo) |

Claim: `iss` = `https://associazionesemplice.it` · `aud` = `associazionesemplice-saas` oppure `associazionesemplice-supporto` · `typ` = `staff_sso` · `iat`, `exp` (≤ 60 s) · `jti` (monouso: l'area lo ricorda fino a `exp`) · `sub` = email minuscola · `area` = `saas` | `supporto` · `level` = `admin|vendita|supporto` (SaaS) oppure `admin|agente` (Supporto) · `mfa` = bool · `mfa_at` = secondi Unix (solo se `mfa`) · `sid` = identificativo della sessione centrale (32 caratteri esadecimali).

L'area: verifica tutto, apre una sessione **propria** con quel livello (inattività massima 2 ore, durata massima 8 ore), manda sempre alla home dell'area (nessun indirizzo di ritorno ricevuto) e **non** tiene password.

## 3. `staff.session.check` (area → portale, ogni 5 minuti)

`POST https://associazionesemplice.it/api/staff/v1/session-check` — corpo: `token=<JWT>` (form) con `typ` = `staff_session_check`, `aud` = `https://associazionesemplice.it`, `iss` = emittente dell'area, claim `sid`, `area`, `jti` (monouso).

Risposta `200 application/json`: `{"valid": true, "level": "vendita"}` oppure `{"valid": false, "level": null}`. `valid:false` se la sessione centrale è chiusa o scaduta, la persona è disattivata o non ha più alcun ruolo in quell'area. **Il livello può cambiare**: l'area adotta subito quello restituito (o chiude se non lo conosce). Risposta non-200 o rete assente = «non so»: l'area chiude la sessione dopo **due controlli falliti di fila** (al massimo 10 minuti) e non apre sessioni nuove finché il portale non risponde (UTENTI_E_ACCESSI §8).

## 4. Richieste «Utenti» (area → portale)

`POST https://associazionesemplice.it/api/staff/v1/people/{list|invite|grant|revoke|deactivate}`, corpo `token=<JWT>` con `typ` = `staff_people`, `aud` = portale, `iss` = area, `jti`, `area` (l'area della pagina), `act` = email di chi agisce (quello della sessione dell'area) e:

| Richiesta | Altri claim | Risposta `200` |
|---|---|---|
| `list` | — | `{"people":[{"email","name","level","active","locked"}]}` (solo l'area richiesta) |
| `invite` | `email`, `level` | `{"ok":true}`: il portale manda l'invito (7 giorni, monouso) |
| `grant` | `email`, `level` | `{"ok":true}` |
| `revoke` | `email` | `{"ok":true}` (toglie il ruolo di quell'area) |
| `deactivate` | `email` | `{"ok":true}` (toglie tutti i ruoli, chiude le sessioni) |

Errori: `403 {"error":"non_autorizzato"|"livello_troppo_alto"|"ultimo_admin"|"super_non_modificabile"|"riconferma_richiesta"}`, `422 {"error":"dati_non_validi"}`. **Il portale decide**: controlla che `act` sia admin dell'area (o la titolare), che nessuno assegni un livello più alto del proprio, che nessuno modifichi se stesso, che l'ultimo admin resti, che la titolare non si tocchi. `riconferma_richiesta` per dare «admin» o disattivare quando l'ultima riconferma di `act` ha più di 15 minuti: l'area rimanda la persona al portale (`/staff/riconferma`) e poi riprova. Nessuna richiesta porta password o segreti del secondo fattore.

## 5. Accesso staff nel portale

`https://associazionesemplice.it/staff/accedi`. Se si apre un'area senza sessione, l'area manda lì con `?area=saas|supporto|wordpress` (solo questi tre valori, mai un indirizzo); dopo l'accesso il portale manda alla home di quell'area. Il portale è anche il selettore «Vai a».
