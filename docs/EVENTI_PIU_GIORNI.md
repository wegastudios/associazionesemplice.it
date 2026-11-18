# Eventi su più giorni (progetto, 18/11/2026)

Richiesta di Luca: un evento (esempio: una gara velica) può durare più giorni e occupare più giorni le risorse. **Non ancora scritto: solo progetto.** Nasce anche dalla domanda di Audit sugli eventi che finiscono dopo mezzanotte (21:30–01:00), che questo stesso modello risolve.

## Decisioni di Luca (18/11/2026)
- **Un evento ha date con inizio e fine**, anche su giorni diversi, e può averne più d'una («diverse date di inizio e di fine»).
- **Prenotazioni e ingressi, a scelta di chi inserisce l'evento:** o una prenotazione per tutto il periodo (l'ingresso si registra in ognuno dei giorni), o più eventi distinti con ingressi diversi (esempio: tre gare = tre eventi, ognuno con i suoi ingressi, ma le risorse occupate in un unico blocco).
- **Risorse, a scelta di chi inserisce l'evento:** o solo controllo (la data si rifiuta se la risorsa è già prenotata in uno dei giorni, con il nome del conflitto), o l'evento prenota la risorsa per tutto il periodo («blocco unico»), anche a servizio di più eventi.

## Modello (proposta)
- Ogni data (`sessions`) ha un campo facoltativo **data di fine** (`end_date`). Vuoto = finisce lo stesso giorno, come oggi: nessun dato esistente cambia.
- Regola dell'orario: se la data di fine è lo stesso giorno, la fine deve essere dopo l'inizio (come oggi). Se la data di fine è un giorno dopo, l'ora di fine può essere qualunque (es. 21:30 → 01:00 del giorno dopo). Nessuna «magia» sull'orario: la data di fine è sempre scritta.
- Schema 69 (una colonna in più, creata con l'aggiornamento; nessun dato da migrare).
- **Blocco di risorse:** funzione Pro. Dall'evento si sceglie una o più risorse e se «tenerle occupate»: crea un blocco (`Resources::block`) per il periodo, tolto se l'evento viene tolto o annullato. Con più eventi collegati allo stesso blocco, il blocco copre dal primo inizio all'ultima fine.
- **Ingressi:** si registrano da un'ora prima dell'inizio fino alla fine (e comunque nei giorni dell'evento); oggi solo «nel giorno dell'evento».

## Cosa si tocca
Validazione (Rules, ActivityService, Schedule/import), modulo dell'attività nel pannello, copia di un'attività, `.ics` (fine con la data), calendario mensile e elenco pubblico (l'evento compare in ogni giorno, «dal 12 al 14 giugno»), pagina evento, dati strutturati (`endDate`), controllo sovrapposizioni, ingressi, import da The Events Calendar (ha già date di fine), guide e changelog.

## Fasi (una PR ciascuna, nessun bump di versione)
1. Data di fine nelle date: schema, validazione, modulo, visualizzazione, `.ics`, calendario, dati strutturati, ingressi. Risolve anche 21:30–01:00.
2. Controllo risorse su tutti i giorni (solo avviso/rifiuto).
3. Blocco di risorse da evento (Pro), a scelta.
4. Guide, tabella di confronto, demo (una gara velica di tre giorni e un evento oltre mezzanotte).

## Regole precise (dopo la rilettura di Audit, 18/11/2026)

1. **In corso, non passato.** Tutto si guarda alla FINE: una data «è passata» se la sua fine (data di fine, o l'inizio se manca) è prima di oggi; «è in corso» se inizio ≤ oggi ≤ fine; «è futura» se inizia dopo oggi. Vale per: «Si è svolto…» e «Date passate» (solo date finite), il pulsante «Dettagli» al posto di «Dettagli e prenotazione» (solo date finite o non più prenotabili), il rifiuto delle prenotazioni (data finita) e la finestra dei mesi dell'elenco: una data compare in ogni mese che attraversa (una gara iniziata a maggio e finita a giugno compare nella finestra di maggio–giugno e in quella di giugno–luglio), con il distintivo «In corso» se lo è. Il calendario mensile la mostra in ognuno dei suoi giorni. Il lavoro vero: la ricerca delle date tra due giorni (`sessions_between`) passa da «data nell'intervallo» a «si sovrappone all'intervallo».
2. **Fino a quando ci si prenota (deciso dal titolare, 18/11/2026):** una data **su più giorni** (con la fine in un giorno diverso dall'inizio) si prenota online solo se inizia da domani in poi; dal giorno di inizio, e mentre è in corso, la prenota solo la segreteria. Una data **di un giorno solo** si prenota online come prima, anche il giorno stesso (non dopo che è passata). Vale per la prenotazione del socio, la lista d'attesa e la richiesta di chi non è socio; il modulo dei non soci elenca le date di un giorno anche di oggi. Messaggio per le date su più giorni: «Le prenotazioni online si chiudono il giorno prima: per questa data rivolgiti alla segreteria.»
3. **Quota.** Una data che dura 3 giorni = **una quota sola** per chi si prenota. Un evento con 3 date distinte (tre date, ciascuna con la sua fine) = la regola di oggi, **una quota per ogni data prenotata**, salvo i pacchetti e i listini già previsti.
4. **Errori di battitura.** Durata massima di una data: **31 giorni** (oltre si rifiuta con «Una data non può durare più di 31 giorni: controlla l'anno»). Oltre **7 giorni** il pannello mostra un avviso («Questa data dura N giorni: è giusto?») e chiede una spunta di conferma prima di salvare. Due date dello stesso evento non si sovrappongono.
5. **Blocco di risorse che segue l'evento (fase 3, Pro).** Il blocco non è una copia: si ricalcola a ogni richiesta, quindi segue ogni modifica, spostamento, accorciamento, annullamento o cancellazione delle date. Si sceglie dall'evento («Tieni la risorsa occupata dal primo all'ultimo giorno, senza interruzioni»). Più eventi della stessa manifestazione condividono lo stesso blocco scegliendolo dal menu «Condividi il blocco con» (collegati da un identificativo, `resource_block`, non dal nome: due blocchi con lo stesso nome restano separati; il nome, `resource_block_label`, si cambia per tutto il blocco). Il blocco va dal primo inizio all'ultima fine delle date non annullate di tutti gli eventi che ne fanno parte; togliendone uno si ricalcola, togliendo l'ultimo sparisce. Durata massima **31 giorni** («Il blocco durerebbe N giorni: al massimo 31. Togli la spunta o separa gli eventi.»); non si usa con gli eventi ricorrenti né con le attività a fasce («occupa solo dove ci sono prenotazioni»). Se una risorsa nel frattempo è stata prenotata da altri nei giorni nuovi, la modifica si rifiuta con il nome del conflitto. **Limite noto:** spostando UNA data di un evento in un blocco, il controllo include ancora la sua posizione di prima (può dare un falso conflitto con chi ha prenotato lì in mezzo); si risolve togliendo prima la prenotazione o spostando le altre date.
6. **Ricorrenze, copia, ora legale.** Una ricorrenza («tutti i venerdì 21:30–01:00») genera ogni data con lo stesso scarto in giorni (fine il giorno dopo); la copia di un'attività mantiene lo scarto in giorni, non la data di fine assoluta. Orari e date sono scritti come ora locale (come oggi): la notte del cambio dell'ora legale non crea problemi (nessuna durata calcolata in ore); nel `.ics` si indica il fuso, come oggi.
7. **Blocco di risorse: resta nel Pro** (deciso: tutto il modulo Risorse è già nel Pro, `pro-files.txt`). La data di fine, il controllo dei conflitti con le risorse e gli ingressi non dipendono da questa scelta.
8. **Gestionale SaaS.** All'unione della fase 1 si avvisa Audit, che avvisa la sessione SaaS della regola nuova (data di fine facoltativa nelle date, in corso/passato guardati alla fine).

## Da decidere o da controllare
- Testi nuovi a Audit prima di ogni PR.
- Eventi di tipo «corso»: restano senza data di fine (hanno le lezioni settimanali).
- Punti 2 e 7: decisi dal titolare il 18/11/2026.

## Stato

- **Fase 1 (18/11/2026), non ancora pubblicata:** schema 69 (`end_date` nelle date); regole (fine non prima dell'inizio, massimo 31 giorni, ora di fine libera se la fine è un altro giorno); in corso/passato guardati alla fine (pagina evento, elenco, «Date passate», biglietti, ingressi in ognuno dei giorni, elenchi del pannello); `.ics` e dati strutturati con la fine; calendario mensile e card («Dal 12 al 14 giugno», «In corso»); copia di un'attività con la stessa durata. Prenotazioni online: per le date su più giorni solo da domani in poi (dal giorno di inizio e in corso la segreteria), per quelle di un giorno come prima («Le prenotazioni online si chiudono il giorno prima: per questa data rivolgiti alla segreteria.»; sulle date, «Prenotazioni online chiuse: per questa data rivolgiti alla segreteria.»).
- **Fatto dopo la pubblicazione della 1.3.28 (18/11/2026), non ancora pubblicato:** campo «fino al» nel modulo di creazione (con la spunta «Confermo la durata» oltre 7 giorni); import da The Events Calendar con la fine; calendario del pannello, «Prossimi 7 giorni» della Bacheca e calendario dei soci con l'evento in ogni suo giorno («giorno N di M»); fase 2: le risorse sono occupate dall'inizio all'ora di fine dell'ultimo giorno (allestimenti compresi), anche oltre mezzanotte.
- **Fase 3 fatta (PR 951):** blocco condiviso per identificativo, tetto di 31 giorni. **Da fare:** fase 4 (guide, tabella di confronto, demo con la regata e un evento oltre mezzanotte). Luca vuole finire tutto e ripubblicare una volta sola: nessun bump né zip finché non è completo.
