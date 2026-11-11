# Utenti staff e accessi: un account, tre aree

Specifica di progetto (decisione della titolare, 10/11/2026). **Sostituisce** il §8 di
[ARCHITETTURA_DOMINI.md](ARCHITETTURA_DOMINI.md) («Passare da un'amministrazione all'altra», che prevedeva staff separati in
ogni amministrazione e un collegamento `admin_hop`). Vale per `/wordpress/admin`, `/saas/admin` e `supporto.associazionesemplice.it`.


> **Revisione dell'11/11/2026 (decisione della titolare): l'elenco unico degli utenti vive nel database del SaaS (PostgreSQL), non nel portale.**
> Dove questo documento dice «il portale» come proprietario dell'elenco, dei ruoli, delle sessioni, dei token `staff_sso`, delle richieste `staff.*` e del registro, leggere **«il SaaS»** (schema `core`, fuori dalle regole per associazione). Il **portale** (`/wordpress/admin`, vendita, area clienti) diventa un'area come le altre: riceve il token dal SaaS, ha la sua sessione, riconferma ogni 5 minuti al SaaS e per la pagina «Utenti» chiama il SaaS. Le chiavi si invertono (il SaaS firma `staff_sso`; il portale verifica). Restano uguali livelli, regole, super amministratore (la titolare, `super_admins` in configurazione del server, mai nel repository), secondo fattore, durata delle sessioni e registro.
> **Fuori da questo elenco:** gli utenti di `wpdemo` e quelli di ogni installazione del plugin stanno nel database di WordPress del cliente (MySQL), non qui; gli operatori delle associazioni stanno nell'app. Cambia anche la sessione di sviluppo che costruisce la directory: da portale-plugin a **portale-saas**; portale-plugin passa a essere un'area che consuma (come supporto). L'indirizzo dell'accesso staff va fissato di nuovo (la pagina deve essere servita dal SaaS ma con un indirizzo del dominio principale, come `/saas/admin`).


> **Aggiornamento dell'11/11/2026 (ordine della titolare, via «Sviluppo SaaS in locale»): un solo accesso per tutti, staff e clienti.** Una pagina `/accedi`, un solo recupero password, secondo fattore in una schermata a parte solo se la persona lo ha attivato; ciascuno vede solo ciò che gli spetta. **Niente accessi separati per lo staff** (né `/wordpress/admin/accedi` né `/saas/admin/accesso` come moduli propri). Dove questo documento parla di pagina di accesso staff, di selettore o di cookie distinti, prevale il **documento unico** (login unico, tre database, ordine dei passaggi) che scrive «Sviluppo SaaS in locale» e che deve essere approvato dalla titolare prima di costruire altro. Livelli, regole, super amministratore e registro restano.
## 1. Principio

**Una persona, un account staff, ruoli per area.** Un solo elenco di utenti staff, tre aree (WordPress, SaaS, Supporto) e per
ciascuna un livello. Si entra **una volta**; da lì ci si sposta nelle aree per cui si è autorizzati senza altri accessi.

L'elenco vive nel **portale** (è già lì: `Plugin\Staff`, `Mfa`, `Totp`, l'accesso della titolare a `/wordpress/admin`) e fa da
**fornitore di identità** verso il SaaS e il supporto. Il supporto già si fida del portale per l'accesso (`SupportSso`): si
estende lo stesso schema.

## 2. Aree e livelli

| Area | Dove | Livelli |
|---|---|---|
| WordPress | `/wordpress/admin` | **admin** · **vendita** · **supporto** |
| SaaS | `/saas/admin` | **admin** · **vendita** · **supporto** |
| Supporto | `supporto.associazionesemplice.it` (staff) | **admin** · **agente** |

Cosa può fare ciascun livello (per WordPress e SaaS):

| Livello | Può | Non può |
|---|---|---|
| **admin** | tutto in quell'area: piani, prezzi, servizi, promo, abbonamenti, impostazioni, **utenti dell'area** | — |
| **vendita** | catalogo, prezzi, servizi, promo, abbonamenti e clienti (lettura e modifica commerciale) | impostazioni, utenti |
| **supporto** | vedere abbonamenti, licenze e clienti per assistere (sola lettura) | modificare piani, prezzi, promo, impostazioni, utenti |

Supporto: **admin** gestisce argomenti, guide, ticket e utenti dell'area; **agente** risponde ai ticket.

## 3. Super amministratore

- **Una sola persona è super amministratore: la titolare.** È admin di **tutte** le aree, sempre, e nessuno può toglierle o
  cambiarle il ruolo, nemmeno un altro admin.
- Il suo indirizzo sta nella configurazione del server (`super_admins` in `config.php`, **non** nel repository e **non**
  modificabile da interfaccia). Non esiste «super» assegnabile dall'interfaccia.
- **Per ora si riusa l'account che la titolare ha già creato per `/wordpress/admin`**: stessa identità per tutte e tre le
  aree, senza crearne di nuovi.
