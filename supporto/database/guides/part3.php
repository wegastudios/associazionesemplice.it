<?php

// Guide: corsi ed eventi.
return [
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'creare-un-corso',
        'title' => 'Creare un corso',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Un corso ha lezioni che si ripetono ogni settimana. Gli iscritti pagano **ogni mese** (rinnovo mensile) oppure **una volta sola** (pagamento unico).

## Cosa fare

1. Vai in **Corsi ed eventi** e crea una nuova attività di tipo **corso**.
2. Indica nome, luogo e referente.
3. Costruisci il programma: ogni riga ha **giorno e orario**. Con la spunta *ricorrente* la lezione si ripete ogni settimana (puoi anche scegliere *tutti i giorni* o *lun-ven*). Puoi aggiungere più righe, per esempio lunedì alle 20 e giovedì alle 19.
4. Imposta le **date di inizio e fine**: con la data di fine le mensilità non sono più dovute dopo quel mese. Le date devono cadere nello stesso anno sociale.
5. Scegli **come si paga** e il contributo.
6. Salva.

## Riproporre un'attività

Per una nuova edizione di un corso o di un evento già fatto, nell'**elenco delle attività** usa il collegamento **«Riproponi»**: apre la sezione **«Riproponi»** della scheda dell'attività (dalla versione 1.2.41 del plugin gratuito). Crea una **nuova edizione** con gli stessi dati: contributi, referente, regole, note, luogo e staff. **Iscrizioni, prenotazioni e incassi non si copiano**: la nuova attività parte vuota.

## Come funzionano i pagamenti

- **Rinnovo mensile**: il corso si rinnova da solo ogni mese finché l'iscritto non viene «disdetto». La mensilità è dovuta dalla prima lezione del mese; prima risulta «in arrivo», poi «da pagare». Senza giorno indicato è dovuta dal 1°.
- **Pagamento unico**: la quota è il totale (per esempio «10 incontri a 120 €»), dovuta all'iscrizione e versabile anche a rate. Se l'iscrizione viene cancellata prima di qualunque pagamento, non è dovuto nulla.
- **Pagamento trimestrale** (dalla versione 1.2.43 del plugin gratuito): oltre a «una tantum» e «rinnovo mensile» puoi scegliere il pagamento del corso a trimestre. I **promemoria mensili** saltano i corsi trimestrali.

## Varianti del contributo

Dalla versione 1.2.44 del plugin gratuito puoi descrivere liberamente delle **varianti del contributo** di un **corso** (non degli eventi), per esempio «Secondo corso -25%», «Famiglia» o «Solo il sabato», e assegnarle a mano agli iscritti. Sono facoltative. **Gli sconti non sono automatici**: la variante è un prezzo alternativo che scegli tu.

### Creare le varianti

1. Apri il corso da **Corsi ed eventi** e vai nella scheda **«Dati dell'attività»**.
2. Nella riga **«Varianti del contributo»** compila **Variante** (un nome libero, fino a 60 caratteri) e **Prezzo** in euro.
3. Parti con tre righe; a ogni salvataggio se ne aggiunge una vuota. Puoi averne **al massimo 8**. Le righe senza nome vengono ignorate.
4. Salva.

Il prezzo vale per **ogni periodo di pagamento** del corso: il mese, il trimestre o l'importo unico, a seconda del tipo di pagamento.

### Assegnare una variante a un iscritto

1. Nel modulo **«Iscrivi un socio o un ospite»**, se il corso ha varianti compare **«Contributo»**: un menu con **«Quota base»** e le varianti.
2. Scegli la voce giusta e, se vuoi, scrivi una **nota** (fino a 190 caratteri, per esempio «sorella iscritta a Danza»).
3. Iscrivi la persona.

Dopo, nell'elenco degli iscritti, accanto a ogni persona trovi lo stesso menu con la nota e il pulsante **Salva**: puoi **cambiare la variante quando vuoi**. Nell'elenco leggi «· Nome variante» e la nota tra parentesi. Il cambio resta nel registro azioni.

### Cosa succede alla quota

- La quota dell'iscritto segue la variante in **«Pagamenti da incassare»**, nell'incasso già compilato (l'importo proposto) e nei pagamenti online del socio.
- Chi si iscrive da solo dall'**area soci** paga sempre la **quota base**. L'iscritto non vede il nome della variante, vede solo l'importo che deve; il nome lo vede la segreteria.
- L'incasso resta una normale quota del corso per il mese di competenza, senza il nome della variante.

> **Se cambi variante**, il dovuto si **ricalcola per tutta l'iscrizione** con il prezzo della nuova variante, anche per i mesi già passati. I versamenti già registrati restano come sono e il saldo è «dovuto meno versato»: con un prezzo più basso può comparire un credito, con uno più alto una differenza da incassare. Nei corsi a rinnovo mensile o trimestrale ogni periodo successivo usa il nuovo prezzo.

## Annullare o spostare una lezione

Dalla versione 1.2.43 le **singole lezioni** di un corso si possono **annullare** o **spostare**, per esempio per una festività o per il maltempo.

1. Vai in **Corsi ed eventi** e apri il corso.
2. Apri la scheda **«Date delle lezioni»**: compaiono le lezioni dei **prossimi 60 giorni**, ciascuna con data e ora. Se non ce ne sono, leggi «Nessuna lezione nei prossimi 60 giorni».
3. Se vuoi, scrivi il **Motivo** (per esempio festività o maltempo).
4. Lascia la spunta **«avvisa gli iscritti»**, attiva di partenza, se vuoi che ricevano un avviso.
5. Scegli come procedere:
   - **Annulla la lezione**: premi il pulsante;
   - **Sposta**: indica la nuova data in **«Sposta a»** (l'ora di inizio e di fine sono facoltative) e premi **Sposta**.

Con la spunta gli iscritti ricevono un avviso come «La lezione di … del … è annullata» o «è spostata al …». L'avviso parte solo se chi opera può mandare avvisi per quel corso: se non parte, l'annullamento o lo spostamento resta comunque e il messaggio te lo dice.

Sotto trovi **«Lezioni cambiate»**, con quelle annullate o spostate, il motivo e il pulsante **Ripristina**, che rimette la lezione com'era. **Le quote non cambiano**. Calendario, presenze e promemoria seguono le date nuove.

## Gli iscritti

Nella scheda del corso gli iscritti stanno sotto il modulo di iscrizione. Accanto a chi deve versare c'è **Paga**, che apre l'incasso già compilato. Dalla versione 1.2.41, nel modulo di iscrizione hai due pulsanti in più: **«Salva e prossimo inserimento»** iscrive la persona e resta sulla stessa pagina, pronta per iscrivere quella dopo; **«Iscrivi e vai alla cassa»** la iscrive e apre l'incasso già compilato, se c'è qualcosa da pagare (altrimenti resta lì). Per togliere chi non è più iscritto usa **Togli dall'elenco** (solo chi non ha incassi registrati).
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'creare-un-evento',
        'title' => 'Creare un evento',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Un evento ha una o più date e, se vuoi, posti limitati e un contributo.

## Cosa fare

1. Vai in **Corsi ed eventi** e crea una nuova attività di tipo **evento**.
2. Indica nome, luogo e referente.
3. Costruisci il **programma**:
   - **Data unica**: giorno, ora di inizio e di fine (per esempio *20 settembre dalle 15 alle 20*).
   - **Giorni ricorrenti**: giorni della settimana, orario e fino a quando (per esempio *tutti i martedì e venerdì dalle 19 alle 20 fino al 31 luglio*).
4. Se serve, imposta i **posti** e il **contributo**.
5. Salva.

## Regole utili

- Tutte le date di un evento devono cadere nell'**anno sociale** dell'attività, altrimenti il salvataggio viene rifiutato e non resta nulla a metà.
- Le date già presenti non si duplicano.
- Per più date, usa l'evento ricorrente.

## Chi lo gestisce

Nella scheda c'è **Gestori dell'evento**: i soci o volontari indicati possono, dall'area riservata, vedere i prenotati e registrare gli ingressi solo per quell'evento (vedi [Registrare gli ingressi a un evento](guida:registrare-gli-ingressi)).

## Cancellare un evento

Solo gli amministratori possono eliminare un evento, dal pulsante **Elimina l'evento** nella scheda. Se ci sono incassi la procedura chiede cosa fare delle somme (restituirle o annullarle) e una doppia conferma; se non ci sono, basta una conferma semplice.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'lista-d-attesa-e-tolleranza-sul-pagamento',
        'title' => 'Posti limitati, lista d\'attesa e tolleranza sul pagamento',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

## Lista d'attesa

Quando i posti sono finiti, l'evento mostra **«Posti esauriti · lista d'attesa»**. Il socio (o chi ospita) può mettere sé stesso o un ospite in coda dall'elenco eventi, vede la posizione e può uscire dalla lista.

- Se qualcuno annulla, o se aumenti i posti, entra automaticamente il primo della lista e riceve una email.
- Chi nel frattempo ha la tessera scaduta, è sospeso o non ha accettato il regolamento viene saltato.
- La coda è visibile e modificabile nella scheda dell'evento.

## Tolleranza sul pagamento

Per gli eventi con posti limitati e un contributo puoi indicare, alla creazione, quante **ore** ha chi prenota per pagare con bonifico o contanti (da 0, nessun limite, a 720).

1. Nella scheda dell'evento, imposta le ore di tolleranza.
2. Salva.

Scaduto il termine senza alcun pagamento, il posto si libera, la persona riceve una email e subentra il primo della lista d'attesa. Il controllo gira ogni ora e a ogni nuova prenotazione.

> Non si libera mai il posto di chi ha già versato (anche in parte), di chi sta pagando online, di chi ha già l'ingresso registrato o di una data passata.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'calendario-e-google-calendar',
        'title' => 'Calendario del sito e Google Calendar',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

## Il calendario per i soci

Lo shortcode `[associazionesemplice_calendario]` (o il blocco o widget «Calendario») mostra il mese con lezioni ed eventi e mette in evidenza le attività del socio. È anche una sezione dell'area soci.

## Il calendario interno

In **Strumenti → Calendari** trovi il calendario di tutte le attività. Quello della singola attività è nella sua scheda. Il calendario è raggiungibile anche dalla Bacheca.

## Pubblicarlo su Google Calendar

1. In **Corsi ed eventi › Calendario** attiva la pubblicazione: viene creato un **indirizzo segreto**, in sola lettura, che contiene solo nomi, giorni, orari e luoghi.
2. In Google Calendar apri **Altri calendari › Da URL** e incolla l'indirizzo.
3. Lo stesso indirizzo funziona con Apple Calendar e Outlook.

L'indirizzo è spento di default e si può rigenerare quando vuoi: rigenerandolo, quello vecchio smette di funzionare.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'listini-e-pacchetti-per-eventi-e-corsi',
        'title' => 'Listini e pacchetti di ingressi per eventi e corsi a date',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (dalla versione 1.2.18 del plugin)

Con i **listini** la stessa attività può avere prezzi diversi, per esempio *Individuale*, *Di gruppo* o *Pacchetto 4 ingressi*. Chi prenota sceglie il listino che gli interessa.

## Creare i listini

1. Apri la **scheda dell'attività** (evento o corso a date) da **Attività**.
2. Nella riga **Listini** aggiungi una riga per ogni listino con **nome**, **prezzo** e numero di **ingressi**.
3. Salva.

Un listino con un solo ingresso è una prenotazione normale; un listino con più ingressi è un **pacchetto**.

## Prenotare con un listino

- **Dall'amministrazione**: in **Cassa → Prenota** (o dalla scheda dell'evento) scegli la persona e il listino.
- **Dall'area riservata**: il socio vede e sceglie solo il listino, senza altre opzioni.

## Come funzionano i pacchetti

1. Il pacchetto si **paga sulla prima prenotazione**.
2. Le altre date si prenotano con **usa pacchetto**: non c'è altro da pagare.
3. Gli ingressi del pacchetto si consumano a ogni prenotazione.

> I listini non valgono per i **corsi a mensilità**: lì il contributo resta quello mensile del corso (vedi [Creare un corso](guida:creare-un-corso)).
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Ingressi e comunicazioni', 'slug' => 'registrare-gli-ingressi',
        'title' => 'Registrare gli ingressi a un evento',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (incasso sul posto: Pro)

Chi gestisce un evento può registrare chi entra direttamente dal telefono, senza accedere all'amministrazione.

## Prepara

1. Nella scheda dell'evento, in **Gestori dell'evento**, indica i soci o volontari che lo gestiscono (oltre al referente e agli amministratori).
2. Crea la pagina **Ingressi** (shortcode `[associazionesemplice_ingressi]`, già inclusa nell'area soci).
3. Se vuoi usare i QR, attiva il **biglietto QR** per l'evento (vedi [Attivare il QR della tessera e dei biglietti](guida:attivare-il-qr-della-tessera)).

## Il giorno dell'evento

1. Apri la pagina **Ingressi** dall'area riservata e scegli la data.
2. Vedi la **lista dei prenotati** con tipo (socio o ospite), contributo (versato, da versare, gratuito), ora di ingresso e i contatori. Email e telefoni non compaiono.
3. Premi **Registra ingresso** accanto alla persona. Puoi annullare la registrazione.
4. Con il QR: premi **Scansiona** (nei browser che leggono i QR) oppure usa la fotocamera del telefono, che apre la pagina del biglietto, e lì **Registra ingresso**.

Un biglietto già usato risulta «Ingresso già registrato»: una copia del QR non entra due volte. Se il contributo non è versato, lo vedi subito.

## Da sapere

- L'ingresso si registra **nel giorno dell'evento**. Gli amministratori possono farlo anche in un altro giorno dalla scheda dell'evento.
- Togliere una persona dai gestori la esclude subito.
- Con lo **staff dell'evento** abilitato, si può anche incassare il biglietto sul posto (funzione Pro).
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Ingressi e comunicazioni', 'slug' => 'inviare-un-avviso-agli-iscritti',
        'title' => 'Inviare un avviso agli iscritti di un\'attività',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Chi tiene un'attività (referente), chi gestisce un evento e gli amministratori possono inviare un avviso a tutti gli iscritti di quell'attività.

## Cosa fare

1. Apri l'**area volontari** e trova l'attività.
2. Nel modulo **Invia un avviso agli iscritti** scrivi il titolo (fino a 120 caratteri) e il testo (fino a 2000).
3. Per un evento puoi inviarlo a tutte le prossime date o a una sola, dal modulo sotto la lista dei prenotati.
4. Premi **Invia**.

## Chi lo riceve e come

- Arriva **per email a ciascun iscritto**, singolarmente: nessuno vede gli indirizzi degli altri.
- Resta anche nella **bacheca «Avvisi»** dell'area riservata per 45 giorni.
- Gli ospiti senza email ricevono l'avviso tramite il socio che li ospita.

## Limiti

- Al massimo 5 avvisi al giorno per attività.
- Per i corsi sono considerati gli iscritti attivi; per gli eventi, i prenotati delle date non ancora passate.

> Per scrivere a un gruppo più ampio (per esempio tutti i soci in regola) usa le [Comunicazioni a gruppi](guida:comunicazioni-a-gruppi).
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Ingressi e comunicazioni', 'slug' => 'comunicazioni-a-gruppi',
        'title' => 'Scrivere a un gruppo di soci con le Comunicazioni',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

Le Comunicazioni inviano un messaggio email a un gruppo, con anteprima dei destinatari e storico degli invii. Sono disponibili anche alla segreteria.

## Cosa fare

1. Vai in **Rubrica → Comunicazioni**.
2. Scegli il **gruppo**: soci in regola, tutti i soci, tessera in scadenza o scaduta, soci e volontari, consiglio direttivo, ospiti, chi non ha accettato il regolamento, iscritti a un corso o a un evento, prenotati a una data.
3. Scrivi oggetto e testo. Puoi usare `{nome}` e `{associazione}` per personalizzare.
4. Guarda l'**anteprima dei destinatari** (e di chi non ha email).
5. Fai una **prova solo a te**.
6. Premi **Invia**.

## Come partono

Ognuno riceve la **propria** email: nessun indirizzo è visibile agli altri. I primi 25 partono subito, il resto continua in background (circa 25 al minuto). Gli ospiti senza email ricevono tramite il socio che li ospita.

## Dopo l'invio

Lo **storico** mostra chi l'ha ricevuta e chi no, con il pulsante **Riprova le non partite**. Se i dispositivi dei soci hanno le notifiche attive, il messaggio arriva anche come notifica.

> C'è un limite di 20 comunicazioni nelle 24 ore, per proteggere la reputazione del tuo indirizzo di posta.
MD,
    ],
];
