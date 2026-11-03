# SaaS: perimetro del lancio e pacchetti

Cosa entra nella prima versione del SaaS e come si divide in pacchetti. Lo schema del database è in
[SAAS_DATABASE.md](SAAS_DATABASE.md).

## 0. Il principio

Chi cerca un **gestionale**, e non un plugin, ha esigenze diverse: **la contabilità non è un optional** ed è il cuore
del prodotto, insieme a ruoli e accessi degli operatori. L'area soci è invece un pacchetto: senza un sito, il gestionale
serve poco ai soci. Biglietteria, gestione risorse e simili sono interventi successivi.

Si vendono due offerte: **gestionale online** e **gestionale online + sito**. Il plugin WordPress resta com'è e si
distingue per dove stanno i dati (sullo spazio del cliente, invece che sul nostro).

## 1. Concorrenza (analisi del 2026-11-03)

| Concorrente | Piani (IVA esclusa) | Su cosa si basa il prezzo | Contabilità | Area soci / app | Sito |
|---|---|---|---|---|---|
| **TeamSystem**, Terzo Settore in Cloud e Sportivi in Cloud | Starter 26 €/mese · Advanced 42 · Pro 69 | numero di utenti (uno o più) e tipo di contabilità (partita doppia solo nel Pro) | in tutti i piani: ricevute, rendiconto, fattura elettronica illimitata | modulo a parte: web app soci 23 €/mese, iscrizioni online 9 €/mese | no |
| **AssoFacile** | Free · Basic 199 €/anno · Premium 299 · Exclusive 499 | anagrafiche (500 / 1.000 / illimitate) e amministratori (1 / 5 / 5 / illimitati) | solo da Premium, con fattura elettronica e rendiconto ETS/RUNTS/L.398 | app soci inclusa anche nel Free | solo un plugin WordPress (Exclusive) |
| **VERIF!CO** (centri di servizio per il volontariato) | Freemium · Mini 99 €/anno · Premium 299 · Maxi 499 | entrate annue dell'ente | il Mini è solo contabilità e bilancio; competenza solo nel Maxi | — | — |
| **AssoConnect** (Francia, team.blue) | da circa 28 €/mese | fasce di contatti | nei piani a pagamento | sì | incluso; biglietteria come modulo |

Cosa se ne ricava:

1. La contabilità è il cuore dei gestionali (TeamSystem, VERIF!CO). Solo AssoFacile la tiene a pagamento, perché parte
   dall'app soci gratuita.
2. Partita doppia e competenza stanno sempre nel piano più alto: confermano la scelta dell'addon.
3. L'area soci si vende a parte (TeamSystem 23 €/mese).
4. In Italia nessuno dei concorrenti analizzati integra un sito vero (al massimo un plugin WordPress): il pacchetto
   **gestionale + sito** è uno spazio libero.
5. La fattura elettronica (SDI) è standard: va anticipata, appoggiandosi a un intermediario già accreditato.
6. Fascia di prezzo di mercato: circa 16–70 €/mese.

