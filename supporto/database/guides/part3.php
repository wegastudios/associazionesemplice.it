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
6. Salva. Dalla versione 1.2.41 del plugin gratuito, se devi inserire più corsi di seguito, premi **«Salva e prossimo inserimento»**: salva questo e ti apre un nuovo modulo.

Per creare un'attività simile a una già fatta, nell'**elenco delle attività** usa il collegamento **«Riproponi»** accanto a quella da copiare.

## Come funzionano i pagamenti

- **Rinnovo mensile**: il corso si rinnova da solo ogni mese finché l'iscritto non viene «disdetto». La mensilità è dovuta dalla prima lezione del mese; prima risulta «in arrivo», poi «da pagare». Senza giorno indicato è dovuta dal 1°.
- **Pagamento unico**: la quota è il totale (per esempio «10 incontri a 120 €»), dovuta all'iscrizione e versabile anche a rate. Se l'iscrizione viene cancellata prima di qualunque pagamento, non è dovuto nulla.

## Gli iscritti

Nella scheda del corso gli iscritti stanno sotto il modulo di iscrizione. Accanto a chi deve versare c'è **Paga**, che apre l'incasso già compilato. Iscrivendo una persona puoi premere **«Iscrivi e vai alla cassa»** (dalla versione 1.2.41): la iscrive e ti porta direttamente all'incasso. Per togliere chi non è più iscritto usa **Togli dall'elenco** (solo chi non ha incassi registrati).
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
