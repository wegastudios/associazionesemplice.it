# Design, area soci passo 3: app installabile, avvisi e tessera nel wallet

Specifica di progetto per la sessione di sviluppo. Contesto: [SVILUPPO.md](SVILUPPO.md) §9 quinquies (area soci passi 1 e 2),
[DESIGN.md](DESIGN.md) (principi e componenti). Pagine coinvolte: `layouts/MemberLayout.vue`, `AreaSoci/Tessera.vue`,
`AreaSoci/Profilo.vue`, `AreaSoci/Accesso.vue`.

Tre parti indipendenti, da rilasciare in quest'ordine: **3a** app installabile, **3b** avvisi sul telefono, **3c** tessera
nel wallet. **Decisioni del titolare (§5):** la 3c è **rimandata** (Apple Wallet non interessa per ora, Google Wallet si
apre più avanti); gli avvisi (3b) sono indispensabili e si progettano subito, comprese le comunicazioni della segreteria (§2.8);
il codice nel link di accesso per chi non ha email è approvato.

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
- anche l'invito di WhatsApp della segreteria riporta il codice accanto al link (**approvato**; oggi il link è senza
  codice). Il messaggio da copiare diventa: «Ciao {nome}, ecco il tuo accesso all'area soci di {associazione}: {link}.
  Se usi l'app, scrivi questo codice: {codice}. Vale 7 giorni.» Il codice resta a uso singolo e con 5 tentativi.
- Una volta dentro, la sessione dura 30 giorni senza uso: non si chiede un nuovo accesso a ogni apertura.

### 1.7 Dettagli di schermata

- Con schermo intero la barra in alto resta com'è (margine sicuro già gestito); «Esci» resta in alto.
- Sulla Tessera, mentre è aperta, **schermo sempre acceso** (Screen Wake Lock) per non spegnersi davanti al lettore; si
  rilascia uscendo dalla pagina. Se non supportato, non succede nulla.
- Con la copia offline il tasto «Paga» e le altre voci mostrano «Serve la connessione» invece di fallire.

## 2. Parte 3b: avvisi sul telefono

### 2.1 Principio

Gli avvisi servono a **ricordare e informare di servizio**, non a fare promozione. Sono di due famiglie: **automatici**
(§2.3, generati dal sistema) e **comunicazioni della segreteria** (§2.8, scritte da una persona). Entrambi passano dallo
stesso canale, dalle stesse impostazioni del socio e dalla stessa bacheca (§2.7).

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
[ ● ] Comunicazioni dell'associazione
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
- `app.notification_preferences`: persona, quattro categorie (scadenze, pagamenti, prenotazioni, comunicazioni), dettagli sì/no.
- `app.notifications`: la voce della bacheca (§2.7) per ogni avviso automatico o comunicazione, per persona (e scheda del minore), con letta il.
- Registro (`actor_type = member`): `push.subscribed`, `push.unsubscribed`, `push.preferences_changed`.
- Coda: lavoro `OrgScopedJob` con solo identificativi; l'invio e il testo si compongono nel lavoro.
- Chiavi VAPID per ambiente nel file d'ambiente del server, **mai** nel repository. Libreria di invio: da vagliare con il
  controllo delle dipendenze.

### 2.7 Bacheca «Avvisi» nell'area soci

Un avviso sul telefono si può perdere (silenziato, cancellato, permesso negato). Ogni avviso, automatico o della
segreteria, è quindi anche una **voce nella bacheca**, che funziona anche senza app e senza permesso.

- Accesso: **campanella** nell'intestazione dell'area, a sinistra di «Esci», con un puntino (e il numero dei non letti
  scritto per esteso nell'etichetta per lo screen reader) quando c'è qualcosa di nuovo. Non si aggiunge una sesta voce
  alla barra in basso (ne ha già cinque).
- Pagina `/area/avvisi`: `list-group` dal più recente, 60 giorni. Riga: icona della famiglia, titolo, prima riga del testo,
  data relativa («oggi», «ieri», «12 nov»), pallino se non letta. Tocco = dettaglio in `Sheet` con testo completo e, se
  c'è, il pulsante dell'azione («Vai al pagamento», «Apri la prenotazione»).
- Si segna come letta all'apertura del dettaglio; «Segna tutte come lette» in cima.
- Per un tutore, la riga dice «Per {nome del minore}» sotto il titolo; l'elenco si filtra con le stesse pastiglie
  «Io / {minore}» già presenti nell'area.
- Vuoto: «Nessun avviso. Quando l'associazione ti scrive o c'è una scadenza, la trovi qui.»
- La bacheca mostra il testo **con** i dettagli: nella bacheca si è già dentro l'app, la riservatezza riguarda solo il blocco
  schermo.

### 2.8 Comunicazioni della segreteria

Da questa parte la segreteria scrive ai soci. È indispensabile, ma è anche l'unico punto in cui una persona può mandare
un messaggio a molti: le regole sotto servono a tenerlo di servizio e non di promozione.

**Gestionale:** pagina `/gestionale/comunicazioni` (voce in «Altro» → Persone; permesso nuovo `communications.send`,
assegnabile ai ruoli personalizzati, non al commercialista) con l'elenco degli invii passati e il pulsante «Nuova
comunicazione», che apre un foglio a passi (telefono) o una pagina a sezioni (da `lg`):

1. **A chi.** Scelta unica con `Segmented`/elenco di opzioni:
   - Tutti i soci in regola
   - Soci con la tessera in scadenza o scaduta
   - Iscritti a un'attività (si sceglie quale)
   - Prenotati a una data di un evento (si sceglie quale)
   - Una persona sola (con `PersonPicker`)
   Sotto, **sempre il conteggio prima dell'invio**: «Lo riceveranno 84 persone. Sul telefono: 51. Solo in bacheca ed
   email: 33.» Chi non ha un tutore operativo o un'email né un telefono è nominato in un elenco apribile («7 persone non
   raggiungibili»).
