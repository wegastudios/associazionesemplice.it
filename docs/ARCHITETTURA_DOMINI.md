# Architettura dei domini: vendita, prodotti, supporto, demo

Specifica di progetto (stato: **principio confermato dalla titolare il 10/11/2026**; restano aperte le decisioni 2–5 del §11). Mette ordine tra portale, plugin e SaaS. Chi la realizza:
la sessione Portale e pagamenti per `portale/`, la sessione SaaS per `saas/`, la sessione plugin per `wordpress/`, la
sessione supporto per `supporto/`. Questo documento non cambia codice: dice dove vive cosa e come i pezzi si parlano.

## 1. Principio

> **Il portale vende. I prodotti decidono.**
> Piani, caratteristiche, limiti, prezzi e promozioni di un prodotto si definiscono **solo nell'amministrazione di quel
> prodotto**. Il portale li **legge**, li mostra, incassa e dice al prodotto «questo cliente ha comprato questo piano».
> Il SaaS e il plugin hanno ciascuno la sua amministrazione, i suoi utenti staff e i suoi dati, senza condividere tabelle.

Cosa cambia rispetto a oggi: ora il listino e i diritti (pacchetti, limiti) sono nel portale, che li spedisce al SaaS
([CONTRATTO_PORTALE.md](../saas/docs/CONTRATTO_PORTALE.md) §2.2 e §2.3, «il portale è l'unica fonte»). Questa proposta
**rovescia** quella scelta: la fonte dei piani torna al prodotto. Il contratto va riscritto in v2 (§6).

## 2. Mappa degli indirizzi

| Indirizzo | A cosa serve | Chi lo realizza |
|---|---|---|
| `associazionesemplice.it` | **Vendita di tutto**: home, listino, confronto, acquisto, area clienti (abbonamenti, fatture, licenze), partner | sessione Portale |
| `associazionesemplice.it/account` | **area riservata unica dei clienti**: tutti gli abbonamenti (SaaS e plugin), fatture, pagamenti, download del plugin, upgrade, downgrade, cambio dominio (§5 bis) | sessione Portale |
| `associazionesemplice.it/saas` | approfondimenti sul SaaS (cosa fa, per chi, novità, domande frequenti) | Portale (contenuti) |
| `associazionesemplice.it/saas/admin` | **controllo commerciale del SaaS**: abbonamenti, piani, prezzi, servizi (addon), promo per la vendita e impostazioni. **Non** gestisce le associazioni né i loro utenti: quello sta nell'app | SaaS |
| `app.associazionesemplice.it` | **l'app** (gestionale delle associazioni). Nessuna pagina di vendita, nessun'amministrazione di piattaforma | SaaS |
| `{slug}.associazionesemplice.it` | sito e area soci della singola associazione | SaaS |
| `associazionesemplice.it/wordpress` | approfondimenti sul plugin: guide di installazione, **changelog**, requisiti | Portale (contenuti) |
| `associazionesemplice.it/wordpress/admin` | **controllo commerciale del plugin**: piani e licenze, prezzi, servizi, promo, versioni, clienti con licenza e le impostazioni del plugin (tutte nello stesso posto) | Plugin (il codice sta in `portale/`, in uno spazio separato: vedi §3.2) |
| `supporto.associazionesemplice.it` | **un solo supporto** per SaaS e plugin | sessione supporto |
| `associazionesemplice.it/demo` | demo del plugin (WordPress vero) — **resta dov'è** | demo |
| `demoapp.associazionesemplice.it` | **demo del SaaS** con dati finti, azzerata ogni notte | SaaS |

Regole sugli indirizzi:
- Le pagine di vendita non stanno mai su `app.`; l'app non ha pagine pubbliche tranne il modulo d'adesione e l'area soci
  dell'associazione.
- `/saas`, `/wordpress` e le due `/admin` sono sezioni **editoriali o di controllo**, non negozi: il carrello è uno solo
  (portale, `/acquista`).
- Vecchi indirizzi da mantenere con redirect 301 (e da ricontrollare): `/admin` → `/wordpress/admin`; l'attuale gestione
  SaaS di `/saas` → `/saas/admin`; l'attuale `/saas/accedi?t=…` («Gestisci abbonamento» dal gestionale) **cambia nome**,
  perché `/saas` torna a essere una sezione di contenuti: proposta `/account/da-gestionale` (il contratto §3.2 si aggiorna).

## 3. Chi possiede cosa

### 3.1 Tabella delle responsabilità

| Dato o funzione | Proprietario | Gli altri come lo vedono |
|---|---|---|
| Piani, pacchetti, caratteristiche, limiti del SaaS | **SaaS** (`/saas/admin`) | portale: catalogo di sola lettura |
| Prezzi e promozioni del SaaS | **SaaS** | portale: catalogo di sola lettura |
| Piani, licenze, prezzi, promo del plugin | **Plugin** (`/wordpress/admin`) | portale: catalogo di sola lettura |
| Versioni e changelog del plugin | **Plugin** | portale: pagina `/wordpress/changelog` letta dal catalogo versioni |
| Clienti, indirizzi di fatturazione, abbonamenti, pagamenti, fatture, partner e commissioni | **Portale** | i prodotti ricevono solo «chi ha comprato cosa» |
| Associazioni, operatori, soci, dati | **SaaS** | il portale riceve solo numeri (consumi) |
| Siti con licenza, attivazioni | **Plugin** (server licenze) | portale: elenco nell'area clienti |
| Ticket, articoli, priorità | **Supporto** | prodotti e portale: solo il collegamento |
| Testo di vendita, pagine `/saas` e `/wordpress`, confronto | **Portale** (contenuti) | legge i dati veri (prezzi, limiti) dal catalogo |

Conseguenza pratica: chi cambia un prezzo lo cambia in un posto solo, il pannello del prodotto, e il portale lo mostra
dopo al massimo 10 minuti (§4.3).

### 3.2 Il plugin non ha un server suo: come si separa

Il plugin è codice dentro WordPress del cliente; il suo «lato server» (licenze, prezzi, versioni) è oggi nel portale
(`portale/src`, `portale/views/admin`). Si separa **per confine logico**, senza spostare il server:

- il codice resta nella stessa applicazione, ma in uno spazio proprio (`portale/src/Plugin/…`, vista `views/wordpress-admin`) con
  **tabelle, ruoli staff, menu e rotte propri** sotto `/wordpress/admin`;
- il resto del portale (vendita, area clienti) parla con quello spazio **solo** attraverso un'interfaccia `Catalog` (§4.1),
  proprio come parlerebbe con il SaaS. Nessuna query diretta alle tabelle dei piani del plugin da parte del negozio;
- così, quando il plugin avrà un backend vero, si sposta senza toccare la vendita.

### 3.3 Cosa gestisce `/saas/admin` e dove gira il codice

**Decisione della titolare (10/11/2026):** `/saas/admin` è la parte **di vendita**: abbonamenti, prezzi, servizi, promo e le
impostazioni commerciali. Tutto il resto (associazioni, operatori, soci, dati, disattivazione di utenti) sta **nell'app**, non
qui. Per il plugin vale lo stesso: `/wordpress/admin` contiene piani e licenze, prezzi, servizi, promo e le impostazioni.

`/saas/admin` è un indirizzo del dominio principale, ma l'amministrazione del SaaS è **codice di `saas/`** (modulo nuovo
`Piattaforma`, con staff e menu propri, non quelli degli operatori delle associazioni). Servire Laravel sotto un percorso
del dominio principale dipende da cosa permette il server. **Prima prova di fattibilità**, in quest'ordine di preferenza:

