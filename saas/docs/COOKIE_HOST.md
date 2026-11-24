# Cookie con il prefisso `__Host-`: proposta e piano di passaggio

Questo documento descrive **regole e procedura**, non lo stato di un ambiente (date, valori e cosa è già stato fatto stanno nel registro dei rilasci riservato).

## Il problema

Le associazioni stanno su `<slug>.associazionesemplice.it`, cioè sono **fratelli** dell'app (`app.associazionesemplice.it`). Un cookie senza prefisso `__Host-` può essere scritto da un sottodominio fratello con `Domain=associazionesemplice.it`, e il browser lo manda all'app insieme (o al posto) di quello vero («cookie tossing»). Con `__Host-` il browser accetta il cookie solo `Secure`, con percorso `/` e **senza** `Domain`, e rifiuta qualunque cookie con lo stesso nome scritto da un altro indirizzo o dal dominio padre.

Già a posto prima di questa modifica: sessione del gestionale (`SESSION_COOKIE=__Host-as_gestionale`), sessione pubblica (`__Host-as_pubblico`), amministrazione (`__Secure-as_admin`, percorso proprio).

## Cosa cambia

| Cookie | Prima | Dopo (HTTPS) | Come |
|---|---|---|---|
| «ricordami» | `remember_web_<sha1 della classe>` | `__Host-remember_web` | guardia `web` = `Core\Auth\HostSessionGuard` (sottoclasse di `SessionGuard` che sovrascrive solo `getRecallerName()`), registrata come driver `host-session` in `config/auth.php` |
| token XSRF per JavaScript | `XSRF-TOKEN` | `__Host-XSRF-TOKEN` | `Core\Http\Middleware\HostPreventRequestForgery` (sottoclasse di `PreventRequestForgery`, sostituita nel gruppo `web`) cambia il nome del cookie; il browser lo legge dal nome scritto dal server nel meta `xsrf-cookie` (`resources/js/lib/xsrf.js`, `http.xsrfCookieName` di Inertia) |
| accesso del socio | `as_socio`, percorso `/area` | `__Host-as_socio`, percorso `/` | `AuthenticateMember::cookieName()` e `AccessController` |

Il prefisso si usa **solo dove vale** (`Core\Contracts\HostCookies::eligible()`): richiesta HTTPS, `session.secure` acceso, `session.path` = `/`, `session.domain` vuoto. Su http (sviluppo), nelle prove su http e sotto `/saas/admin` (percorso della sessione diverso da `/`) i nomi restano quelli di prima. Valore, durata, cifratura (`APP_KEY`), HttpOnly, SameSite e rotazione del token non cambiano.

## Cosa non cambia, e perché

- **Nome del «ricordami» non configurabile in Laravel**: lo costruisce `SessionGuard::getRecallerName()` da nome della guardia e classe, senza impostazioni. Per questo serve una sottoclasse della guardia (22 righe: la costruzione ripete `AuthManager::createSessionDriver`; la prova `HostCookiesTest` fallisce se Laravel cambia qualcosa di visibile).
- **`XSRF-TOKEN` è un comodo, non una difesa**: il confronto avviene tra l'intestazione `X-XSRF-TOKEN` (cifrata con `APP_KEY`) e il token della sessione sul server. Un sottodominio che scrive un cookie omonimo non può far passare una richiesta (non conosce `APP_KEY`), al più la fa rifiutare (419). Il rischio che si chiude è quindi basso; si fa perché è gratuito e uniforma il perimetro.
- **`as_socio` a percorso `/`**: `__Host-` esige `Path=/`, non c'è alternativa (un altro schema, per esempio `__Secure-` con percorso `/area`, proteggerebbe dal solo http ma **non** dalla sostituzione da un fratello, che è il punto). L'effetto collaterale è che il browser manda il cookie anche alle altre pagine dello stesso indirizzo (`/dona`, `/adesione`, file statici): è un codice casuale, HttpOnly, riconosciuto solo dal database, e quelle pagine non lo leggono (le pagine pubbliche usano `__Host-as_pubblico`).
- **Amministrazione `/saas/admin`**: resta con il suo cookie `__Secure-as_admin` e il percorso proprio, non è `__Host-` (esigerebbe `/`). La sostituzione da un fratello è già coperta dal nome non prevedibile del percorso e da `SameSite=Strict`; non cambia nulla.
- Il cookie del **servizio chiavi** e quelli del **portale WordPress** non sono di questa app.

## Piano di passaggio (nessuno viene buttato fuori insieme)

