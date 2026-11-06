<?php

// Guide aggiunte con i rilasci del plugin (1.2.20 e successivi).
return [
    [
        'topic' => 'installazione', 'section' => 'Manutenzione', 'slug' => 'bacheca-da-lavorare-ed-eventi-della-settimana',
        'title' => 'Usare la Bacheca: cosa c\'è da lavorare e gli eventi della settimana',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Appena entri nella **Bacheca di WordPress** (la pagina iniziale di `wp-admin`) trovi due riquadri con ciò che ti serve oggi. Li vedono gli amministratori, la segreteria e i consiglieri con accesso operativo.

## Da lavorare

Il riquadro elenca le cose che aspettano te:

- **richieste di accesso** da controllare (i soci che hanno chiesto di entrare);
- **soci da rinnovare**, con tessera scaduta o in scadenza;
- **ospiti da invitare** a iscriversi;
- **schede da completare**: soci in carica a cui mancano dei dati (l'avviso compare anche nella Bacheca di Associazione Semplice, con l'elenco e il contatore). Per ogni scheda il plugin controlla codice fiscale, data e luogo di nascita e residenza (indirizzo **e** comune). Per un **minorenne** serve almeno un genitore o tutore, con l'email del tutore che opera sul sito e il codice fiscale di ciascun genitore; per un maggiorenne (o senza data di nascita) serve almeno **email o telefono**. L'elenco mostra le voci mancanti scheda per scheda. Ospiti, genitori non soci, soci receduti o anonimizzati e domande in attesa non sono controllati, e la liberatoria non conta perché è facoltativa;
- **email non partite** (solo per l'amministratore).

Ogni voce ha un collegamento che porta alla pagina giusta. In fondo trovi il collegamento alla **Bacheca di Associazione Semplice**, con il quadro completo.

## Eventi e corsi della settimana

Il secondo riquadro mostra i **prossimi 7 giorni**, con orario, luogo e numero di prenotati. Sotto ci sono i pulsanti per le operazioni più frequenti:

- **Nuovo incasso**
- **Prenota**
- **Nuovo socio**
- **Corsi ed eventi**
- **Rubrica**
- **Prima nota**

## Se non vedi i riquadri

1. Controlla di avere un ruolo con accesso operativo (vedi [Assegnare ruoli e permessi](guida:assegnare-ruoli-e-permessi)).
2. Apri **Impostazioni schermata** in alto nella Bacheca di WordPress e verifica che i riquadri non siano nascosti.

> I riquadri compaiono solo nella Bacheca di WordPress: nelle pagine del plugin trovi invece la Bacheca di Associazione Semplice.
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Manutenzione', 'slug' => 'leggere-le-novita-di-ogni-versione',
        'title' => 'Leggere le novità di ogni versione',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Ogni plugin ha un **elenco delle novità** (il *changelog*), scritto in italiano semplice: ti dice cosa è stato aggiunto, cambiato o corretto in ogni versione.

## Dove lo trovi

- **Plugin gratuito**: nel file `CHANGELOG.md` incluso nel plugin e nella scheda dei dettagli del plugin in WordPress.
- **Associazione Semplice Pro**: nei **dettagli dell'aggiornamento** del plugin, quando WordPress segnala che c'è una nuova versione. Le voci arrivano dal portale di Associazione Semplice.

## Come aggiornare in sicurezza

1. Prima di aggiornare, leggi le novità della versione nuova.
2. Fai una [copia di sicurezza](guida:copia-di-sicurezza-e-ripristino).
3. Aggiorna il plugin da **Plugin → Plugin installati**. Il plugin gratuito e Pro hanno numeri di versione diversi, ma **vanno aggiornati insieme**: con un plugin gratuito vecchio, il Pro nuovo si sospende e mostra un avviso. Aggiorna prima il gratuito, poi Pro.
4. Controlla la Bacheca di Associazione Semplice e prova un'operazione semplice, per esempio aprire la scheda di un socio.

## Avviso degli aggiornamenti

Con una chiave di licenza inserita (anche quella gratuita), il plugin ti avvisa quando esce una versione più recente. L'avviso si può chiudere e si può spegnere dalle impostazioni.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Risorse e spazi', 'slug' => 'creare-e-gestire-le-risorse',
        'title' => 'Creare e gestire le risorse: campi, sale, posti auto e armadietti',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

Una **risorsa** è un bene che i soci possono prenotare o ricevere in uso: un campo da gioco, una sala, un posto barca, un posto auto, un armadietto. Una risorsa è **esclusiva**: o è libera o è occupata. Non c'è una capienza, perché ospita una prenotazione alla volta.

## Creare una risorsa

1. Dal menu principale di Associazione Semplice apri **Risorse** (sta tra **Corsi ed eventi** e **Crediti**).
2. Vai su **Elenco** e crea una nuova risorsa.
3. Scegli la **categoria**: campo da gioco, sala, posto barca, posto auto, armadietto o altro.
4. Scegli la modalità (vedi sotto) e compila la scheda della risorsa.
5. Salva. Ogni risorsa ha la sua scheda, dove puoi modificarla in qualsiasi momento.

## Le due modalità

- **A fasce orarie** (campi, sale): indichi l'orario di apertura e di chiusura e la durata della fascia, per esempio 60 minuti. Puoi stabilire le fasce minime e massime da prenotare di seguito, quanti giorni prima si può prenotare e le prenotazioni massime per socio.
- **Assegnata a periodo** (posto auto, armadietto, posto barca): la segreteria **assegna** la risorsa a un socio per un periodo. Se è occupata, i soci possono mettersi in **lista d'attesa**.

## Tariffe e regole

Sulla scheda della risorsa imposti anche:

- la **tariffa per fascia**, anche diversa per giorni e orari (per esempio «feriali 18:00-22:00 15,00»);
- se sono ammessi **ospiti** e l'eventuale **supplemento per ospite**;
- le regole di **annullamento**;
- il **pagamento**, anche in crediti.

Se una risorsa è da **pagare online in anticipo** ma i pagamenti online non sono attivi, resta **prenotabile** e il contributo si salda in segreteria.

## Chi può usare il menu Risorse

Lo usano gli amministratori e chi opera (segreteria e consiglieri con accesso operativo). Per i permessi vedi [Assegnare ruoli e permessi](guida:assegnare-ruoli-e-permessi).
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Risorse e spazi', 'slug' => 'prenotare-una-risorsa',
        'title' => 'Prenotare una risorsa: cosa possono fare i soci',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

Le risorse a fasce orarie si prenotano dall'**area riservata**. La prenotazione è del socio che la fa.

## Chi può prenotare

Solo i soci con:

- la **tessera valida**;
- lo stato **non sospeso**;
- il **regolamento accettato**.

Chi non è socio non può prenotare. I soci possono indicare degli **ospiti** solo dove la risorsa li ammette, con l'eventuale supplemento.

## Quando non si può prenotare

Un socio non può prenotare:

- nei periodi occupati da **corsi ed eventi** collegati alla risorsa, **tempo di allestimento compreso**;
- quando c'è un **blocco** sulla risorsa;
- oltre i limiti della risorsa (prenotazioni massime per socio, anticipo massimo, fasce minime e massime).

## Cosa fare se un socio ha un problema

1. Apri **Risorse → Elenco** e la scheda della risorsa.
2. Controlla orari, tariffe e regole.
3. Verifica il socio: tessera, sospensione e regolamento (vedi [Iscrivere un socio e rinnovare la tessera](guida:iscrivere-un-socio-e-rinnovare-la-tessera)).

Per le risorse **assegnate a periodo** (posto auto, armadietto) non c'è una prenotazione del socio: lo assegna la segreteria, con lista d'attesa.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Risorse e spazi', 'slug' => 'collegare-attivita-e-risorse-con-allestimento',
        'title' => 'Collegare un corso o un evento a una risorsa e indicare l\'allestimento',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale} (aggiorna insieme il plugin gratuito e Pro)

Quando un corso o un evento si svolge in una sala o su un campo, collegalo alla risorsa: le sue date **occupano** la risorsa e i soci non possono prenotarla in quegli orari.

## Scegliere la risorsa

1. Vai in **Corsi ed eventi** e apri l'attività.
2. Nella scheda, in **Dati dell'attività**, scegli la **risorsa**.
3. Salva.

- Un **evento deve indicare** la risorsa oppure scegliere **«Nessuna risorsa (evento fuori sede)»**.
- Per un **corso** la scelta è facoltativa.
- Si possono scegliere solo risorse **a fasce orarie**.
- Gli eventi già salvati scelgono la risorsa alla prima modifica.

Nel calendario del giorno leggi **«Attività: …»** sulla fascia occupata e **«Allestimento per: …»** sul tempo di preparazione.

## Il tempo di allestimento

Serve a lasciare libera la sala per preparare e riordinare. Ci sono due livelli, che si **sommano**:

1. quello della **risorsa**, nella sua scheda (per esempio 20 minuti prima e dopo);
2. quello dell'**attività**, nella riga **Allestimento** della scheda dell'attività.

**Esempio**: sala con 20 minuti prima e dopo, corso di yoga con 30 minuti dopo. La sala risulta indisponibile **20 minuti prima** e **50 minuti dopo**.

## Valori predefiniti

- Se non scrivi niente, un **evento** ha **3 ore prima e dopo**; un **corso** non ha allestimento.
- Scrivi **0** per indicare nessun allestimento.
- Puoi scrivere in modo naturale: «3 ore», «mezz'ora», «20 minuti», «1h30».

## Sovrapposizioni

Una risorsa non può essere occupata due volte. Il plugin lo impedisce così:

- se **colleghi** un corso o un evento a una risorsa, oppure **aggiungi o sposti** delle date che si sovrappongono a un'altra attività (**allestimenti compresi**), il salvataggio viene **rifiutato** con un messaggio che dice **con cosa si sovrappone**;
- per correggere, collega l'attività a un'altra risorsa, oppure aggiungi o sposta le date.

Le sovrapposizioni **già presenti** prima dell'aggiornamento non cambiano da sole: in Bacheca compare l'avviso **«Risorse occupate due volte»** e vanno sistemate a mano, modificando le date o la risorsa di una delle due attività.

> L'allestimento della risorsa non vale intorno alle prenotazioni dei soci: vale solo per le attività.
MD,
    ],
];