1. reindirizzamento interno (proxy) da `associazionesemplice.it/saas/admin` all'app: l'indirizzo voluto resta;
2. sottocartella del dominio principale con la stessa applicazione e `APP_URL`/asset con percorso;
3. se nessuno dei due regge: l'amministrazione sta su `app.associazionesemplice.it/admin` e `/saas/admin` fa un redirect
   permanente; cambia solo l'indirizzo che si vede, non il progetto.

Il cookie di sessione dell'amministrazione ha il proprio nome e percorso: **non** condiviso né con il portale né con la
sessione degli operatori (REGOLE_DI_LAVORO, cookie solo per il suo indirizzo).

## 4. Il catalogo: come il portale legge piani e prezzi

### 4.1 Un formato unico

Ogni prodotto espone lo stesso documento (versionato `catalogo/v1`), così il portale ha **un solo** modo di mostrarlo:

```
GET https://app.associazionesemplice.it/api/catalogo/v1        (SaaS)
GET https://associazionesemplice.it/wordpress/api/catalogo/v1  (plugin: interfaccia interna, stesso formato)
```

Pubblico, senza dati personali, cacheabile; risposta firmata (`ETag` e firma EdDSA del prodotto) così il portale sa che non
è stato alterato. Contenuto:

| Parte | Cosa |
|---|---|
| `product` | `saas` o `plugin`, nome, descrizione breve |
| `rev` | numero crescente: cambia a ogni modifica del catalogo |
| `plans[]` | `code` stabile (`procontabile`, `profiscale`, …), nome lungo (da pannello), **versione del piano** (`plan_version`, immutabile), a chi è rivolto, ordine, visibile sì/no |
| `features[]` | elenco delle caratteristiche con `id` stabile e testo; per ogni piano: `incluso` / `limite` / `non incluso`. Da qui il portale costruisce da solo la **tabella di confronto** |
| `prices[]` | per piano: periodicità, importo in centesimi, IVA, valuta; più le regole di variazione (fasce di soci, operatori aggiuntivi, ricariche) |
| `promos[]` | promozioni **attive**: codice (o automatica), cosa sconta, su quali piani, dal-al, limite d'uso, cumulabilità |
| `support_level` | per piano: livello di assistenza (`free`, `paid`, `top`), per il supporto (§7) |
| `limits` | limiti numerici leggibili dal prodotto stesso, usati poi per i diritti (§5) |

