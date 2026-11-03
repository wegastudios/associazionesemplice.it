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
- più operatori, con verifica in due passaggi e registro delle azioni;
- ruoli pronti: amministratore, tesoriere, segreteria, revisore in sola lettura, referente di attività.

**Soci**
- anagrafica, tesseramento e quote, libro soci;
- minori e tutori, certificati medici, consensi.

**Attività come centri di costo**
- corsi ed eventi con le iscrizioni registrate dalla segreteria, per legare incassi e spese a ciascuna attività.

## 4. Pacchetti

| Pacchetto | Quando | Contenuto |
|---|---|---|
| **Area soci e pagamenti online** | al lancio | tessera QR, profilo, iscrizioni e pagamenti online (Stripe, PayPal), PWA |
| **Database dedicato** | al lancio | database esclusivo dell'associazione, con chiave e backup propri |
| **Sito** | fase 3 | sito generato dall'AI, dominio di terzo livello incluso, area soci compresa; il cliente controlla solo testi e allegati |
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

## 6. Interventi successivi

- Biglietteria e controllo degli ingressi.
- Gestione delle risorse e prenotazione degli spazi.
- Assemblee, votazioni e verbali; registro presenze e assicurazioni.
- Affiliazioni, convenzioni, tessera nei wallet, 5×1000.
- Migrazione bidirezionale con il plugin (ultimo passo).

## 7. Pilota e demo

- 3–5 associazioni vere nella prima fase, scelte tra chi usa già il plugin.
- Demo SaaS a sé: ogni visitatore riceve un'associazione di prova con dati finti, creata al volo e cancellata dopo
  qualche giorno. La demo attuale del plugin non si tocca.
- Niente piano gratuito nel SaaS, solo la prova di 30 giorni: il gratuito resta il plugin.

## 8. Domande aperte

1. **Fattura elettronica**: addon subito dopo il lancio?
2. **Area soci**: venduta da sola **e** compresa nel Sito, oppure solo dentro il Sito?
3. **Prezzo**: oltre alla fascia di soci, un limite di **operatori** per fascia (come TeamSystem e AssoFacile)?