Fonti: [TeamSystem Terzo Settore in Cloud](https://www.teamsystem.com/associazioni/terzo-settore-in-cloud/prezzi/) ·
[TeamSystem Sportivi in Cloud](https://www.teamsystem.com/sport/sportivi-in-cloud/prezzi/) ·
[AssoFacile](https://www.assofacile.it/funzioni/) · [VERIF!CO](https://www.veryfico.it/prezzi/) ·
[AssoConnect (independant.io)](https://independant.io/avis/assoconnect/) ·
[AssoConnect (tool-advisor)](https://tool-advisor.fr/logiciel-association/comparatif/assoconnect/)

## 2. Fondamenta (prima di qualunque modulo)

- Separazione tra associazioni, cifratura dei campi, registro delle azioni ([SAAS_DATABASE.md](SAAS_DATABASE.md)).
- Accesso con verifica in due passaggi per gli operatori; permessi derivati dai dati.
- Migrazioni su tutti i database (condiviso e dedicati); dump orari cifrati.
- Abbonamento e fatturazione (Stripe Billing): prova gratuita, fascia di soci, pacchetti.
- Configurazione guidata al primo accesso.
- Import da Excel e CSV (soci, prima nota); esportazione completa dei dati.

## 3. Gestionale: base, al lancio

**Contabilità**
- prima nota, conti (cassa, banca, carte), categorie sul modello ETS;
- ricevute numerate senza buchi, comprese quelle per i minori intestate al genitore;
- rendiconto per cassa ETS, chiusura dell'esercizio;
- fondi e rimborsi ai volontari, preventivo, alert di bilancio;
- allegati alle spese; import dell'estratto conto da CSV con riconciliazione;
- IVA e corrispettivi, regime L.398 per le sportive.

**Ruoli e accessi**
- **3 operatori inclusi**: presidente, vicepresidente, tesoriere;
- **operatori aggiuntivi a pagamento**, a basso costo, non gratuiti e illimitati: segretari e consiglieri con permessi
  configurabili. Una segreteria numerosa ha il budget per pagarli;
- il **commercialista** (o altro professionista invitato dall'associazione) non conta come operatore: accesso alla
  contabilità, revocabile dall'associazione, e un'**area Studio** gratuita con tutte le associazioni seguite;
- verifica in due passaggi per tutti e registro delle azioni.

**Soci**
- anagrafica, tesseramento e quote, libro soci;
- minori e tutori, certificati medici, consensi.

**Attività come centri di costo**
- corsi ed eventi con le iscrizioni registrate dalla segreteria, per legare incassi e spese a ciascuna attività.

## 4. Pacchetti

| Pacchetto | Quando | Contenuto |
|---|---|---|
| **Area soci e pagamenti online** | al lancio | tessera QR, profilo, iscrizioni e pagamenti online (Stripe, PayPal), PWA |
| **Risorse e prenotazioni** | al lancio | impianti e spazi prenotabili (campi da tennis, calcetto, padel, sale, comprese le sale del bar o del ristorante con servizi aggiuntivi alla prenotazione), posti barca, postazioni spiaggia e solarium; disponibilità per data, tariffe soci e non soci, giornaliere e stagionali, lista d'attesa. Prenotazione e pagamento online dei soci con l'Area soci. Anticipato per circoli sportivi con impianti e circoli nautici e balneari: per loro le risorse sono il cuore dell'attività, e senza questo pacchetto il gestionale non servirebbe |
| **Database dedicato** | al lancio | database esclusivo dell'associazione, con chiave e backup propri |
| **Sito** | fase 3 | sito generato dall'AI, dominio di terzo livello incluso; il cliente controlla solo testi e allegati. **Non comprende l'area soci**: i blocchi di iscrizione e pagamento online compaiono solo se è attivo anche quel pacchetto |
| **Dominio personalizzato** | con il Sito | extra a pagamento |

## 5. Addon successivi

- **Fattura elettronica SDI** (anticipata rispetto al piano iniziale), tramite intermediario accreditato.
- **Partita doppia e contabilità per competenza.**
- **Comunicazioni di massa** via email; **SMS** a consumo.

### 5.1 Fattura elettronica: come si fa

| Pezzo | Chi lo fa |
|---|---|
| Generazione dell'XML FatturaPA, validazione con lo schema ufficiale, PDF, registrazione in prima nota | **noi**: è il valore vero e ci lascia liberi di cambiare fornitore. Modelli guidati per i casi tipici delle associazioni (sponsorizzazione, pubblicità, servizio, cessione), regole riviste da un commercialista |
| Trasmissione allo SDI e ricezione delle fatture passive | **intermediario** (A-Cube in valutazione, Invoicetronic come confronto), dietro un'interfaccia `SdiProvider` |
| Conservazione | servizio gratuito dell'Agenzia delle Entrate (15 anni), più quella dell'intermediario se inclusa |

Un **canale SDI nostro** (SDICoop, con accreditamento) si valuta solo se i volumi lo giustificano.

**Una sola chiave, nostra.** Tutte le associazioni (SaaS e plugin) passano dal nostro ponte con un'unica chiave
dell'intermediario: si fa volume e si scende di fascia di prezzo. La chiave non arriva mai sui siti dei clienti; il
plugin chiama il ponte con la propria licenza.

**Costi, tarati sul caso peggiore** (fasce più basse, novembre 2026): Invoicetronic 0,10 € per operazione nel
pacchetto da 1.000 (invio, ricezione e validazione contano ciascuno), Openapi 0,049 € a fattura. Validando l'XML da noi
prima dell'invio si evita di pagare la validazione dell'intermediario: **circa 0,10 € a fattura emessa** e
**0,10 € a fattura ricevuta**. Cinquanta fatture emesse costano al massimo circa 5 € l'anno.

**Al cliente, a pacchetti**:
- **50 fatture emesse all'anno incluse** nell'addon; si azzerano a ogni rinnovo;
- **ricariche** acquistabili quando servono, che **non scadono**;
- consumo e saldo visibili in ogni momento, avvisi all'80% e al 100%;
- a credito esaurito si blocca solo **l'emissione**: la **ricezione** delle fatture passive non si ferma mai.

**Rischio da gestire:** con un'unica chiave, se il credito presso l'intermediario finisce si fermano tutti i clienti.
Credito comprato in anticipo, monitoraggio del saldo complessivo e avvisi ben prima della soglia.

**Nel database**: i movimenti di credito (pacchetti inclusi, ricariche, consumi) in una tabella a sola aggiunta per
associazione, come la prima nota.

**Plugin**: stesso modello del SaaS. Il **portale** fa da ponte verso l'intermediario (il plugin chiama il portale
con la propria licenza), con gli stessi pacchetti: 50 fatture emesse all'anno incluse nell'addon Pro e ricariche. Si
collega quando il ponte del portale è pronto; il ponte lo sviluppa la sessione del portale. L'invio dalla PEC
dell'associazione per ora non è previsto.

**Da verificare**: obblighi attuali per le sportive in regime 398 (l'esonero sotto i 65.000 € è stato ridotto dal 2022)
con un commercialista; preventivo dei due intermediari.

## 5.2 Programma partner

Per chi porta clienti: commercialisti, CAF, agenzie di comunicazione, centri di servizio, chiunque.

- **Iscrizione a un mini-portale partner**: dati fiscali e di pagamento, accettazione delle condizioni. Il portale
  rilascia un **codice** e un **link** da condividere.
- **Compenso**: una **percentuale su tutto ciò che fatturiamo all'associazione** (canone, pacchetti, ricariche,
  addon), **ricorrente** per tutto il tempo in cui il partner amministra il cliente: ogni mese, ogni anno, non una
  tantum. Calcolata sull'incassato, al netto di IVA e rimborsi.
- **A chi spetta**: al partner che **amministra il cliente in quel momento**. L'attribuzione nasce dal codice o dal link
  alla registrazione; se l'associazione cambia partner, da quel momento il compenso passa al nuovo.
- **Liquidazione su richiesta**, raggiunta una soglia minima: il portale emette un **numero di pratica**, il partner ci
  manda una regolare fattura con quel numero, noi paghiamo agli estremi indicati.
- Vale anche per le **licenze Pro del plugin**: un unico programma, gestito dal portale.
- Nel database: ogni incasso registra il partner attribuito in quel momento, in una tabella a sola aggiunta; maturato,
  richieste e pagamenti si ricavano da lì.

**Soglia minima di liquidazione**: circa **due anni di compenso sul servizio minimo**, arrotondata per eccesso.
Esempio: servizio minimo a 7 €/mese (84 €/anno), percentuale al 10% → 16,80 € in due anni → soglia **20 €**. Con un
solo cliente al minimo ci vogliono due anni; con più clienti e pacchetti si arriva prima e più spesso, ed è lo scopo.
La soglia si ricalcola quando si fissano prezzi e percentuale.

**Il maturato non scade, ma sotto la soglia non è esigibile in nessun caso**: nemmeno se il cliente cambia partner,
ci lascia, o il partner non ha più clienti. Resta fermo, in attesa dei prossimi clienti che lo portino sopra la
soglia. Va scritto chiaramente nelle condizioni del programma.

Da decidere: percentuale (ed eventuali scaglioni), verifica dei limiti deontologici dei commercialisti (trasparenza
verso il cliente).

## 6. Interventi successivi

- Biglietteria e controllo degli ingressi.
- Assemblee, votazioni e verbali; registro presenze e assicurazioni.
- Affiliazioni, convenzioni, tessera nei wallet, 5×1000.
- Migrazione bidirezionale con il plugin (ultimo passo).

## 7. Pilota e demo

- 3–5 associazioni vere nella prima fase, scelte tra chi usa già il plugin.
- Demo SaaS a sé: ogni visitatore riceve un'associazione di prova con dati finti, creata al volo e cancellata dopo
  qualche giorno. La demo attuale del plugin non si tocca.
- Niente piano gratuito nel SaaS, solo la prova (regole al §7.1): il gratuito resta il plugin.

## 7.1 Struttura dei piani (prezzi da definire)

**Concorrenza verticale per circoli**: Playtomic Manager (campi; secondo un concorrente circa 59–279 €/mese più il 4%
sulle prenotazioni, dato non verificato), [Harba](https://harba.co/pricing) (porti e yacht club: 2.700 €/anno più
5 € per posto barca, più 1.350 € di avvio), TeamSystem (case history della Lega Navale di Ancona), Sportclubby. Costano
più dei gestionali per associazioni e non gestiscono soci e contabilità da ente non profit. **Noi niente commissioni
sulle prenotazioni.**

**Base: Gestionale** (§3), prezzo per **fascia di soci** (tesserati attivi nell'anno). In più rispetto al §3: domanda
di adesione online tramite link pubblico, email di servizio (ricevute, rinnovi, scadenze dei certificati), ricezione
delle fatture passive via SDI per chi ha partita IVA, con un limite d'uso ragionevole.

**Pacchetti**

| Pacchetto | Contenuto | Criterio di prezzo |
|---|---|---|
| Area soci e pagamenti | PWA, tessera QR e wallet, profilo, iscrizioni e rinnovi online, prenotazione eventi, pagamenti, notifiche push | fascia di soci |
| Risorse e prenotazioni | impianti, sale, posti barca, postazioni; tariffe soci e non soci; liste d'attesa; calendario di occupazione | **numero di risorse, pesato per modalità di prenotazione** (sotto) |
| Sito | builder AI, sottodominio, rigenerazioni mensili incluse | fisso |
| Fattura elettronica | 50 fatture emesse all'anno, conservazione | fisso, più ricariche |
| Comunicazioni | email di massa e newsletter, avvisi di gruppo, SMS | fisso, SMS a consumo |
| Contabilità avanzata | partita doppia, competenza, stato patrimoniale e rendiconto gestionale ETS | fisso |
| Vita associativa | assemblee, deleghe, votazioni online, verbali, libri sociali | fisso |
| Sport | affiliazioni e tesseramenti con federazioni ed enti di promozione, registro safeguarding, compensi dei collaboratori sportivi (da sviluppare) | fisso |

**Risorse: il peso dipende da quanto lavorano, non da quante sono.** Quattro campi da tennis generano molte più
prenotazioni e scritture di 200 posti barca assegnati una volta l'anno. Il criterio è quindi la **modalità di
prenotazione** di ogni risorsa:

| Tipologia | Esempi | Movimento | Primo scaglione |
|---|---|---|---|
| **Oraria** | campi da tennis, padel, calcetto | alto | **2** risorse |
| **Giornaliera** | sala del ristorante o del bar, postazioni spiaggia a giornata, sale per feste | medio | **10** risorse |
| **Stagionale** (assegnazione per stagione o anno, qualche rotazione) | posti barca, ombrelloni stagionali, armadietti | basso | **50** risorse (un circolo nautico parte in genere da circa 20 posti) |

La tipologia è **il modo in cui la risorsa si vende**, non la risorsa in sé: un posto barca è di solito stagionale, ma
il circolo può venderne anche 3 a giornata o a ore, e allora quei 3 contano come giornalieri o orari. Per usare la
stessa sala in più fasce della giornata (colazione, pranzo, cena) si comprano 3 risorse giornaliere. Un circolo con
più tipologie somma i loro scaglioni.

Una stessa risorsa conta nella modalità in cui è usata; un circolo con entrambe somma i due scaglioni.

**Extra e consumi**: operatori aggiuntivi (segretari e consiglieri con permessi configurabili), dominio personalizzato,
database dedicato, ricariche di fatture elettroniche, SMS e rigenerazioni del sito.

**Offerte già composte**, con sconto rispetto ai pacchetti singoli; chi vuole compone da sé.

| Offerta | Contenuto | Per chi |
|---|---|---|
| Associazione | Gestionale + Area soci | APS, ODV, culturali |
| Associazione con sito | Gestionale + Area soci + Sito | chi parte da zero |
| Sportiva | Gestionale + Area soci + Sport | ASD e SSD |
| Circolo | Gestionale + Risorse + Area soci + Fattura elettronica | circoli sportivi, nautici, balneari |

**Prova gratuita**

| Caso | Durata |
|---|---|
| Base (c'è molto da compilare) | **30 giorni** |
| Pacchetti attivati **insieme alla base** in prova | 30 giorni, insieme alla base |
| Pacchetto provato **su una base già operativa** | **7 giorni**, prorogabili a **14** su richiesta, inviata automaticamente all'assistenza |

## 7.2 Listino a regime

Prezzi **di listino**, come se fossimo già consolidati; le promo si applicano sopra. Tutti **IVA esclusa** (per le
associazioni senza partita IVA l'IVA è un costo: da ricordare nella comunicazione). **Annuale = 10 mensilità.**
**Nessuna commissione** su pagamenti e prenotazioni.

### Base: Gestionale, a scaglioni marginali di soci

Si contano i tesserati attivi nell'anno. Ogni socio costa in base allo scaglione in cui cade, come le aliquote IRPEF.

| Soci | Prezzo per socio al mese |
|---|---|
| primi 50 | **15 € fissi** (prezzo minimo) |
| dal 51° al 150° | 0,18 € |
| dal 151° al 400° | 0,12 € |
| oltre il 400° | 0,06 € |

| Soci | Mensile | Per socio all'anno |
|---|---|---|
| 50 | 15 € | 3,60 € |
| 100 | 24 € | 2,88 € |
| 150 | 33 € | 2,64 € |
| 250 | 45 € | 2,16 € |
| 400 | 63 € | 1,89 € |
| 1.000 | 99 € | 1,19 € |
| 2.500 | 189 € | 0,91 € |

Confronto: TeamSystem 26–42 € (69 con la partita doppia) senza scala per socio, AssoFacile circa 25 € fino a
1.000 anagrafiche, ACLI/ARCI circa 4 € a socio all'anno (affiliazione, assicurazione e servizi).

**Spazio file**: **5 MB a socio** (certificato medico e poco più), minimo 250 MB. I pacchetti ne aggiungono: Sito
+2 GB, Vita associativa +500 MB, Risorse +500 MB, Fattura elettronica +500 MB.

### Pacchetti (mensile)

| Pacchetto | Prezzo |
|---|---|
| **Area soci e pagamenti** | stessi scaglioni della base: 5 € fissi fino a 50 soci, poi 0,06 € · 0,04 € · 0,02 € a socio (150 soci: 11 €; 400: 21 €; 1.000: 33 €) |
| **Risorse orarie, Web** (prenotabili online dai soci) | fino a 2: 19 € · fino a 5: 29 € · fino a 10: 45 € · oltre: 69 € |
| **Risorse giornaliere, Web** | fino a 10: 19 € · fino a 25: 29 € · fino a 50: 45 € · oltre: 69 € |
| **Risorse stagionali, Web** | fino a 50: 19 € · fino a 150: 35 € · fino a 400: 59 € · oltre: su preventivo |
| **Versione Interna** di ciascuna (prenota solo l'operatore) | 50% della Web |
| **Sito** | 19 € |
| **Fattura elettronica** (50 fatture emesse all'anno) | 7 € |
| **Comunicazioni** | 9 €, SMS a consumo |
| **Contabilità avanzata** | 19 € |
| **Vita associativa** | 7 € |
| **Sport** | 12 € |
**Sale del bar e del ristorante.** Non gestiamo il bar né la somministrazione: non ci riguarda. Le sale e gli spazi
del bar o del ristorante (per esempio un aperitivo con sala riservata) sono **risorse giornaliere**, con
eventuali **servizi aggiuntivi alla prenotazione** (aperitivo, buffet, allestimento) a prezzo deciso dal circolo, che
finiscono nell'incasso della prenotazione.

**Versione Web e versione Interna.** Una risorsa prenotabile online è un servizio diverso da una risorsa che prenota
solo l'operatore: più carico, più valore per il circolo. Le Risorse esistono quindi in due versioni: **Web** al prezzo
di listino, **Interna** al **50%**. La versione Web richiede l'Area soci (accesso e pagamento online). Si può passare
dall'una all'altra in qualsiasi momento.

### Extra e consumi

| Voce | Prezzo |
|---|---|
| Operatore aggiuntivo | 3 €/mese l'uno |
| Dominio personalizzato (collegamento e certificato; registrazione a carico del cliente) | 3 €/mese |
| Database dedicato | 25 €/mese |
| Ricarica fatture elettroniche (non scadono) | 50 fatture 15 € · 200 fatture 40 € |
| SMS | circa 0,10 € l'uno, da tarare sul costo del fornitore |
| Rigenerazioni del sito oltre quelle incluse | 10 rigenerazioni 5 € |
| Spazio file oltre quello incluso | 10 GB 3 €/mese |

### Sconti per combinazione di pacchetti

Gli sconti valgono **sui pacchetti**, non sulla base (che scala già con gli scaglioni). Si applica lo sconto più
favorevole, senza cumulo con gli altri.

| Combinazione | Sconto sui pacchetti coinvolti |
|---|---|
| **Trio web**: Sito + Area soci + Risorse Web | **50%** |
| Sito + Area soci | 25% |
| 2 pacchetti qualsiasi | 15% |
| 3 pacchetti qualsiasi | 25% |
| 4 o più pacchetti | 30% |

Il trio web premia chi porta online tutta la vita del circolo: con le risorse già Web, aggiungere il sito costa quasi
nulla.

### Bundle: il terzo pacchetto quasi in regalo

Ancora più convenienti degli sconti per combinazione: **con poco più di due pacchetti se ne prendono tre**. Così il
circolo gestisce con noi anche ciò che altrimenti terrebbe separato (la fattura elettronica per gli affitti, le
comunicazioni…).

**Regola**: i due pacchetti più cari del bundle con lo sconto per combinazione di due (15%, o 25% se sono Sito + Area
soci), **il terzo a 5 €/mese** qualunque sia il suo listino. Il sistema applica sempre il calcolo più favorevole al
cliente tra bundle, trio web (50%) e sconti per combinazione: chi sceglie un bundle non paga mai più del 25% sui tre
pacchetti.

| Bundle | Pacchetti | Per chi |
|---|---|---|
| **Circolo sportivo** | Area soci + Risorse orarie Web + **Fattura elettronica** | tennis, padel, calcetto |
| **Circolo nautico** | Area soci + Risorse stagionali + **Fattura elettronica** | circoli nautici e balneari |
| **Associazione online** | Area soci + Sito + **Comunicazioni** | APS, ODV, culturali |
| **Sportiva** | Area soci + Sport + **Comunicazioni** | ASD e SSD |

Esempio: circolo tennis, 250 soci, 4 campi prenotabili online (risorse orarie, scaglione fino a 5). La sala del bar
si aggiungerebbe come risorsa giornaliera.

| | Area soci | Risorse Web | Comunicazioni | Pacchetti | Base | **Totale** |
|---|---|---|---|---|---|---|
| Listino | 15 € | 29 € | 9 € | 53 € | 45 € | 98 € |
| Tre pacchetti con il 25% | | | | 39,75 € | 45 € | 84,75 € |
| **Bundle con Comunicazioni come terzo** | (15 + 29) − 15% = 37,40 € | | **5 €** | **42,40 €** | 45 € | **87,40 €** |
| Solo i primi due, con il 15% | | | | 37,40 € | 45 € | 82,40 € |

Con la Fattura elettronica (7 €) come terzo pacchetto il bundle costa uguale: 87,40 €. **Il terzo pacchetto entra
sempre per 5 € in più**, e conviene soprattutto quando il suo listino è alto: con un terzo pacchetto da 19 € (Sito,
Contabilità avanzata) il risparmio rispetto al 25% è di circa 5–7 €. Le offerte già composte (Associazione, Associazione con sito, Sportiva, Circolo) restano come **combinazioni
proposte** in vetrina, con questi sconti già applicati.

| Profilo | Composizione | Listino | Con sconto |
|---|---|---|---|
| APS, 40 soci | base + Area soci | 20 € | **20 €** (un solo pacchetto) |
| APS, 120 soci | base + Area soci + Sito | 55,80 € | **48,90 €** (25% su Area soci e Sito) |
| ASD, 300 soci | base + Area soci + Sport | 80 € | **75,35 €** (15% su due pacchetti) |
| Circolo tennis, 250 soci, 4 campi prenotabili online | base + Area soci + Risorse Web + Fattura elettronica | 96 € | **83,25 €** (25% su tre pacchetti) |
| Lo stesso circolo **con il sito** | + Sito: scatta il trio web | 115 € | **83,50 €** (50% sul trio, Fattura elettronica piena) |
| Circolo nautico, 200 soci, 120 posti barca gestiti in segreteria | base + Area soci + Risorse stagionali Interna + Fattura elettronica | 76,50 € | **67,13 €** (25% su tre pacchetti) |

Per confronto: Harba costa circa 275 €/mese per un circolo con 120 posti barca (solo posti barca); Playtomic
59–279 € più il 4% sulle prenotazioni (solo campi).

### Plugin

| Piano | Mensile | Annuale |
|---|---|---|
| Gratuito | 0 € | 0 € |
| PRO Contabilità | 18 € | 180 € |
| PRO Contabilità & Fiscalità | 29 € | 290 € |
| Addon Fattura elettronica (tramite portale; 50 fatture all'anno) | **12 €** | 120 €, più le stesse ricariche del SaaS |

**Prezzo piatto, senza limite di soci**: il plugin non comunica il numero di soci e il readme resta com'è. La
differenza con il SaaS si gioca sull'**assistenza** (sotto), non sul prezzo: le grandi associazioni non hanno problemi
di budget ma di sicurezza, solidità e assistenza, e il plugin paga meno perché ci impegna molto meno.

La fattura elettronica costa di più sul plugin (12 € contro 7 €): stesso costo per noi, ma i ticket sugli scarti
arrivano da installazioni che non controlliamo. Nel SaaS resta bassa perché è il terzo pacchetto dei bundle dei circoli.

Da sistemare: il sito pubblico usa "PRO Contabilità" e "PRO Contabilità & Fiscalità", la tabella di confronto
"Pro Contabile" e "Pro Fiscale": serve un nome unico, da usare anche nel SaaS.

### Assistenza

| | **SaaS** | **Plugin Pro** | **Plugin gratuito** |
|---|---|---|---|
| Presa in carico del ticket | **entro 24 ore lavorative** | entro 48 ore lavorative | nessuna garanzia (guide, FAQ) |
| Problemi tecnici | ✓ | ✓ | — |
| Assistenza sulle funzioni ("come registro…", "come chiudo l'anno…") | ✓ inclusa | a pagamento | a pagamento |
| Configurazione iniziale | guidata, inclusa | a pagamento | a pagamento |
| Sicurezza, backup, aggiornamenti | nostri | del cliente | del cliente |
| Coda dei ticket | prioritaria | normale | — |

Nelle condizioni:
1. **Presa in carico, non risoluzione**: entro il termine rispondiamo e iniziamo a lavorare.
2. **Ore lavorative**: dal lunedì al venerdì, festivi esclusi, in un orario dichiarato (per esempio 9–18).
3. **Un guasto della piattaforma SaaS non è un ticket**: lo vediamo dal monitoraggio e lo comunichiamo su una pagina
   di stato.

**Servizi a pagamento** (per il plugin, e per il SaaS oltre quanto incluso)

| Servizio | Prezzo |
|---|---|
| Configurazione iniziale del plugin (impostazioni, conti, categorie, tipi di socio, primo import) | 149 € una tantum |
| Ora di assistenza sulle funzioni o di formazione (anche online) | 49 € |
| Pacchetto da 5 ore, valido 12 mesi | 199 € |
| Import dati assistito da Excel o da altri gestionali | su preventivo |

Il sito di supporto deve conoscere il piano del cliente per ordinare i ticket e misurare i tempi: il portale lo
aggiunge ai dati che già passa con l'accesso unico (lavoro delle sessioni del portale e del supporto).

### Programma partner

- **15%** ricorrente su tutto ciò che fatturiamo all'associazione; **20%** oltre i 10 clienti attivi. Vale per SaaS e
  licenze del plugin.
- **Soglia di liquidazione**: 15 € × 24 mesi × 15% = 54 € → **55 €**.

## 8. Domande aperte

1. Programma partner: percentuale ed eventuali scaglioni (§5.2).

Decise: fattura elettronica come primo addon dopo il lancio (§5.1); **Sito e Area soci sono due pacchetti separati**,
perché l'area soci non serve a tutti; **3 operatori inclusi** più aggiuntivi a pagamento (§3); programma partner
aperto a tutti (§5.2).