Regole:
- **Versione del piano immutabile**: una modifica crea una `plan_version` nuova. Gli abbonamenti già venduti restano sulla
  versione comprata finché il cliente non cambia piano (nessun prezzo o limite cambia di nascosto sotto un cliente).
- Importi sempre in centesimi interi. Testi senza HTML.
- Il portale **non** mostra mai un prezzo che non viene dal catalogo; se il catalogo non risponde usa l'ultimo salvato e lo
  segna «aggiornato il …» nel pannello staff; **per acquistare** serve il catalogo corrente (§4.3).

### 4.2 Cosa decide ogni amministrazione (editor del catalogo)

Nelle due `/admin` c'è la pagina «Piani e prezzi» con lo stesso schema:
- elenco dei piani; per ciascuno nome, descrizione, caratteristiche, limiti, prezzi, livello di assistenza, visibile;
- **bozza e pubblicazione**: le modifiche non sono visibili finché non si preme «Pubblica» (crea nuova `rev`); anteprima
  di come lo vedrà il portale; elenco delle modifiche nel registro con chi le ha fatte;
- promozioni: codice, sconto (percentuale o importo), piani validi, date, limite d'uso, stato; la promo è del prodotto,
  vale solo per i suoi piani;
- validazioni che impediscono errori costosi: prezzo ≥ 0, piano senza caratteristiche non pubblicabile, promo con date
  incoerenti, ultimo piano visibile non eliminabile.

### 4.3 Lettura e acquisto

- Il portale scarica il catalogo ogni 10 minuti e quando riceve un avviso (`POST` firmato `catalogo.changed` dal prodotto
  alla pubblicazione), poi lo salva.
