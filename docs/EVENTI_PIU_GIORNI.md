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

## Da decidere o da controllare
- Testi nuovi a Audit prima di ogni PR.
- Eventi di tipo «corso»: restano senza data di fine (hanno le lezioni settimanali).
- Prenotazioni con pagamento: una sola quota per il periodo (resta la regola per data).