2. **Cosa scrivi.** Titolo (massimo 50 caratteri), testo (massimo 280; i primi 90 compaiono nell'avviso sul telefono),
   azione facoltativa scelta da un elenco fisso (apri Eventi, apri una prenotazione, apri Quote) — niente indirizzi liberi.
   Avvertenza fissa sopra il campo: «Il testo si legge anche a schermo bloccato. Non scrivere dati personali, sanitari o
   importi di una persona.» Contatore dei caratteri visibile.
3. **Quando.** «Subito» o «Programma» (data e ora, non prima di un'ora da adesso, non nelle fasce di silenzio).
4. **Controlla e invia.** Anteprima dell'avviso come appare sul telefono (con il nome dell'associazione come titolo) e
   riepilogo del conteggio; pulsante **«Invia a 84 persone»** (testo con il numero vero, mai solo «Invia»); un'ulteriore
   conferma solo sopra le 50 persone (`Sheet` con la frase «Non si può ritirare dopo l'invio»).

**Regole:**
- Una comunicazione inviata **non si modifica né si cancella**; si può solo mandarne una di rettifica. Quella programmata
  si può annullare fino all'orario.
- Limiti per associazione: 2 comunicazioni al giorno e 8 alla settimana; sopra, il pulsante spiega perché è bloccato.
- Il socio le riceve se non ha spento «Comunicazioni dell'associazione» (quarta categoria, acceso di default perché è
  servizio; la segreteria non può forzarlo). Chi l'ha spento le trova comunque in bacheca, senza avviso.
- **Email di riserva** solo se la persona ha il consenso al canale email in `Soci\Contracts\ConsentChannel`; senza, solo
  bacheca e telefono.
- Niente allegati, niente immagini, niente link liberi (riduce phishing e spam).
- I soci usciti o sospesi non le ricevono, salvo «Una persona sola».
- Il minore non riceve: il messaggio va al tutore operativo, con «Per {nome}» solo nella bacheca.

**Dati e registro:**
- `app.announcements`: autore, destinatari scelti (tipo e riferimento), titolo, testo, azione, programmato per, inviato il, annullato il.
- `app.announcement_deliveries`: una riga per persona e canale (telefono, bacheca, email), con esito e letta il; è la base del conteggio e della bacheca.
- Registro (`actor_type` operatore): `announcement.created`, `announcement.sent`, `announcement.cancelled`, con numero di destinatari, mai con il testo dei destinatari.
- Test: separazione tra associazioni, il commercialista non può inviare, i limiti giornalieri, l'esclusione dei sospesi e dei minori.

**Cosa non c'è in questo passo:** risposte dei soci, sondaggi, immagini, invii a gruppi liberi. Se serviranno, sono un passo a parte.

## 3. Parte 3c: tessera nel wallet — RIMANDATA

Per decisione del titolare: Apple Wallet non interessa per ora, Google Wallet si apre più avanti. Questa sezione resta
come riferimento e **non va sviluppata ora**. Quando si riprende: prima Google Wallet, poi eventualmente Apple.

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

## 4. Disegni

Schermi di riferimento in [mockup/area-soci-passo-3.html](mockup/area-soci-passo-3.html) (si apre nel browser; stessi colori e
misure dell'app, anche tema scuro): icona maschera, invito a installare, foglio per iPhone, impostazioni degli avvisi, strisce
di stato, tessera senza rete, bacheca «Avvisi», avvisi a schermo bloccato, nuova comunicazione della segreteria, sezione
«Sul tuo telefono» in scuro. L'anteprima del pass wallet non c'è: la 3c è rimandata. I riquadri del foglio per iPhone sono
descrittivi: le illustrazioni vere di Safari le fa lo sviluppo.

## 5. Decisioni che spettano al titolare (non all'implementazione)

| Tema | Decisione | Stato |
|---|---|---|
| Apple Wallet | Non interessa per ora | deciso |
| Google Wallet | Si apre l'account più avanti | rimandato |
| Emittente dei pass | Un solo emittente (Associazione Semplice) con il nome dell'associazione sul pass, oppure uno per associazione | da spiegare e decidere quando si riprende la 3c |
| Avvisi | Indispensabili: si progettano subito, comprese le comunicazioni della segreteria (§2.8) | deciso |
| Link WhatsApp | Il codice si aggiunge al link di accesso | approvato |

3a e 3b non dipendono da nessuna decisione aperta.

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
3. 3b: tabelle (sottoscrizioni, preferenze, avvisi/consegne), bacheca `/area/avvisi` con campanella (funziona anche senza telefono).
4. 3b: sottoscrizione push e impostazioni; poi gli avvisi automatici uno alla volta partendo da «pagamento ricevuto» e «posto liberato».
5. 3b: promemoria di scadenza sul nuovo canale, con la regola «un solo canale».
6. 3b: comunicazioni della segreteria (§2.8), con permesso `communications.send`, conteggio, limiti e programmazione.
7. 3c rimandata: non sviluppare.

Aggiornare [DESIGN.md](DESIGN.md) §6 e [SVILUPPO.md](SVILUPPO.md) §9 quinquies solo a lavoro finito.
