# Login unico e tre database

**Stato: PROPOSTA, da approvare dalla titolare.** Finché non c'è un «sì» scritto dopo l'approvazione di questo documento non si sposta nessun dato, non si accende nessun accesso nuovo, non si costruisce altro. Nasce dalla decisione dell'11/11/2026.

## 1. Cosa si vuole (parole della titolare)

- **Un solo accesso per tutti**: staff e clienti entrano dalla stessa pagina. Un solo sistema di accesso, un solo recupero password, il secondo livello solo se la persona lo ha attivato (in una schermata nuova); altrimenti entra e basta.
- **Ognuno vede quello che deve vedere**: il cliente la sua area, lo staff le aree in cui ha un ruolo.
- **Tre database soltanto**: supporto (motore deciso dalla sessione supporto), SaaS (comprende anche pagine di vendita e clienti), demo WordPress.

## 2. Com'è oggi

| Cosa | Dove vive | Note |
|---|---|---|
| Staff (persone, ruoli, sessioni, inviti, audit) | SaaS, PostgreSQL `associazionesemp_saas`, `core.staff_*` | Il SaaS è già l'emittente dei token `staff_sso`. |
| Operatori delle associazioni | SaaS, tabella utenti, accesso nel gestionale | Terzo accesso, a parte. |
| Clienti, ordini, licenze, credenziali | Portale, MySQL `portale` | Da chiarire con portale-vendita il riferimento a `data/portale.sqlite` in `config.php`. |
| Supporto | Sessione supporto | Database proprio, motore da decidere. |
| Demo WordPress | WordPress della demo | A parte, resta così. |

Oggi gli accessi sono quindi **quattro** (clienti sul portale, staff in vendita plugin, staff in `/saas/admin`, operatori nel gestionale), con identità in due database. È questo che si elimina.

## 3. Come deve funzionare

**Una sola pagina `/accedi`** (indirizzo pubblico `associazionesemplice.it/accedi`).

1. La persona scrive email e password. Se non ricorda la password usa **l'unico** «password dimenticata».
2. Il sistema riconosce chi è. Se ha attivato il secondo livello, una schermata nuova chiede il codice; altrimenti entra.
3. Dove va dopo l'accesso lo decidono i suoi ruoli, non la pagina da cui è partita:
   - solo cliente → la sua area (`/account`);
   - staff con un'area sola → direttamente lì (vendita del plugin, amministrazione SaaS o supporto);
   - più aree → una scelta di una riga;
   - operatore di un'associazione → il suo gestionale.
4. Le aree riservate non hanno più moduli di accesso propri: chi non ha la sessione viene mandato a `/accedi` e ritorna dove stava andando.

**Un solo sistema di identità**, quello del SaaS: persone, password, secondo livello, sessioni, recupero. Le aree (portale, supporto, gestionale, amministrazione) **chiedono** chi è la persona e quali ruoli ha; non conservano più password proprie. Il token di breve durata già costruito (`staff_sso`, 60 secondi, un solo uso, per area) resta il modo in cui l'identità passa alle aree; si estende ai clienti con lo stesso formato.

## 4. I tre database

1. **SaaS (PostgreSQL)**: identità di tutti (staff, clienti, operatori), associazioni, dati delle associazioni (uno schema ciascuna, isolati), ordini, licenze, pagine di vendita, catalogo. Assorbe il database `portale`.
2. **Supporto**: database proprio; MySQL o PostgreSQL lo decide la sessione supporto. Non conserva password: usa l'identità del SaaS.
3. **Demo WordPress**: a parte, con dati inventati, azzerabile.

## 5. Che cosa del lavoro già fatto resta, cambia o si toglie

**Resta**: emittente di identità (`StaffDirectory`, `StaffTokenIssuer`, `AreaRequestVerifier`); tabelle `core.staff_*`; inviti (`core.staff_invites`, 7 giorni, un solo uso); proxy del portale verso `/saas/*`; `SAAS_PUBLIC_URL` per gli indirizzi dietro il proxy; ruoli per area.

**Cambia**: l'invito e il recupero password usano la stessa pagina `/accedi` e lo stesso sistema; l'emittente impara a riconoscere anche i clienti; un nuovo invito annulla i precedenti (oggi non lo fa).

