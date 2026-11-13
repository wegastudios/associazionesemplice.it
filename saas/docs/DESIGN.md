# Design dell'interfaccia

Piano per rendere gestionale e area soci più belli, intuitivi e moderni, **partendo dal telefono**. Niente effetti: una
interfaccia calma, leggibile e veloce, riconoscibile come Associazione Semplice (marchio in `docs/brand/README.md`:
Montserrat, indaco, salvia, giallo).

## 1. Per chi e per fare cosa

Chi usa il gestionale: segretari, tesorieri e volontari, spesso non esperti, spesso in piedi (al campo, al banco, in
sede) e con il telefono in mano. I gesti di ogni giorno, in ordine di frequenza:

1. **Trovare una persona** (per nome o numero di tessera) e vedere se è in regola.
2. **Registrare un incasso o una spesa**.
3. **Vedere cosa c'è oggi** (lezioni, eventi, prenotazioni) e cosa è rimasto da fare.
4. Iscrivere, rinnovare la tessera, prenotare una risorsa.
5. Controllare la contabilità (soprattutto da computer).

Il socio (area soci) vuole: la tessera sotto mano, prenotare, pagare, trovare la ricevuta. Sempre da telefono.

## 2. Cosa non va oggi (audit sul telefono, 390 px)

- L'intestazione occupa quasi un quinto dello schermo (logo, associazione, tre link, poi un menu a 12 voci tagliato
  che scorre di lato: le voci oltre la quarta non si vedono).
- Il riepilogo è quasi vuoto: un numero e due link. Non dice cosa fare.
- Le tabelle si troncano (email tagliate, colonne strette), i link degli strumenti sono sparsi come testo.
- Moduli lunghi con tanti campi uguali; controlli piccoli e bordi tutti uguali; selezioni native senza gerarchia.
- Stessi stili ripetuti a mano in ogni pagina (69 riquadri, 33 pulsanti principali, 20 campi): ogni ritocco va fatto
  in 43 file.

## 3. Principi

1. **Telefono prima, poi schermi larghi.** Si disegna a 360–430 px; da 1024 px in su si aggiunge la barra laterale e
   si allarga il contenuto (mai oltre 64 rem).
2. **Una mano sola.** Navigazione e azioni principali in basso, dove arriva il pollice. Bersagli di tocco da 44 px
   (campi da 48 px), campi a 16 px (iOS non ingrandisce la pagina).
3. **Le liste battono le tabelle.** Sul telefono ogni riga è una voce con titolo, riga di dettaglio e valore a destra;
   la tabella resta da `lg` in su dove c'è spazio.
4. **Poche cose per schermo, un'azione principale.** I moduli lunghi si dividono in sezioni; gli strumenti secondari
   stanno in un foglio («Altro»), non in fila sotto il titolo.
5. **Il sistema parla chiaro.** Frasi in italiano semplice, azioni che dicono cosa fanno («Registra l'incasso», non
   «Invia»); lo stesso nome dall'inizio alla fine; errori che spiegano come rimediare; schermate vuote che propongono il
   passo successivo.
6. **Una sola cosa memorabile**: la **Home come elenco di cose da fare** (domande da esaminare, tessere e certificati
   in scadenza, pagamenti da controllare, lezioni e prenotazioni di oggi) e il pulsante **Nuovo** sempre a portata.
   Tutto il resto resta sobrio.
7. **Niente decorazioni.** Niente sfumature, niente ombre su ogni riquadro, niente animazioni di ingresso. Il
   movimento serve solo a mostrare cosa è cambiato (apertura di un foglio, avviso di conferma) e si spegne con
   «riduci movimento».

## 4. Sistema visivo

### Colori (nomi e valori; il tema scuro segue il sistema, come oggi)

| Nome | Valore | Uso |
|---|---|---|
| Indaco | `#1F218C` (scuro: `#A6A9FF`) | azione principale, voce attiva, collegamenti |
| Salvia | `#5F8579`; testo su fondo salvia `#3D6256` | «in regola», conferme |
| Giallo | `#EFCA5C`; testo su fondo giallo `#6B4F00` | attenzione: in scadenza, da controllare |
| Rosso | `#B42318` | errori, scaduto, azioni che cancellano |
| Carta | `#F5F6FA` | sfondo della pagina |
| Inchiostro / Grigio / Filo | `#181A2E` / `#5D6078` / `#E2E5EE` | testo, testo secondario, linee |

