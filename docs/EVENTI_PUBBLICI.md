# Eventi pubblici sul sito (come The Events Calendar)

Bozza di progetto del plugin, 11/11/2026. Decisioni della titolare: **tipo di contenuto WordPress «Evento»**, **gratuito**, prima versione con **scheda completa, elenco a card + calendario mensile, categorie e organizzatore, condivisione**.

## Il problema

Oggi un evento ha nome, date, luogo, prezzo e una nota. Sul sito compare come una riga di testo (`[associazionesemplice_prossimi_eventi]`, `[associazionesemplice_attivita]`). Mancano: locandina, descrizione, categorie, organizzatore, una pagina propria da condividere, un calendario guardabile.

## Idea

Ogni evento che si vuole pubblicare ha un **contenuto WordPress** (tipo `asem_evento`) collegato all'attività. Le due parti hanno compiti separati:

| Dove | Cosa | Perché lì |
|---|---|---|
| Attività (tabelle del plugin) | date e orari, posti, prezzi, prenotazioni, incassi, luogo | è la fonte di verità per la gestione: non si duplica |
| Contenuto «Evento» (WordPress) | titolo pubblico, descrizione formattata, **locandina** (immagine in evidenza), categorie, organizzatore, galleria, indirizzo (slug) | testi e immagini si scrivono con l'editor che l'associazione conosce; i plugin SEO, il tema e Elementor lavorano da soli |

Il legame è il campo nascosto `_asem_activity_id`. **Date, posti e prezzo non si copiano nel contenuto**: la pagina li legge dall'attività a ogni visualizzazione (nessun doppione da allineare). Titolo e luogo si propongono dall'attività alla creazione e poi si possono rifinire.

## Come si pubblica

- Nella scheda dell'attività (solo eventi): riquadro **«Sul sito»** con la casella *Pubblica sul sito* e il pulsante *Modifica la pagina* (apre l'editor del contenuto Evento).
- Spuntando la casella si crea (o si riprende) il contenuto in bozza con titolo e luogo; pubblicarlo dal pulsante dell'editor.
- Evento annullato o eliminato: la pagina passa in bozza (mai cancellata in silenzio) e l'elenco non lo mostra.
- Eventi ricorrenti: **una sola pagina per la serie**, con l'elenco delle prossime date (ciascuna prenotabile); l'elenco e il calendario mostrano le singole date.

## Pagine

1. **Scheda evento** `/eventi/nome-evento/`: locandina, titolo, date e orari (con «aggiungi a Google/Apple Calendar», file `.ics`), luogo con mappa (collegamento a OpenStreetMap, nessuna chiave né richiesta a terzi), prezzo (e prezzi per socio/ospite, se ci sono), posti rimasti, pulsante **Prenota** (usa il modulo già esistente), descrizione, galleria, organizzatore, categorie, eventi collegati, pulsanti di condivisione (collegamenti, senza script esterni).
2. **Elenco** `/eventi/`: card con locandina, data in evidenza, titolo, luogo, prezzo; filtri per categoria e periodo; paginazione.
3. **Calendario mensile**: vista a mese con gli eventi nei giorni, su telefono diventa elenco per giorno; stessi filtri.
4. Shortcode e blocchi: `[associazionesemplice_eventi vista="elenco|mese|card" categoria="…" limite="…"]` e blocco/widget equivalente; i vecchi shortcode continuano a funzionare e usano le nuove card.

## Per i motori di ricerca e i social

- Dati strutturati **schema.org `Event`** in JSON-LD (nome, date, luogo, immagine, prezzo, stato di annullamento, disponibilità).
- Anteprima social (titolo, descrizione, locandina) se un plugin SEO non la fornisce già.
- Indirizzi puliti e modificabili dalle Impostazioni (base `eventi`).

## Regole da rispettare

- **WordPress.org**: nomi con prefisso (`asem_evento`, `asem_evento_cat`), nessuna richiesta a siti esterni, nessuno script esterno, testi escapati, Plugin Check a zero avvisi (free e Pro).
- **Privacy**: la pagina pubblica non mostra mai dati di persone (iscritti, prenotati). I posti rimasti sono un numero.
- **Disinstallazione**: i contenuti Evento restano come per gli altri dati (scelta già presente in Impostazioni).
- **Vecchia grafica**: la scheda e l'elenco usano solo il nuovo stile (Montserrat e icone già incluse).
- **Pro**: i pagamenti e i biglietti QR restano nel Pro; la scheda mostra il pulsante giusto in base a cosa è attivo.