**Si toglie**: il modulo di accesso locale di `/saas/admin/accesso` e di `/wordpress/admin/accedi`, la «password locale» dello staff e la tabella `platform_staff` come fonte di verità (resta solo l'identità unica).

## 6. Ordine dei passaggi (ognuno richiede il «sì» scritto prima di partire)

1. **Approvazione di questo documento** e decisione di supporto sul suo database.
2. **Pagina `/accedi` unica e recupero password unico**, con il secondo livello opzionale. Prima solo per lo staff (nulla dei clienti si sposta); i moduli separati diventano rinvii.
3. **Ingresso unico nelle aree**: `/saas/admin` e `/wordpress/admin` accettano solo il token; si accende `SAAS_STAFF_SSO`; si prova il passaggio tra le aree.
4. **Importazione dei clienti nel SaaS** (identità, ordini, licenze): con copia di sicurezza, prova su dati di test, ripristino provato, poi «sì» scritto per l'esecuzione vera. Il portale smette di conservare password.
5. **Spegnimento** del database `portale` e dei vecchi accessi, solo dopo una verifica con i dati veri.
6. **Operatori delle associazioni** nello stesso accesso (se confermato dalla titolare, vedi §7).

## 7. Da decidere

- Gli **operatori delle associazioni** entrano anch'essi da `/accedi` (consigliato, coerente con «un solo accesso per tutti») o restano nel loro gestionale?
- Chi è **sia cliente sia staff** con la stessa email: un'identità sola con più ruoli (consigliato).
- Il secondo livello è **obbligatorio per lo staff** o scelto da ognuno? (Oggi spento in sviluppo; prima dei dati veri va riacceso.)
- Il **motore del database di supporto**: lo decide la sessione supporto.

## 8. Cosa non cambia

I limiti di legge e le regole del plugin; il plugin gratuito; il formato dei token; la regola che nessuna password viaggia tra aree.

## 9. Avanzamento

- **Passo 2 (in costruzione, nel repository, dietro il flag `SAAS_UNIFIED_LOGIN`, spento di default):** pagina unica `/saas/staff/accedi` (dietro il proxy: `associazionesemplice.it/saas/staff/accedi`), codice solo se attivo, scelta dell'area, uscita, recupero password unico (`/saas/staff/password-dimenticata`, link di un'ora, una volta sola, chiude gli accessi aperti). Con il flag acceso `/saas/admin/accesso` rinvia qui. Non c'è ancora la pagina per **attivare** il secondo livello (prossima PR): oggi lo possono avere solo le persone con il codice già impostato.
- **Fuori da questo passo, e da concordare con portale-vendita:** `/wordpress/admin/accedi` e la pagina `/accedi` del portale devono rinviare a `/saas/staff/accedi`; l'ingresso delle aree con il token (`SAAS_STAFF_SSO`, passo 3) resta spento: finché non è acceso, il pulsante «Entra» di un'area porta a un 404.

## 10. Correzione del 11/11/2026 (indirizzi e chi serve le pagine)

Decisione della titolare, che prevale su quanto sopra dove diverge:

- **Le pagine sono del portale, alla radice di `associazionesemplice.it`**: `/accedi` (accesso e recupero password in `/accedi/password-dimenticata`), `/twofactor` (il codice, solo se attivo, facoltativo, attivabile da ognuno dal profilo), `/account` (arrivo di tutti: dati, abbonamenti, collegamenti alle sole aree in cui si ha un ruolo, profilo con password, email, secondo livello, sessioni) e `/account/esci` (chiude la sessione unica, quindi tutte le aree). **Mai sotto `/saas`.** Nessuna area ha moduli propri per password, email, secondo livello o uscita: rinviano a `/account`. Dopo l'accesso la destinazione è `/account`, salvo ritorno alla pagina voluta.
- **Il SaaS è l'unico elenco di persone e risponde a richieste firmate** del portale: la specifica è in `IDENTITA_API.md`. Sotto `/saas/staff` restano solo le richieste tecniche tra le aree.
- **La pagina provvisoria `/saas/staff/accedi` (§9)** è un ponte: resta finché `/accedi` del portale non la sostituisce, poi si toglie. Non viene ampliata (niente pagina di attivazione del secondo livello nel SaaS: è del profilo in `/account`).
- Database del supporto: MySQL 8, deciso dalla sessione supporto.