- Recupero se la titolare perde l'accesso: procedura **da server** (script riservato, come `bin/vault.php`), mai con un solo
  link per email.

## 4. Chi può dare ruoli a chi

| Chi | Può |
|---|---|
| admin di un'area | invitare chiunque per email e dare/togliere **admin, vendita o supporto** (o agente) **in quella area** |
| admin di più aree | gestire gli utenti di **tutte** le sue aree, da ognuna delle pagine «Utenti» |
| vendita, supporto, agente | non gestiscono utenti |
| titolare (super) | tutto, anche su admin di altre aree |

Regole fisse:
1. Nessuno assegna un livello **più alto del proprio**.
2. Nessuno modifica il proprio livello né si toglie da solo.
3. L'ultimo admin di un'area non si può togliere (e la titolare resta sempre).
4. «Disattiva account» toglie tutti i ruoli e chiude le sessioni **subito**.
5. Dare un ruolo **admin** o disattivare un utente chiede di riconfermare la password (e il codice a due passaggi, se la persona lo ha attivato) se l'ultima
   conferma ha più di 15 minuti.
6. Ogni invito, assegnazione, revoca, disattivazione e accesso finisce in un registro (chi, a chi, cosa, quando, indirizzo IP);
   **ogni nuovo admin e ogni accesso da un indirizzo nuovo mandano un avviso** alla titolare.
7. Gli inviti per email scadono dopo 7 giorni e si usano una volta sola.

Ogni area ha la sua pagina «Utenti», che mostra e modifica **solo i ruoli di quell'area** (chi è admin di più aree le vede tutte).
I permessi li **applica il portale** ad ogni richiesta, non la pagina: una pagina di `/saas/admin` che chiede «dai il livello
vendita a Mario» lo fa con la richiesta firmata dell'utente che la sta usando, e il portale decide.

## 5. Accesso e spostamenti senza mille login

### Entrare

- **Nessuna pagina di accesso staff a parte (11/11/2026): si entra dall accesso già esistente di ciascuna area, che verifica l elenco unico del SaaS** (prima ipotizzata: `/staff/accedi`; `/wordpress/admin` ci
  porta se non c'è sessione). Email e password, **codice a due passaggi FACOLTATIVO, spento di default: lo attiva chi vuole, ognuno per sé** (il portale ha già
  `Totp` e `Mfa`).
- Aprendo direttamente `/saas/admin` o il supporto senza sessione, si viene mandati all'accesso staff e **poi riportati** lì
  (solo verso le tre aree fisse, mai a un indirizzo preso dalla richiesta).
- Il cliente normale usa l'accesso clienti (`/account`), che resta com'è: staff e clienti non si confondono. Una persona può essere
  entrambe le cose, ma chi ha attivato il secondo fattore lo usa anche per lo staff.

### Muoversi

In alto, in ogni area, un **selettore «Vai a»**: WordPress · SaaS · Supporto. Mostra **solo** le aree in cui si ha un ruolo.
Un tocco e si è dentro, **senza altro login**:

1. il portale emette un token firmato (JWT EdDSA, stesso formato del contratto §1) `typ: "staff_sso"`, vita **60 secondi**,
   monouso (`jti`), con `sub` (email), `area` di destinazione e **livello** in quell'area, `mfa` (vero solo se la persona lo ha usato in questa sessione) e, se vero, l orario del secondo
   fattore, `sid` (identificativo della sessione centrale);