## Fasi di sviluppo (un rilascio può contenerne più di una; versione solo alla pubblicazione)

1. **Fondamenta**: tipo di contenuto, categorie, legame con l'attività, riquadro «Sul sito», scheda evento (locandina, date, luogo, prezzo, Prenota, descrizione), `.ics`, dati strutturati.
2. **Elenco a card** + shortcode `[associazionesemplice_eventi]` + blocco/widget; i vecchi shortcode passano alle card.
3. **Calendario mensile** e filtri (categoria, periodo).
4. **Condivisione e collegati**: anteprima social, pulsanti, eventi correlati, galleria; pagina «Eventi» creata dalla procedura guidata.
5. **Rifiniture**: ricorrenze (pagina di serie), eventi annullati, test su tema classico, tema a blocchi ed Elementor; guide del supporto e tabella di confronto.

## Da decidere con la titolare

- Luogo con mappa: bastano collegamento e indirizzo (nessuna mappa incorporata, per privacy e WordPress.org)? Proposta: sì.
- Categorie: libere (le crea l'associazione) con alcune di partenza? Proposta: libere, nessuna di partenza.
- Eventi a pagamento: il pulsante Prenota porta al modulo attuale (area soci per i soci, modulo ospite per gli altri)? Proposta: sì, come oggi.
- Immagine della locandina: dimensioni consigliate e ritaglio per card e anteprime social (proposta: verticale 2:3 o orizzontale 16:9 a scelta, mostrata intera nella scheda).

## Riferimento e prenotazione online (richiesta della titolare, 11/11/2026)

**Modello da replicare:** il calendario di un'associazione con The Events Calendar (esempio: sognaragazzosogna.it/calendario-attivita). Elenco **per mese**, ogni riga con **badge della data**, categoria, titolo, luogo, estratto, prezzo («€15», «gratuito») e pulsante verso la scheda. Scheda con data e orario, contributo, testo, informazioni di contatto, «Salva nel tuo calendario», luogo con mappa, precedente/successivo. L'esempio prenota solo per telefono; qui la scheda deve **prenotare online**.

**Prenotazione dalla scheda: la modalità dipende da come è configurato il sito.**

| Situazione del sito | Cosa vede chi prenota |
|---|---|
| Evento senza prenotazione | solo informazioni e contatti |
| Prenotazione gratuita o contributo da versare sul posto/con bonifico (gratuito o Pro senza pagamenti online) | **Prenota**: conferma subito, posti e lista d'attesa come oggi; il contributo risulta «da versare» e si incassa come adesso |
| Pagamenti online configurati (Pro: Stripe, PayPal, SumUp) e l'evento ha un contributo | **Prenota e paga**: la prenotazione resta in attesa fino al pagamento (si usa la finestra di blocco già esistente, `hold_hours`) e poi si conferma |

Stati del pulsante: Prenota · Prenota e paga · Entra in lista d'attesa · Esaurito · Prenotazioni chiuse · Già prenotato (con «Vedi/annulla» dall'area soci). I posti si controllano sotto blocco, come oggi.

**Chi può prenotare** (da decidere, vedi sotto): il socio con accesso prenota con un clic dalla scheda (stesso modulo e regole dell'area soci: cancellazione, cambio nominativo, biglietto QR). Chi non è socio, se gli ospiti sono attivi, compila un modulo breve (nome, email, telefono, consensi, accompagnatori) e conferma dall'email; se gli ospiti non sono attivi trova il collegamento «Richiedi l'ammissione come socio».

**Protezioni** della prenotazione pubblica: conferma dall'email prima di occupare il posto, limite di richieste per indirizzo, campo trappola e controllo del tempo di compilazione (niente CAPTCHA esterni), nessun dato di altri visibile, informativa privacy collegata.

**Impatto sulla fase 1:** la scheda mostra il pulsante giusto leggendo la configurazione; la prenotazione da non socio e il pagamento online arrivano in una fase propria (dopo scheda ed elenco), perché toccano ospiti, pagamenti e antispam.

**Da decidere in più:** la prenotazione di chi non è socio è prevista subito o solo dai soci in questa versione?
