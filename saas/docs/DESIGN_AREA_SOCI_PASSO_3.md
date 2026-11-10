# Design, area soci passo 3: app installabile, avvisi e tessera nel wallet

Specifica di progetto per la sessione di sviluppo. Contesto: [SVILUPPO.md](SVILUPPO.md) §9 quinquies (area soci passi 1 e 2),
[DESIGN.md](DESIGN.md) (principi e componenti). Pagine coinvolte: `layouts/MemberLayout.vue`, `AreaSoci/Tessera.vue`,
`AreaSoci/Profilo.vue`, `AreaSoci/Accesso.vue`.

Tre parti indipendenti, da rilasciare in quest'ordine: **3a** app installabile, **3b** avvisi sul telefono, **3c** tessera
nel wallet. La 3c richiede decisioni e costi del titolare del progetto (§5).

## 0. Cosa sa già l'area soci (vincoli da rispettare)

- Ogni associazione ha il suo sottodominio: `{slug}.<dominio>/area`. Ogni sottodominio è quindi **un'app diversa** con il
  proprio nome e la propria installazione.
- Cookie `as_socio` con path `/area`, 30 giorni senza uso o 90 dall'accesso. Il socio entra con link o codice a 6 cifre
  ricevuti per email, o con un link di WhatsApp della segreteria (7 giorni).
- La Content-Security-Policy è `default-src 'self'`, nessuno script esterno: service worker, manifest e icone devono
  essere serviti dallo stesso sottodominio.
- Le pagine con dati personali oggi non vanno in cache: ogni eccezione è scritta qui sotto ed è l'unica.
- Icone già presenti: `public/assets/brand/icona-192.png`, `icona-512.png`, `apple-touch-icon.png`.

## 1. Parte 3a: app installabile (PWA)

### 1.1 Obiettivo

Il socio tocca l'icona sul telefono e arriva alla **tessera**, a schermo intero, senza barra del browser. Se non c'è
campo (palestra, campo sportivo, cantina) vede comunque l'ultima tessera salvata.

### 1.2 Manifest (uno per associazione)

Servito da `/area/manifest.webmanifest`, generato dal server dall'associazione del sottodominio.