Il contrasto di ogni coppia testo/sfondo è almeno 4,5:1. Il colore non è mai l'unico segnale: ogni stato ha anche una
parola (e, dove serve, un'icona).

### Carattere

Montserrat (già ospitato da noi) in Medium, SemiBold e Bold; un solo carattere. Scala sul telefono: titolo di pagina
24/30 Bold; titolo di sezione 17/24 SemiBold; testo 15/22 Medium; dettagli 13/18 Medium in grigio; campi 16.
Importi sempre con cifre allineate (`tabular-nums`). Frasi in minuscolo con la maiuscola iniziale, senza tutto-maiuscolo.

### Forme

- Raggi per gerarchia: campi e pulsanti 12 px, gruppi di righe e riquadri 16 px, fogli 24 px in alto, etichette e
  avatar tondi.
- **Il contenuto sta in gruppi di righe, non in tanti riquadri uguali**: un riquadro bianco con filetto contiene
  molte righe separate da una linea. Niente ombre sui riquadri; solo i fogli e la barra in basso hanno un'ombra leggera.
- Avatar con le iniziali su fondo chiaro preso dai tre colori del marchio (sempre lo stesso per la stessa persona).
- Icone: Lucide (tratto da 1,75), sempre con la parola accanto nelle voci di navigazione.

### Componenti (cartella `resources/js/components/ui`)

I pulsanti e i campi sono classi (`btn btn-primary|secondary|ghost|danger`, `btn-sm`, `field`), non componenti.
`Sheet` (foglio dal basso su telefono, finestra al centro da `md`), `Avatar`, le classi `badge`, `list-group` e `list-row`,
`PageHeader`, `EmptyState`, `Segmented` (scelta tra due-tre viste), `FlashMessages` (conferme a comparsa ed errori).
Le icone sono in `components/ui/icons.js` (solo quelle usate). Le classi semantiche `btn`,
`field`, `card`, `badge` stanno in `resources/css/app.css`: le pagine usano quelle, non stringhe di utilità ripetute.

## 5. Navigazione

Telefono:

```
┌──────────────────────────────┐
│ ◉ Polisportiva Alfa ⌄  [+ Nuovo] │   intestazione bassa 56 px
├──────────────────────────────┤
│                              │
│  contenuto                   │
│                              │
├──────────────────────────────┤
│ Home  Soci  Attività  Prima nota  Altro │   barra in basso, 5 voci
└──────────────────────────────┘
```

- La barra in basso mostra al massimo 4 voci + «Altro», scelte in base a permessi e pacchetti: Home, Soci, Attività,
  «Prima nota» (del gruppo «Cassa e conti»), oppure Risorse al posto della prima nota per chi non vede la contabilità.
- **Nuovo** apre un foglio con le azioni di creazione consentite (nuovo incasso, nuova spesa, nuova scheda,
  nuova attività, prenota una risorsa).
- **Altro** apre un foglio con tutte le sezioni raggruppate (Persone, Attività e risorse, Cassa e conti, Gestione) e, in fondo,
  l'account (sicurezza, cambia associazione, esci).
- Il nome dell'associazione nell'intestazione apre il cambio di associazione quando se ne seguono più d'una.

Da 1024 px: barra laterale fissa con le stesse sezioni raggruppate, intestazione con «Nuovo» e account, contenuto
centrato fino a 64 rem.

Area soci: stessa logica, più semplice: barra in basso con Tessera, Eventi, Prenota (se c'è), Paga, Ricevute, Profilo;
la tessera è la prima schermata e resta leggibile con il telefono girato a metà luce (contrasto alto, QR grande).

## 6. Piano di lavoro

| Passo | Cosa | Stato |
|---|---|---|
| 1 | Questo piano e le fondamenta: token, classi semantiche, icone, componenti | **fatto** |
| 2 | Layout del gestionale (barra in basso, Nuovo, Altro, barra laterale, notifiche a comparsa) | **fatto** |
| 3 | Tutte le pagine esistenti passano alle classi semantiche (stesso aspetto ovunque, bersagli da 44 px) | **fatto** (automatico: `btn`, `field`, `card`) |
| 4 | Pagine disegnate da capo: accesso, Home, Soci (elenco e scheda a sezioni), Prima nota (elenco, dettaglio e moduli in fogli) | **fatto** |
| 5 | Attività, Risorse, Domande, Operatori, Conti, Rendiconto, Certificati, Libro soci: elenchi al posto delle tabelle sul telefono, moduli in sezioni | da fare |
| 6 | Area soci: barra in basso con icone, tessera come carta indaco, avviso dei pagamenti da fare | **fatto**; resta Paga/Quote/Eventi/Prenota sugli stessi componenti |
| 7 | Verifica: screenshot a 360, 390, 768 e 1280 px, tastiera, contrasto, «riduci movimento» | a ogni passo |
| 8 | Specifica del passo 5: [DESIGN_PASSO_5.md](DESIGN_PASSO_5.md) | progettata, da sviluppare |
| 9 | Area soci passo 3 (app installabile, avvisi, wallet): [DESIGN_AREA_SOCI_PASSO_3.md](DESIGN_AREA_SOCI_PASSO_3.md) | progettata, da sviluppare |

Regola per ogni pagina nuova: si disegna prima a 390 px; si controlla poi a 768 e a 1280.

## 7. Cosa abbiamo scartato

- **App nativa o PWA separata** per il gestionale: due interfacce da mantenere. Le pagine web adattive bastano; l'area
  soci diventerà installabile più avanti.
- **Libreria di componenti pronta** (Vuetify, PrimeVue…): pesa e impone un aspetto che non è il nostro; con Tailwind e
  una dozzina di componenti nostri si resta leggeri e coerenti con il marchio.
- **Tema con sfumature, ombre e animazioni**: non serve a chi deve registrare un incasso e rende tutto più lento.