Principio: **lettura doppia, scrittura solo del nome nuovo**. Il codice scrive sempre e solo i nomi nuovi; per un periodo legge ancora i vecchi e, alla prima visita, porta il valore sotto il nome nuovo e cancella il vecchio (`Core\Http\Middleware\MigrateHostCookies`, nel gruppo `web`, dopo la decifratura dei cookie). Le date si impostano nel `.env`:

```
SAAS_LEGACY_REMEMBER_UNTIL=<data ISO>   # fine della lettura del vecchio «ricordami»
SAAS_LEGACY_MEMBER_UNTIL=<data ISO>     # fine della lettura del vecchio as_socio
```

(`config/saas.php` → `legacy_cookies`). **Vuote o scadute = nessuna lettura dei vecchi nomi** (il vecchio cookie, se arriva, viene solo cancellato).

1. **Rilascio del codice** con le due date impostate nello stesso rilascio.
   - «ricordami»: finestra di **14 giorni** (è la durata massima del cookie, che non si rinnova da solo): chi ha il «ricordami» e torna entro 14 giorni non si accorge di niente; oltre i 14 giorni il vecchio cookie sarebbe comunque scaduto. Il cookie nuovo scade **quando sarebbe scaduto il vecchio** (al più alla data di fine finestra): il passaggio non allunga il «ricordami».
   - `as_socio`: finestra di **30 giorni** (è il limite di inattività dell'accesso del socio nel database; oltre, l'accesso non vale comunque). Il cookie nuovo dura 90 giorni, come prima; a decidere resta il database dei soci.
2. **Cosa succede a chi ha ancora il vecchio nome**: alla prima visita dopo il rilascio entra normalmente (nessuna riautenticazione, 2FA compreso: il flusso di sicurezza dei limiti di sessione resta quello di prima), il browser riceve il cookie nuovo e la cancellazione del vecchio. Chi torna **dopo** la fine della finestra: una sola riautenticazione (password o email e codice).
3. **Token XSRF**: nessun passaggio. Il cookie vecchio scade da solo entro la durata della sessione (120 minuti); una scheda aperta prima del rilascio che usa ancora il JavaScript vecchio può avere un 419 una volta, e Inertia ricarica la pagina quando la versione dei file cambia.
4. **Chiusura**: alla scadenza delle date la lettura dei vecchi nomi si spegne da sola. Dopo la finestra più lunga si può togliere dal `.env` le due variabili e, in un rilascio successivo, il ramo di lettura del vecchio (`MigrateHostCookies`, `legacyRecaller`).
5. **Rischio nella finestra**: finché si leggono i vecchi nomi, un sottodominio fratello malevolo potrebbe ancora sostituirli, come oggi (non peggio di prima). Per questo le finestre sono le più corte che non fanno uscire nessuno; chi vuole il massimo rigore accorcia le date e accetta una riautenticazione a chi non torna in tempo.

### Rollback

- **Prima della fine della finestra**: rimettere il codice precedente. Chi si è già spostato sul nome nuovo non è riconosciuto dal codice vecchio: una riautenticazione per il «ricordami» e per il socio (l'accesso nel database del socio resta valido, il socio rifà solo email e codice). Nessun dato perso, nessuna migrazione di database.
- **Senza tornare indietro col codice**: lasciare le due variabili vuote non spegne i nomi nuovi; per rimettere i vecchi serve il codice vecchio. Accettabile: i nomi nuovi non hanno controindicazioni oltre al percorso `/` di `as_socio`.
- Il token XSRF non ha rollback da gestire (si rigenera a ogni sessione).

### Controlli prima e dopo

- Prima: `config/saas.php` e `.env` hanno `SESSION_SECURE_COOKIE=true` e `SESSION_DOMAIN` vuoto (condizioni del prefisso); l'app dietro il proxy riconosce HTTPS (altrimenti `isSecure()` è falso e i nomi non cambiano: nessun danno, ma nessun beneficio).
- Dopo: negli strumenti del browser (scheda Applicazione → Cookie) devono esserci `__Host-XSRF-TOKEN`, `__Host-remember_web` (solo con «ricordami») e, nell'area soci, `__Host-as_socio`, tutti `Secure`, percorso `/`, senza `Domain`; i vecchi nomi spariscono alla prima visita.

## Prove

`tests/Feature/Auth/HostCookiesTest.php`: su HTTPS nome nuovo, `Secure`, percorso `/`, nessun `Domain`, HttpOnly dove previsto; su http nomi di prima; passaggio dal vecchio al nuovo senza riautenticazione (e valore invariato, scadenza non allungata); finestra chiusa o data non valida = vecchio ignorato e cancellato; entrambi i cookie presenti = vale il nuovo; uscita del socio cancella nuovo e vecchio (percorso `/area`); giorni dell'accesso del socio uguali a `MemberAccess::MAX_DAYS`.