| Campo | Valore |
|---|---|
| `name` / `short_name` | nome dell'associazione / lo stesso tagliato a 12 caratteri sulla parola intera |
| `start_url` | `/area?da=app` (il parametro serve solo a distinguere le aperture dall'icona) |
| `scope` | `/area` |
| `display` | `standalone` |
| `theme_color` / `background_color` | indaco `#1F218C` / carta `#F5F6FA` |
| `lang` | `it` |
| `icons` | 192, 512 e una **maschera** 512 (simbolo bianco su indaco, margine di sicurezza 20 %): da disegnare |
| `shortcuts` | Tessera, Eventi, Quote (tasto lungo sull'icona su Android) |

Nell'intestazione di ogni pagina dell'area: `<link rel="manifest">`, `theme-color`, `apple-touch-icon`,
`apple-mobile-web-app-title`. Controllare che il `viewport` abbia `viewport-fit=cover` (la barra in basso già usa i margini sicuri).

### 1.3 Service worker

Servito da `/area/sw.js` (deve stare dentro lo scope), `Cache-Control: no-cache`, versione legata all'hash della build.

| Cosa | Strategia |
|---|---|
| CSS, JavaScript, caratteri, icone della build | in cache all'installazione; si aggiorna alla nuova versione |
| Pagine dell'area | **sempre di rete**, mai in cache |
| **Tessera** (`/area`, solo schermata del socio con QR) | **unica eccezione**: dopo ogni apertura riuscita si salva una copia; senza rete si mostra la copia |
| Tutto il resto senza rete | pagina «Sei senza connessione» |

La copia offline della tessera:
- non mostra mai «In regola» come se fosse aggiornato: al posto del badge compare «Salvata il 14/11 alle 18:32. Collegati
  per controllare se è ancora in regola.» (giallo, con la parola, non solo colore); il QR resta visibile;
- contiene solo ciò che c'è già sulla schermata della tessera (nome, numero, QR): **mai** certificati, indirizzo, importi;
- si cancella con «Esci», con «Esci da tutti i dispositivi» e quando il server risponde 401;
- per i profili dei minori vale la stessa regola, una copia per profilo.

Aggiornamenti: quando c'è una versione nuova, striscia in alto «Aggiornamento pronto. [Aggiorna]» — mai ricaricare la
pagina da soli mentre il socio compila un pagamento.

### 1.4 Invito a installare

Mai alla prima visita e mai su una pagina di pagamento. Due punti d'ingresso:

1. **Dopo il secondo accesso riuscito**, in cima alla Tessera, una carta sobria (bordo filetto, non gialla):

```
┌──────────────────────────────────────┐
│ ▢  Tieni la tessera sul telefono     │
│    Si apre con un tocco, anche       │
│    senza campo.                      │
│    [ Aggiungi alla Home ]  Non ora   │
└──────────────────────────────────────┘
```
   «Non ora» la nasconde per 30 giorni (memoria del browser, solo comodità); «Non mostrare più» nel foglio.
2. **Sempre disponibile** in Profilo, sezione «Sul tuo telefono» (§1.5).

Comportamento per sistema:
- Android/Chrome: il pulsante usa l'evento di installazione del browser.
- iOS/Safari: il pulsante apre un `Sheet` «Aggiungi alla Home» con tre passi illustrati (icona Condividi → «Aggiungi alla
  schermata Home» → «Aggiungi»), testo in italiano semplice, un disegno per passo.
- Già installata (`display-mode: standalone`): l'invito sparisce e Profilo mostra «App installata» con segno di spunta.
- Altri browser: nessun pulsante, solo il testo «Aggiungi questa pagina alla schermata Home dal menu del browser».

### 1.5 Profilo: sezione «Sul tuo telefono»

Un `list-group` con righe: **App** (Installata / Installa), **Avvisi** (Attivi su questo telefono / Attiva, vedi 3b),
**Wallet** (vedi 3c). Una riga per cosa, stato a destra con la parola.

### 1.6 Accesso dall'app installata (punto delicato)

Su iPhone l'app installata ha un archivio di cookie **separato** da Safari: il link ricevuto per email, aperto, fa entrare
Safari e non l'app. Quindi:
- nella schermata d'accesso, se la pagina gira come app installata, il **codice a 6 cifre è l'azione principale** e il
  link è citato per secondo («Apri la mail, copia il codice e scrivilo qui»);
- il campo codice usa `inputmode="numeric"`, `autocomplete="one-time-code"` (iOS lo propone dalla tastiera);
- l'email del codice ha il codice **in grande in cima**, prima del pulsante del link;
- anche l'invito di WhatsApp della segreteria deve riportare il codice accanto al link. **Da decidere con lo sviluppo:**
  oggi quel link è senza codice.
- Una volta dentro, la sessione dura 30 giorni senza uso: non si chiede un nuovo accesso a ogni apertura.

### 1.7 Dettagli di schermata

- Con schermo intero la barra in alto resta com'è (margine sicuro già gestito); «Esci» resta in alto.
- Sulla Tessera, mentre è aperta, **schermo sempre acceso** (Screen Wake Lock) per non spegnersi davanti al lettore; si
  rilascia uscendo dalla pagina. Se non supportato, non succede nulla.
- Con la copia offline il tasto «Paga» e le altre voci mostrano «Serve la connessione» invece di fallire.

## 2. Parte 3b: avvisi sul telefono

### 2.1 Principio

Gli avvisi servono a **ricordare e informare di servizio**, non a fare promozione. Niente messaggi di massa della
segreteria in questo passo (richiederebbero il consenso `ConsentChannel` e un'altra schermata di scrittura).

### 2.2 Quando si chiede il permesso

Solo su gesto esplicito: pulsante «Attiva gli avvisi» in Profilo, o dal foglio che compare **dopo il primo pagamento o la
prima prenotazione riusciti** («Vuoi un avviso se cambia qualcosa?»). Mai all'apertura della pagina, mai a freddo.
Su iPhone gli avvisi funzionano solo con l'app installata: se non lo è, il pulsante apre prima l'`Sheet` d'installazione
(§1.4) con la frase «Per ricevere gli avvisi su iPhone aggiungi prima l'app alla Home».

### 2.3 Quali avvisi

| Avviso | Quando | Testo (con dettagli attivi) | Testo generico |
|---|---|---|---|
| Quota in scadenza | tessera in scadenza o scaduta da meno di 30 giorni (stesso motore dei promemoria) | «La tessera di {nome} scade il {data}» | «Hai una scadenza da controllare» |
| Scadenza del certificato | come sopra | «Il certificato di {nome} scade il {data}» | «Hai una scadenza da controllare» |
| Pagamento registrato | dopo conferma Stripe/PayPal | «Pagamento di {importo} ricevuto. Ricevuta disponibile» | «Pagamento ricevuto» |
| Pagamento da controllare | pagamento «da controllare» risolto dalla segreteria | «Ti abbiamo scritto per il pagamento del {data}» | «Aggiornamento su un pagamento» |
| Prenotazione confermata / cambiata / annullata | dalla segreteria o dal sistema | «{evento}, {data} alle {ora}: {esito}» | «Aggiornamento su una prenotazione» |
| Posto liberato dalla lista d'attesa | ingresso automatico dalla lista | «Ti si è liberato un posto: {evento}, {data}» | «Ti si è liberato un posto» |
| Evento annullato o spostato | modifica della data | «{evento} è stato spostato al {data}» | «Un evento è cambiato» |

Il testo generico è sempre quello **predefinito**: sul blocco schermo non compaiono nomi, importi né la parola
«certificato». Il socio può attivare i dettagli con un interruttore «Mostra i dettagli nell'avviso» (spento all'inizio).
Il nome dell'associazione è sempre il titolo dell'avviso.

Per il posto liberato dalla lista d'attesa la persona ha tempo di annullare fino al giorno prima: l'avviso apre la
prenotazione già selezionata. Questo chiude il «Da fare» di SVILUPPO.md §9 bis (avviso a chi entra dalla lista) anche per
chi non ha l'app: l'email resta il canale di riserva.

### 2.4 Impostazioni (Profilo → Avvisi, in `Sheet`)

```
Avvisi sul telefono
[ ● ] Attivi su questo telefono

Cosa vuoi ricevere
[ ● ] Scadenze di tessera e certificato
[ ● ] Pagamenti e ricevute
[ ● ] Prenotazioni ed eventi
[ ○ ] Mostra i dettagli nell'avviso
      Con i dettagli, nome e importi sono visibili anche a schermo bloccato.

I tuoi telefoni
 Pixel 8, Chrome      attivo dal 02/11     [Disattiva]
 iPhone, app          attivo dal 10/11     [Disattiva]
```
- Interruttori da 44 px con la parola «Sì/No» letta dallo screen reader, non solo posizione.
- Preferenze **per persona** (chi accede), valgono per tutti i suoi telefoni. Un tutore riceve gli avvisi dei minori di cui
  è tutore operativo; con «Per {nome}» solo se i dettagli sono attivi.
- «Disattiva» elimina la sottoscrizione del telefono. Se il permesso del sistema è negato, la riga dice «Bloccati dal
  telefono: riattivali dalle impostazioni» con una riga di istruzioni, non un errore.

### 2.5 Regole di invio

- Orario di silenzio **21:00–08:00** (Europe/Rome): gli avvisi non urgenti si fermano alle 08:00. Pagamento ricevuto e
  posto liberato sono urgenti e partono subito.
- Al massimo 3 avvisi al giorno per persona; i promemoria di scadenza sono già uno per persona, tipo e scadenza
  (`app.reminders`): lo stesso registro vale per l'avviso, **prenotato prima dell'invio**.
- Un solo canale per promemoria: se l'avviso sul telefono parte, l'email dello stesso promemoria no; se non c'è alcun
  telefono attivo o l'invio fallisce, parte l'email.
- Tocco sull'avviso = apre la pagina giusta (`/area/quote`, `/area/eventi/{id}`…), mai la home generica.
- Le sottoscrizioni scadute (risposta 404/410 del servizio di consegna) si eliminano da sole.

### 2.6 Dati (da progettare con lo sviluppo, regole di `OrgSchema` e cifratura)

- `app.push_subscriptions`: persona, indirizzo di consegna e chiavi **cifrati** (sono segreti), impronta per
  l'unicità, etichetta del dispositivo, creata il, ultimo invio, revocata il.
- `app.notification_preferences`: persona, tre categorie, dettagli sì/no.
- Registro (`actor_type = member`): `push.subscribed`, `push.unsubscribed`, `push.preferences_changed`.
- Coda: lavoro `OrgScopedJob` con solo identificativi; l'invio e il testo si compongono nel lavoro.
- Chiavi VAPID per ambiente nel file d'ambiente del server, **mai** nel repository. Libreria di invio: da vagliare con il
  controllo delle dipendenze.

## 3. Parte 3c: tessera nel wallet

### 3.1 Obiettivo

Il socio apre il wallet del telefono (Apple o Google) e mostra la tessera dall'**blocco schermo**, senza cercare l'app.

### 3.2 La tessera nel wallet

| Elemento | Contenuto |
|---|---|
| Titolo | nome dell'associazione |
| Nome | nome e cognome |
| Numero | n. tessera |
| Validità | «Anno sociale {anno}», «fino al {data}» — **non** «in regola»: lo stato vero lo dice il QR quando lo si legge |
| Codice | lo stesso QR della tessera (il link firmato di verifica, che dice solo se è valida oggi) |
| Colori | indaco di fondo, testo bianco; marchio Associazione Semplice (il simbolo negativo) |

Il pass **non contiene** indirizzi, date di nascita, certificati, importi. Un pass per ogni scheda (il tutore ne ha uno
per ciascun minore, con il nome del minore).

Il link firmato nel QR del wallet **non scade con il tempo** (è su un pass che si usa per anni), ma si può invalidare:
«Rigenera la tessera» in Profilo ne emette uno nuovo e spegne il vecchio. Chi esce dall'associazione, o la cui scheda è
sospesa, vede «non valida» alla lettura.

### 3.3 Aggiornamenti

Al rinnovo, il pass si aggiorna da solo («fino al {data}» cambia). Se il socio esce, il pass viene segnato come non più
valido nel wallet. Niente avvisi di aggiornamento nel wallet in questo passo.

### 3.4 Dove si aggiunge

Sulla Tessera, sotto la carta, **un solo pulsante** adatto al telefono: sui dispositivi Apple il badge ufficiale
«Aggiungi a Apple Wallet», su Android il badge «Aggiungi a Google Wallet», dal computer entrambi. I badge sono quelli
ufficiali (marchio): le regole di Apple e Google vietano di ridisegnarli. In Profilo, riga «Wallet» con stato («Aggiunta» non si può
sapere in modo affidabile: la riga dice solo «Aggiungi»).

## 4. Disegni da produrre (prima del codice)

1. Icona maschera 512 e controllo a 48 px.
2. Carta d'invito all'installazione (chiara e scura).
3. `Sheet` d'installazione per iPhone: tre passi illustrati.
4. `Sheet` «Avvisi» come in §2.4, con stato «bloccati dal telefono».
5. Striscia «Aggiornamento pronto» e striscia «Sei senza connessione».
6. Tessera offline (badge giallo «Salvata il…»).
7. Anteprima del pass wallet (fronte), in chiaro e scuro.

## 5. Decisioni che spettano al titolare (non all'implementazione)

| Tema | Domanda | Perché conta |
|---|---|---|
| Apple Wallet | Aprire un account Apple Developer (circa 99 USD l'anno) per il certificato dei pass? | Senza, solo Google Wallet |
| Google Wallet | Creare un account emittente Google Wallet (gratuito) con chiave di servizio? | Serve per firmare i pass |
| Emittente | Pass emessi da **Associazione Semplice** (un solo certificato, nome dell'associazione sul pass) o uno per associazione? | Il primo è fattibile subito, il secondo no |
| Avvisi | Confermi che in questo passo non ci sono messaggi di massa della segreteria? | Evita consensi e moderazione |
| Link WhatsApp | Aggiungere il codice al link di accesso per chi non ha email? | Serve per l'app su iPhone |

Le prime quattro vanno decise prima di iniziare 3c; 3a e 3b non dipendono da esse.

## 6. Controlli di accettazione

- **3a:** Lighthouse «installabile» verde su un sottodominio di prova; apertura senza rete della tessera con avviso di
  data; dopo «Esci» la copia non c'è più (provarlo); nessuna pagina di pagamento in cache; accesso con codice funziona
  nell'app installata su iPhone; l'invito non compare alla prima visita.
- **3b:** nessun avviso senza gesto dell'utente; avviso generico di default; silenzio notturno rispettato; un solo avviso
  per promemoria (non anche l'email); sottoscrizione 410 eliminata; test di separazione tra associazioni per le due tabelle nuove.
- **3c:** il pass si apre su un iPhone e su un Android veri; il QR letto dal lettore dell'ingresso dà lo stesso esito della
  schermata; «Rigenera la tessera» spegne il vecchio pass; nessun dato oltre a quelli in §3.2.
- Tutte: schermate a 360, 390, 768 e 1280 px; bersagli ≥ 44 px; contrasto ≥ 4,5:1; «riduci movimento»; ogni stato con una parola.

## 7. Ordine di lavoro consigliato

1. 3a: manifest, icone, service worker con le sole risorse statiche, pagina senza rete, invito a installare.
2. 3a: copia offline della tessera (con cancellazione all'uscita) e accesso con codice nell'app.
3. 3b: tabelle, sottoscrizione, impostazioni; poi gli avvisi uno alla volta partendo da «pagamento ricevuto» e «posto liberato».
4. 3b: promemoria di scadenza sul nuovo canale, con la regola «un solo canale».
5. 3c: dopo le decisioni di §5, prima Google Wallet, poi Apple Wallet.

Aggiornare [DESIGN.md](DESIGN.md) §6 e [SVILUPPO.md](SVILUPPO.md) §9 quinquies solo a lavoro finito.