2. il browser lo manda alla destinazione con una **POST** (il token non resta nell'indirizzo né nel referer);
3. la destinazione verifica firma, `aud`, `typ`, scadenza e `jti`, apre una **sessione propria** con quel livello e la
   destinazione è fissa (la home dell'area): nessun indirizzo di ritorno libero;
4. se la persona ha attivato il secondo fattore e l ultima verifica ha più di 8 ore, lo si richiede prima di emettere il token; chi non lo ha attivato non lo vede mai.

### Uscire e revocare

- Sessione di area: inattività massima 2 ore, durata massima 8 ore.
- Ogni area **riconferma ogni 5 minuti** al portale che la sessione centrale (`sid`) è ancora valida e che il livello non è
  cambiato. Se si disattiva una persona, o le si toglie un ruolo, **entro 5 minuti** perde l'accesso ovunque.
- «Esci da tutto» nel selettore chiude la sessione centrale e quindi tutte le aree.

## 6. Dati nel portale

| Tabella | Contenuto |
|---|---|
| `staff_people` | persona: email, nome, attiva, secondo fattore (seme cifrato, codici di recupero con impronta), ultimo accesso |
| `staff_roles` | persona, area, livello, chi l'ha dato e quando (una riga per persona e area) |
| `staff_invites` | invito: email, area, livello, scadenza, usato il, chi l'ha creato |
| `staff_sessions` | `sid`, persona, creata, ultimo uso, indirizzo IP, revocata |
| `staff_audit` | registro delle azioni (non modificabile) |

**Migrazione** in due tempi (prima si aggiunge, poi si toglie): gli staff di oggi (`plugin_staff`, colonna `saas` della tabella
`staff`, `admins` di `config.php`) diventano righe di `staff_people` e `staff_roles`; gli staff locali già creati nel SaaS e nel
supporto restano finché l'accesso unico non funziona, poi si **disattiva il loro accesso locale** (resta solo, spento per
impostazione, un accesso di emergenza **della sola titolare**, da attivare dal server).

## 7. Richieste del portale (firmate, EdDSA, `kid` come nel contratto)

| Richiesta | Chi la usa |
|---|---|
| `staff.session.check` (`sid`) → valida/non valida, livello attuale | ogni area, ogni 5 minuti |
| `staff.people.list` (area) | pagina «Utenti» di un'area |
| `staff.people.invite` / `grant` / `revoke` / `deactivate` | pagina «Utenti» (con `act` = chi agisce) |

Il portale controlla che `act` sia admin dell'area interessata (o la titolare) prima di eseguire. Le richieste non portano mai
password né segreti del secondo fattore.

## 8. Cosa può andare storto (e come lo limitiamo)

Un solo elenco di utenti significa un **punto unico di rischio**: un account staff rubato con più ruoli apre più aree. Perciò:
secondo fattore facoltativo (consigliato agli admin, mai imposto), riconferma della password per le azioni che danno privilegi, sessioni corte e riconferma ogni 5 minuti, avvisi alla
titolare su ogni nuovo admin e su accessi da indirizzi nuovi, limite ai tentativi, accesso di emergenza solo da server. Se il
portale è irraggiungibile le aree **non** si aprono (nessuna sessione nuova), tranne l'accesso di emergenza della titolare.

## 9. Chi realizza cosa

| Sessione | Compito |
|---|---|
| **portale-plugin** | la directory centrale (tabelle, migrazione di `Plugin\Staff` e dello staff SaaS), accesso staff unico con secondo fattore, selettore «Vai a», emissione dei token `staff_sso`, `staff.session.check`, pagina «Utenti» di `/wordpress/admin`, registro e avvisi |
| **portale-saas** | accesso a `/saas/admin` solo con il token del portale, sessione locale con riconferma ogni 5 minuti, pagina «Utenti» che chiama il portale, spegnimento dell'accesso locale dello staff, livelli admin/vendita/supporto nel codice di `Piattaforma` |
| **sessione supporto** | accesso staff dal token (admin→admin, agente→agente), pagina «Utenti», il login clienti con `SupportSso` resta com'è |
| **portale-vendita** | nulla da costruire: fissare l'indirizzo di accesso staff e il selettore nei layout del portale insieme a portale-plugin; l'accesso clienti non cambia |

Ordine: 1) directory centrale e migrazione della titolare, 2) token e accesso al SaaS, 3) supporto, 4) pagine «Utenti»,
5) riconferma periodica, registro e avvisi, 6) spegnimento degli accessi locali.

## 10. Confermato (10/11/2026)

La titolare ha confermato tutti e tre i punti:

1. Nomi dei livelli: **admin / vendita / supporto** (WordPress e SaaS) e **admin / agente** (Supporto).
2. ~~Indirizzo dell accesso staff `associazionesemplice.it/staff/accedi`~~ **Superato (11/11/2026): non serve una pagina di accesso a parte.** Gli staff hanno già i loro account: si entra dalla pagina di accesso già esistente di un area (`/wordpress/admin`, `/saas/admin`, supporto) e da lì il selettore «Vai a» porta alle altre senza altro accesso. L autenticazione usa l elenco unico nel database del SaaS.
3. Portale irraggiungibile: nessuno entra nelle aree finché non torna, salvo l'accesso di emergenza della sola titolare dal server.

## Invito dello staff: com'è realizzato (11/11/2026)

- Tabella `core.staff_invites` (email, nome, ruoli per area, scadenza a 7 giorni, usato il, chi l'ha creato); del codice si conserva solo l'impronta SHA-256, mai il codice.
- `php artisan saas:staff:invite <email> <nome> --role=saas:admin --role=wordpress:admin`: crea l'invito e manda per email il link; il link non si stampa (solo con `--show-link`, per i casi di emergenza).
- Il link porta a `/saas/staff/invito/<codice>`: pagina senza sessione né cookie (il codice è il segreto), la persona sceglie da sé la password (almeno 12 caratteri). Si usa una volta sola, anche con due invii in contemporanea (riga bloccata). Nessuno vede mai la password.
- `SAAS_STAFF_INVITE_BASE` cambia la base del link (dietro il proxy del portale: `https://associazionesemplice.it/saas`).
- Ancora da fare: la pagina «Utenti» (invitare dal browser) e la riconferma della password per le azioni con privilegi.