- **Pagine di vendita:** dal catalogo salvato.
- **Acquisto:** all'apertura del carrello il portale chiede la `rev` corrente; il prezzo e la `plan_version` entrano
  nell'ordine come istantanea. Se la `rev` è cambiata mentre il cliente decide, il portale lo dice («i prezzi sono
  stati aggiornati») e ricalcola prima di incassare.
- **Promo:** il cliente scrive il codice; il portale chiede al prodotto «vale?» (`POST` firmato `promo.check`) e riceve
  sconto e scadenza; l'uso si conferma al pagamento riuscito (`promo.redeem`, una volta sola per ordine).

## 5. Dal portale al prodotto: «ha comprato questo»

Oggi il portale manda al SaaS i **diritti** calcolati (pacchetti, limiti). Nel nuovo modello manda solo il **fatto
commerciale**, e il prodotto ricava da sé i diritti dalla sua `plan_version`:

```
PUT /api/portale/v2/orgs/{org_id}/subscription    (SaaS)
{ "ref":"sub_123", "rev":7, "status":"active", "until":"2026-12-31", "trial_ends":null,
  "plan":"procontabile", "plan_version":3,
  "addons":[{"code":"operatori_extra","qty":2}],
  "promo":"PRIMAVERA", "price_cents":29000, "partner":"STUDIO-ROSSI" }
```

- `status`, `until`, `trial_ends`, `ref`, `rev`, `partner`, ritenti e regole di sospensione: **invariati** rispetto alla v1.
- I limiti (soci, operatori, spazio, risorse…) li calcola il SaaS dal piano e dagli addon; il portale non li conosce se non
  per mostrarli dal catalogo.
- Se il SaaS non conosce la `plan_version`, risponde `409 unknown_plan` e **non applica nulla**.
- Il plugin riceve lo stesso tipo di messaggio per le licenze (`plan`, `plan_version`, `until`, siti ammessi).
- I consumi (SaaS → portale, §3.1 del contratto) restano: servono al portale per fatturare fasce e addon, con le regole
  prese dal catalogo.
- **Cade** il §2.3 del contratto v1 (amministrazione della piattaforma fatta dal portale con richieste firmate, SaaS senza
  interfaccia): l'amministrazione ora è del SaaS (§3.3 e §8). Restano gli endpoint di sola lettura che al portale servono per l'area
  clienti (stato dell'associazione, consumi).

## 5 bis. Area riservata unica dei clienti

Un cliente ha **un solo accesso** (portale, `/account`) per tutto ciò che ha comprato, qualunque prodotto. Non esistono aree
clienti separate per SaaS e plugin. L'area è del portale; **le operazioni sul prodotto le esegue il prodotto**, il portale le
chiede e incassa.

### Pagine

| Pagina | Contenuto |
|---|---|
| **Panoramica** | una carta per abbonamento: prodotto, piano, stato (attivo, in prova, pagamento non riuscito, in scadenza), prossimo rinnovo e importo; avvisi da fare («Pagamento da controllare», «Licenza in scadenza tra 12 giorni») |
| **Abbonamento** (una pagina per ciascuno) | piano e addon, consumi (soci, operatori, spazio) dai consumi del SaaS, rinnovo, **cambia piano**, **disdici**, storico delle modifiche |
| **Plugin** | licenze: chiave (mascherata, con «Copia»), **siti attivi e domini**, **cambia dominio**, **scarica l'ultima versione** e le precedenti, link al changelog di `/wordpress`; per il piano gratuito il rimando a WordPress.org |
| **SaaS** | associazioni: «Apri il gestionale» (collegamento a `app.`), dominio personalizzato (stato: in verifica, attivo, errore con istruzioni), consumi e limiti |
| **Fatturazione** | dati fiscali, metodi di pagamento, fatture e ricevute da scaricare, ponte SDI |
| **Persone** | altri utenti dell'account con un ruolo: intestatario (tutto), amministrativo (fatture e pagamenti), tecnico (domini, download, licenze); nessuno vede i dati dei soci |
| **Assistenza** | accesso al supporto (collegamento unico, §7), ticket aperti |

Su telefono: stessi principi di [DESIGN.md](../saas/docs/DESIGN.md) (elenchi, fogli dal basso, un'azione principale per schermata).

### Cambio di piano (upgrade e downgrade)

1. Il cliente sceglie il nuovo piano **dal catalogo** (mai da un elenco proprio del portale).
2. Il portale chiede al prodotto: `plan_change.check` con `ref` e `to_plan` → `{allowed, blockers[], effects[]}`. Il prodotto
   dice se si può e perché no, in italiano chiaro («Hai 180 soci e il piano ne ammette 150»).
3. **Upgrade:** subito; il portale calcola il conguaglio (proporzionale ai giorni residui), incassa, poi manda `subscription`
   (§5) con la nuova `plan_version`.
4. **Downgrade:** **alla scadenza del periodo pagato**, non subito; è messo in programma e visibile («Dal 01/01 passi a
   Pro Contabile») e annullabile finché non scatta. Il prodotto lo blocca se i dati superano i limiti del piano più basso
   (nessun dato si cancella mai; il cliente deve prima rientrare nei limiti).
5. Ogni cambio finisce nel registro del portale e in quello del prodotto.

### Dominio

- **Plugin:** la licenza è legata a uno o più domini. Il cambio lo valida il server licenze (modulo plugin): dominio
  raggiungibile e non già usato da un'altra licenza. **Regola già in vigore nel portale** (`Billing::domainUnlockAt`,
  impostazione `domain_lock_days`): il cambio è libero, poi la chiave resta legata al nuovo dominio per N giorni; il personale
  può sempre cambiarlo a mano. Il valore predefinito nel codice è **180 giorni**; la titolare ricorda 120: il valore vero
  si legge e si imposta nelle impostazioni di `/wordpress/admin` («Blocco del dominio dopo un cambio»). Il numero N non è nel
  catalogo, è un'impostazione. Il cliente vede l'esito subito; il vecchio dominio si disattiva alla prima verifica del plugin.
- **SaaS, dominio personalizzato** (pacchetto): il cliente scrive il dominio, il SaaS verifica il DNS e attiva il
  certificato; lo stato torna nella pagina. L'indirizzo `slug.associazionesemplice.it` **non** si cambia da qui (resta stabile):
  eventuali eccezioni passano dal supporto.

### Download del plugin

Solo per licenza Pro attiva; il collegamento è **firmato, a vita breve (5 minuti) e legato alla licenza**; l'archivio lo serve il modulo plugin.
Elenco versioni dal catalogo versioni, con data e link al changelog. Licenza scaduta: si scarica ancora l'ultima versione rilasciata
**prima** della scadenza, non le successive.

### Sicurezza

- Operazioni che toccano soldi o accesso (cambia piano, disdici, cambia dominio, cambia metodo di pagamento, aggiungi una
  persona) chiedono di nuovo la password o il codice a due passaggi se l'ultima conferma ha più di 15 minuti.
- Ogni cliente vede solo i **propri** abbonamenti; ogni richiesta ricontrolla di chi è l'abbonamento (`ref`).
- Disdetta: a fine periodo, mai immediata; per il SaaS la pagina ricorda l'esportazione completa e per quanto tempo restano i dati.

### Cosa serve al contratto v2

Oltre a catalogo e `subscription` (§4–5), il prodotto espone al portale, con richieste firmate: `plan_change.check`,
`domain.check` e `domain.set`, `license.sites` (elenco siti attivi), `download.link` (collegamento firmato). Tutte monouso
nel `jti` come il resto del contratto.

## 6. Impatto sul contratto

[CONTRATTO_PORTALE.md](../saas/docs/CONTRATTO_PORTALE.md) passa a **v2**:

| Parte della v1 | Cosa succede |
|---|---|
| §1 Token e chiavi | invariati |
| §2.1 Creazione dell'associazione | invariata |
| §2.2 Diritti (pacchetti, limiti spediti dal portale) | **sostituita** da §5 di questo documento |
| §2.3 Amministrazione dal portale | **cancellata** (non ancora sviluppata; era «proposta») |
| §3.1 Consumi | invariata |
| §3.2 Accesso «Gestisci abbonamento» | solo cambio di indirizzo di arrivo (§2) |
| Nuovo | §4 catalogo, `catalogo.changed`, `promo.check`, `promo.redeem` |

Finché il portale non ha sviluppato §2.2 e §2.3 della v1, il costo del rovesciamento è basso; **fermare la sessione Portale
su quelle due parti** finché il contratto v2 non è scritto.

## 7. Un supporto solo

`supporto.associazionesemplice.it` serve SaaS e plugin. Cosa si prepara **subito** (poco costo) e cosa dopo.

**Subito**
- Ogni ticket ha un campo **prodotto** (SaaS / plugin / fatturazione e account) e un campo **piano** (copiato dall'abbonamento
  del cliente al momento dell'apertura, con il suo `support_level`). Anche se ora non cambia nulla, i dati servono dopo.
- Argomenti organizzati per prodotto, con una sezione «Per tutti» (accesso, fatture, abbonamento). Il cliente sceglie
  il prodotto prima dell'argomento; gli articoli suggeriti sono quelli del prodotto.
- L'accesso unico dal portale (oggi `SupportSso`, HMAC) porta già il ruolo; si aggiunge l'elenco `plans: [{product, plan,
  support_level, status}]` (nessun dato di soci) così il supporto sa chi ha cosa senza un'altra chiamata.
- Le guide restano allineate a ogni rilascio, come già stabilito.

**Livelli di assistenza** (decisi dalla titolare il 10/11/2026; il campo `support_level` del catalogo ha questi tre valori):

| Livello (codice) | Chi | Prima risposta entro | Giorni coperti | Priorità |
|---|---|---|---|---|
| `free` | **piani gratuiti** (la «base») | 48 ore | lunedì–venerdì | normale |
| `paid` (assistenza **Standard**) | piani a pagamento con assistenza standard | 48 ore | lunedì–sabato | prioritaria rispetto al gratuito |
| `top` (assistenza **Top**) | piani a pagamento con assistenza top | 24 ore | lunedì–sabato | prioritaria, in cima alla coda |

- Il tempo è di **prima risposta**, non di risoluzione, ed è un obiettivo di servizio, non una promessa contrattuale.
- Le ore si contano **solo nei giorni coperti** (un ticket del venerdì sera per `free` ha risposta entro mercoledì: ore dei giorni
  di servizio); le festività nazionali non contano. *(Da confermare questa lettura.)*
- **Scelta per piano:** chi crea un piano a pagamento nell'editor del catalogo (`/saas/admin`, `/wordpress/admin`) sceglie se ha
  assistenza Standard o Top (campo `support_level` obbligatorio); i piani gratuiti sono sempre `free`. Oggi, per plugin e SaaS: il
  piano a pagamento più basso è Standard, l'altro è Top. I nomi da mostrare ai clienti: «Gratuita», «Standard», «Top».
- Il supporto mostra allo staff, ordinata per livello e per tempo residuo, la coda con la scadenza della prima risposta, e al
  cliente l'etichetta («Assistenza prioritaria» per `paid` e `top`).
- Un cliente con più prodotti prende il livello più alto tra quelli attivi **del prodotto del ticket**.
- Licenza o abbonamento sospesi o scaduti: livello `free`.

## 8. Passare da un'amministrazione all'altra

Chi ha un ruolo sia in `/wordpress/admin` sia in `/saas/admin` vede in **entrambe** un collegamento «Passa a …» in
alto, **solo se ha davvero il ruolo nell'altra**; chi non lo ha non vede nulla.

Ogni amministrazione ha i **suoi** utenti staff e il suo accesso (email e password o link, **verifica in due passaggi
obbligatoria** per chiunque abbia un ruolo). Il passaggio non è una sessione condivisa: è un'**attestazione monouso**:

1. l'amministrazione di partenza genera un token JWT EdDSA (stesso formato del contratto §1) `typ: "admin_hop"`, vita
   **60 secondi**, `jti` monouso, `sub` = email, `mfa: true` (la persona ha già fatto i due passaggi), `from` e `aud` =
   destinazione;
2. il browser va a una pagina intermedia che lo invia con una richiesta **POST** (il token non resta nell'indirizzo, nella
   cronologia né nel referer);
3. l'amministrazione di arrivo verifica firma, `aud`, `typ`, scadenza e `jti`; poi **cerca l'email nei propri utenti staff**:
   il ruolo non viene mai dal token. Se non c'è, nega senza dire perché;
4. se `mfa` non è `true` o l'ultima verifica ha più di 8 ore, chiede il codice a due passaggi invece di aprire;
5. apre una **sessione propria** con il livello (amministratore, solo lettura) che ha lì; la destinazione è fissa
   (la home dell'altra amministrazione), niente indirizzi di ritorno presi dalla richiesta;
6. registra l'evento in entrambi i registri (`admin.hop.out` / `admin.hop.in`) con indirizzo IP e `jti`.

Chiavi: una coppia Ed25519 per ciascuna amministrazione (non quella delle licenze né quella del contratto), parte
pubblica nell'altra. Rotazione come nel contratto.

## 9. Demo del SaaS: `demoapp.associazionesemplice.it`

Un'istanza separata del SaaS (ambiente «demo», non lo staging, non la produzione), con database proprio e **solo dati finti**.

| Aspetto | Decisione |
|---|---|
| Dati | un'associazione di fantasia («Polisportiva Demo») con soci, tessere, attività, risorse, prima nota, domande aperte, avvisi: abbastanza per vedere ogni schermata piena. Nomi, codici fiscali e IBAN fittizi e riconoscibilmente falsi |
| Azzeramento | ogni notte (comando unico, p.es. `saas:demo:reset`, alle 03:30): cancella e ricrea tutto; in testa all'app striscia «Demo: i dati si cancellano ogni notte» |
| Accessi | utenti di prova **pubblici** per ruolo (titolare, segreteria, commercialista, socio), come nella demo del plugin; nessun amministratore di piattaforma, nessuna registrazione |
| Diritti | tutti i pacchetti attivi, nessun limite fastidioso; nessun collegamento col portale (nessuna chiamata di consumi o diritti) |
| Sicurezza ridotta solo qui | verifica in due passaggi spenta (`SAAS_REQUIRE_TWO_FACTOR=false`), password pubbliche; per questo nessun dato vero, mai |
| Posta | mai inviata: solo log. Gli inviti e i codici d'accesso compaiono in una pagina «posta di prova» |
| Pagamenti | simulati (nessuna chiave Stripe/PayPal): il pulsante «Paga» mostra l'esito finto |
| File e import | caricamenti ammessi ma piccoli (2 MB) e cancellati con l'azzeramento notturno |
| Limiti | frequenza ridotta per indirizzo IP, 50 richieste al minuto, ricerche e import con limiti più bassi |
| Visibilità | `noindex`, nessun cookie di tracciamento, collegato dalle pagine `/saas` del portale con il pulsante «Prova la demo» |
| Area soci | sottodominio `polisportiva-demo.demoapp.associazionesemplice.it` (jolly a parte, `SAAS_MEMBERS_DOMAIN=demoapp.associazionesemplice.it`) |
| Aggiornamento | a ogni rilascio del SaaS, come per la demo del plugin: stesso ambiente, nuovi dati (regola «demo sempre aggiornata») |
| Cosa non si fa | niente `reset` a metà giornata senza chiedere; niente dati importati da associazioni vere |

La demo del plugin resta in `associazionesemplice.it/demo`.

## 10. Piano di lavoro

| # | Passo | Chi | Dipende da |
|---|---|---|---|
| 0 | Confermare questo documento; **fermare** lo sviluppo di §2.2 e §2.3 della v1 nel portale | titolare | — |
| 1 | Indirizzi: nuovo schema, redirect 301, `/saas` e `/wordpress` come sezioni di contenuti, cambio di `/saas/accedi` | Portale | 0 |
| 2 | Contratto v2 scritto (catalogo, `subscription`, promo) | SaaS + Portale | 0 |
| 3 | Catalogo del SaaS: formato `catalogo/v1` da un file di configurazione iniziale; il portale lo legge e mostra listino e confronto | SaaS, Portale | 2 |
| 4 | Catalogo del plugin dietro l'interfaccia `Catalog`; confine logico `portale/src/Plugin` e `/wordpress/admin` | Portale (spazio plugin) | 2 |
| 5 | Amministrazione commerciale del SaaS `/saas/admin`: staff, 2FA, editor del catalogo con bozza/pubblicazione, abbonamenti, servizi, promo, impostazioni (niente gestione di associazioni e utenti: sta nell'app) | SaaS | 3 + prova di fattibilità §3.3 |
| 6 | Acquisto con istantanea del prezzo e `subscription` al posto dei diritti; promo | Portale, SaaS | 3, 5 |
| 6 bis | Area clienti unica (§5 bis): panoramica, pagina per abbonamento, cambio piano con `plan_change.check`, domini, download firmato | Portale (+ SaaS e plugin per le richieste) | 6 |
| 7 | Passaggio tra le due amministrazioni (§8) | Portale e SaaS | 4, 5 |
| 8 | Supporto: campi prodotto/piano, argomenti per prodotto, `plans` nell'accesso unico | Supporto, Portale | 3 |
| 9 | Demo del SaaS (§9) | SaaS | 5 (per i pacchetti) |
| 10 | Priorità di assistenza per piano: tre livelli `free`/`paid`/`top` con tempi di §7, coda ordinata per scadenza | Supporto | piani a pagamento attivi |

I passi 1, 2 e 8 si possono iniziare subito e in parallelo; il resto segue.

## 11. Decisioni

Prese dalla titolare il 10/11/2026:

1. **Piani nei prodotti, il portale legge** (§1). Si fermano §2.2 e §2.3 del contratto v1.
2. **`/saas/admin` è la parte di vendita** (abbonamenti, prezzi, servizi, promo, impostazioni); le associazioni e i loro utenti
   si gestiscono nell'app (§3.3). Resta da fare la prova di fattibilità sull'indirizzo sotto il dominio principale; se non
   regge, `app.associazionesemplice.it/admin` con redirect.
3. **Piani del plugin** in `/wordpress/admin` insieme alle altre impostazioni, nello stesso codice del portale ma separati (§3.2).
4. **Niente offerte combinate** tra plugin e SaaS: chi prende il plugin non prende il SaaS. Le promo sono sempre di un prodotto.
5. **Upgrade subito, downgrade e disdetta a fine periodo**, downgrade bloccato se i dati superano i limiti (§5 bis).
6. **Cambio dominio del plugin**: regola già in vigore (cambio libero, poi blocco di N giorni, `domain_lock_days`, impostazione).
   Il codice ha 180 come valore predefinito; la titolare ricorda 120: da verificare nelle impostazioni del pannello (§5 bis).
7. **Livelli di assistenza** `free` (48 ore, lun–ven), `paid` (48 ore, lun–sab, prioritario), `top` (24 ore, lun–sab, prioritario) (§7).

Deciso anche: lancio del catalogo (10/11/2026) e assistenza per piano scelta alla creazione (§7).

Ancora da fare:
- Prova di fattibilità dell'indirizzo `/saas/admin` (sessione portale-saas).
- Conferma della lettura dei tempi di assistenza (§7: ore contate solo nei giorni coperti, festivi esclusi).
- Valore giusto di `domain_lock_days` sul server (120 o 180).
