<?php

// Guide: corsi ed eventi.
return [
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'creare-un-corso',
        'title' => 'Creare un corso',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Un corso ha lezioni che si ripetono ogni settimana. Gli iscritti pagano **ogni mese** (rinnovo mensile) oppure una volta sola (pagamento unico).

## Cosa fare

1. Vai in **Corsi ed eventi** e crea una nuova attività di tipo **corso**.
2. Indica nome, luogo e referente.
3. Costruisci il programma: ogni riga ha **giorno e orario**. Con la spunta *ricorrente* la lezione si ripete ogni settimana (puoi anche scegliere *tutti i giorni* o *lun-ven*). Puoi aggiungere più righe, per esempio lunedì alle 20 e giovedì alle 19.
4. Imposta le **date di inizio e fine**: con la data di fine le mensilità non sono più dovute dopo quel mese. Le date devono cadere nello stesso **anno delle attività**.
5. Scegli **come si paga** e il contributo.
6. Salva.

## Riproporre un'attività

Per una nuova edizione di un corso o di un evento già fatto, nell'**elenco delle attività** premi **«Riproponi»**. La nuova edizione ha gli stessi dati (contributi, referente, regole, note, luogo e staff) ma parte vuota: iscrizioni, prenotazioni e incassi non si copiano.

## Sport e disciplina

Ogni attività ha uno **sport** e una **disciplina**, entrambi **facoltativi**. Valgono per **tutti i tipi di attività**: corsi, eventi e ricorrenti.

1. Compila la riga **«Sport e disciplina»** nel modulo **«Nuova attività»** (da **Corsi ed eventi**) oppure, per un'attività esistente, nella scheda **«Dati dell'attività»**.
2. Nel menu **«Sport»** scegli dall'**elenco di sport basato sull'elenco CONI** (o lascia «— nessuno —»).
3. In **«Disciplina secondo l'ente»** scrivi a mano la disciplina dell'ente (per esempio «Ginnastica dolce»), fino a 120 caratteri.
4. Salva.

### L'elenco degli sport

L'elenco lo gestisce l'**amministratore** (non la segreteria), dal riquadro **«Sport disponibili»** in fondo all'elenco delle attività, in **Corsi ed eventi**. Puoi:

- **aggiungere** uno sport;
- **importare** un elenco, uno per riga o separati da «;»;
- **togliere** uno sport dal menu: le attività che già lo usano lo tengono;
- **ripristinare** l'elenco di base.

Un'attività accetta anche uno sport che non è nell'elenco.

Con Pro, sport e disciplina compaiono nel file per i portali degli enti: nelle colonne **Sport** e **Disciplina** trovi quelli dei corsi a cui la persona è iscritta e degli eventi a cui è prenotata, separati da «;» (vedi [Gestire le affiliazioni agli enti](guida:affiliazioni-agli-enti)).

## Come funzionano i pagamenti

- **Rinnovo mensile**: il corso si rinnova da solo ogni mese finché non disdici il rinnovo dell'iscritto. La mensilità è dovuta dalla prima lezione del mese; prima risulta «in arrivo», poi «da pagare». Senza giorno indicato è dovuta dal 1°.
- **Pagamento unico**: il contributo è il totale (per esempio «10 incontri a 120 €»), dovuto all'iscrizione e versabile anche a rate. Se elimini l'iscrizione prima di qualunque pagamento, non è dovuto nulla.
- **Pagamento trimestrale**: il contributo si paga ogni trimestre. I **promemoria mensili** saltano questi corsi.

## Varianti del contributo

Puoi descrivere liberamente delle **varianti del contributo** di un **corso** (non degli eventi), per esempio «Secondo corso -25%», «Famiglia» o «Solo il sabato», e assegnarle a mano agli iscritti. Sono facoltative. **Gli sconti non sono automatici**: la variante è un prezzo alternativo che scegli tu.

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

### Cosa succede al contributo

- Il contributo dell'iscritto segue la variante in **«Pagamenti da incassare»**, nell'incasso già compilato e nei pagamenti online del socio.
- Chi si iscrive da solo dall'area soci paga sempre la **quota base**. L'iscritto non vede il nome della variante, vede solo l'importo che deve; il nome lo vede la segreteria.
- L'incasso resta una normale contributo del corso per il mese di competenza, senza il nome della variante.

> **Se cambi variante**, il dovuto si **ricalcola per tutta l'iscrizione** con il prezzo della nuova variante, anche per i mesi già passati. I versamenti già registrati restano come sono e il saldo è «dovuto meno versato»: con un prezzo più basso può comparire un credito, con uno più alto una differenza da incassare. Nei corsi a rinnovo mensile o trimestrale ogni periodo successivo usa il nuovo prezzo.

## Quando un iscritto smette di venire

Chi smette di venire ha già frequentato il periodo pagato: **non si restituisce nulla**. Nella scheda del corso, accanto all'iscrizione attiva, c'è il pulsante **«Non rinnova»**:

- l'iscritto **resta nel corso** fino alla fine del periodo già pagato (o del mese in corso);
- poi **non deve più nulla**, nemmeno i rinnovi successivi;
- **non viene tolto** dal corso.

**«Disdici il rinnovo»**, con la scelta del mese, resta per i casi particolari.

## Quando è l'associazione a non poter più tenere il corso

In questo caso (per esempio se l'insegnante non può più tenere il corso) si restituisce ciò che non è stato ancora fatto. Eliminando il corso:

- di un mese pagato per intero ma fatto a metà si restituisce la parte delle **lezioni non ancora tenute**. Esempio: mese da 60 €, 4 lezioni, 2 già tenute: si restituiscono **30 €**;
- i mesi **già svolti per intero** restano incassati;
- i mesi **senza lezioni svolte** si rendono per intero;
- con **«annulla gli incassi»** un mese fatto a metà **resta com'è**: si può rendere solo con la **restituzione**.

## Annullare o spostare una lezione

Le **singole lezioni** di un corso si possono **annullare** o **spostare**, per esempio per una festività o per il maltempo.

1. Vai in **Corsi ed eventi** e apri il corso.
2. Apri la scheda **«Date delle lezioni»**: compaiono le lezioni dei **prossimi 60 giorni**, ciascuna con data e ora. Se non ce ne sono, leggi «Nessuna lezione nei prossimi 60 giorni».
3. Se vuoi, scrivi il **Motivo** (per esempio festività o maltempo).
4. Lascia la spunta **«avvisa gli iscritti»**, attiva di partenza, se vuoi che ricevano un avviso.
5. Scegli come procedere:
   - **Annulla solo questa lezione**: premi il pulsante;
   - **Annulla questa lezione e le successive**: annulla quella data e tutte le successive dello stesso giorno della settimana, fino alla fine del corso o dell'anno delle attività. Le lezioni passate non si toccano;
   - **Sposta**: indica la nuova data in **«Sposta a»** (l'ora di inizio e di fine sono facoltative) e premi **Sposta**.

Con la spunta gli iscritti ricevono un avviso come «La lezione di … del … è annullata» o «è spostata al …». L'avviso parte solo se chi opera può mandare avvisi per quel corso: se non parte, l'annullamento o lo spostamento resta comunque e il messaggio te lo dice.

Sotto trovi **«Lezioni cambiate»**, con quelle annullate o spostate, il motivo e il pulsante **Ripristina**, che rimette la lezione com'era. **Le quote non cambiano**. L'annullamento di lezioni **non registra rimborsi**: se serve, si restituisce a mano. Calendario, presenze e promemoria seguono le date nuove.

## Gli iscritti

Nella scheda del corso gli iscritti stanno sotto il modulo di iscrizione. Accanto a chi deve versare c'è **Paga**, che apre l'incasso già compilato. Nel modulo di iscrizione e nell'elenco trovi anche:

- **«Salva e prossimo inserimento»**: iscrive la persona e resta sulla pagina, pronta per la successiva.
- **«Iscrivi e vai alla cassa»**: iscrive la persona e, se c'è qualcosa da pagare, apre l'incasso già compilato.
- **Togli dall'elenco**: toglie chi non è più iscritto, solo se non ha incassi registrati.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'creare-un-evento',
        'title' => 'Creare un evento',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

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

- Tutte le date di un evento devono cadere nello stesso **anno delle attività**: altrimenti l'evento non viene salvato.
- Se un incasso di un corso o di un evento cade in un anno fiscale chiuso, non si possono eliminare l'evento o l'iscrizione né cedere il posto: va prima riaperto l'ultimo anno chiuso.
- Le date già presenti non si duplicano.
- Per più date, usa l'evento ricorrente. In un evento ricorrente puoi aggiungere o togliere date con **«Aggiungi o elimina date»**.

## Il calendario dell'evento

Nella pagina dell'evento trovi il **Calendario dell'evento**. Lo vedi per **Mese** o per **Giorno**, e un selettore ti porta alla data che vuoi. Sotto ci sono le righe con le date.

## Chi lo gestisce

Nella scheda c'è **Gestori dell'evento**: i soci o volontari indicati possono, dall'area soci, vedere i prenotati e registrare gli ingressi solo per quell'evento (vedi [Registrare gli ingressi a un evento](guida:registrare-gli-ingressi)).

## Eliminare un evento

Solo gli amministratori possono eliminare un evento, dal pulsante **Elimina l'evento** nella scheda. Se ci sono incassi la procedura chiede cosa fare delle somme (restituirle o annullarle) e una doppia conferma; se non ci sono, basta una conferma semplice.

Eliminando un **evento** o un **corso**, il rimborso o l'annullamento riguardano **solo ciò che non è ancora stato fatto**. Gli incassi di **date già svolte**, o di **mesi con almeno una lezione già svolta**, restano in prima nota: non vengono restituiti né annullati. Per esempio, per un corso tenuto a ottobre e annullato a novembre, ottobre resta e novembre si restituisce. La pagina di eliminazione ti mostra **quanti incassi restano**.

Vale lo stesso se elimini una **singola iscrizione** (a una data di un evento o a un corso). Per la parte già fatta la pagina e il messaggio finale ti dicono **quanto manca** (da incassare) oppure **quanto avanza** (da restituire a mano).

## Annullare una data di un evento ricorrente

Negli eventi ricorrenti, accanto a **«Annulla solo questa data»** c'è **«Annulla questa data e le successive»**. Le **date passate non si toccano**. L'annullamento di una data **non registra rimborsi**: le somme si restituiscono a mano.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'lista-d-attesa-e-tolleranza-sul-pagamento',
        'title' => 'Posti limitati, lista d\'attesa e tolleranza sul pagamento',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

## Lista d'attesa

Quando i posti sono finiti, l'evento mostra **«Posti esauriti · lista d'attesa»**. Dall'elenco eventi il socio può mettere in lista sé stesso o un suo ospite, vedere la posizione e uscire dalla lista.

- Se qualcuno annulla, o se aumenti i posti, entra automaticamente il primo della lista e riceve una email. **Non vale per gli eventi su richiesta**: lì non c'è lista d'attesa e prenota la segreteria.
- Se l'attività ha dei **listini**, chi si mette in lista **sceglie il listino**: quando il posto si libera, il plugin prenota con quello. Una persona in lista **senza un listino valido** (per esempio perché si è messa in lista prima) **non entra da sola**: nella scheda dell'evento compare «manca la tariffa scelta: non entra da solo, prenotalo a mano scegliendo il listino».
- Chi nel frattempo ha la tessera scaduta, è sospeso o non ha accettato il regolamento viene saltato.
- La coda è visibile e modificabile nella scheda dell'evento.

## Tolleranza sul pagamento

Per gli eventi con posti limitati e un contributo puoi indicare, alla creazione, quante **ore** ha chi prenota per pagare con bonifico o contanti (da 0, nessun limite, a 720).

1. Nella scheda dell'evento, imposta le ore di tolleranza.
2. Salva.

Scaduto il termine senza alcun pagamento, il posto si libera, la persona riceve una email e subentra il primo della lista d'attesa. Il controllo avviene ogni ora e a ogni nuova prenotazione.

> Non si libera mai il posto di chi ha già versato (anche in parte), di chi sta pagando online, di chi ha già l'ingresso registrato o di una data passata.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'calendario-e-google-calendar',
        'title' => 'Calendario del sito e Google Calendar',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

## Il calendario per i soci

Lo shortcode `[associazionesemplice_calendario]` (o il blocco o widget «Calendario») mostra il mese con lezioni ed eventi e mette in evidenza le attività del socio. È anche una sezione dell'area soci.

## Il calendario interno

In **Strumenti → Calendari** trovi il calendario di tutte le attività. Quello della singola attività è nella sua scheda. Il calendario è raggiungibile anche dalla Bacheca.

Un evento su più giorni compare in **ognuno dei suoi giorni**, con l'indicazione **«(giorno 2 di 3)»**.

## Pubblicarlo su Google Calendar

1. In **Strumenti → Calendari** attiva la pubblicazione: viene creato un **indirizzo segreto**, in sola lettura, che contiene solo nomi, giorni, orari e luoghi.
2. In Google Calendar apri **Altri calendari › Da URL** e incolla l'indirizzo.
3. Lo stesso indirizzo funziona con Apple Calendar e Outlook.

L'indirizzo è spento di partenza e si può rigenerare quando vuoi: rigenerandolo, quello vecchio smette di funzionare.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'pagina-pubblica-degli-eventi-sul-sito',
        'title' => 'La pagina pubblica degli eventi sul sito',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Ogni evento e ogni corso può avere una **pagina pubblica** sul tuo sito, con locandina, descrizione e il modo di prenotare o iscriversi.

## Come nasce la pagina

La pagina nasce da sola dall'attività e la trovi tra gli **«Eventi sul sito»**. Resta in bozza finché non la pubblichi: nessuno la vede prima.

- Nella pagina scrivi la **descrizione**, carichi la **locandina** e scegli la **categoria**.
- **Data, luogo, contributo e posti** non si scrivono lì: arrivano dall'attività e restano sempre aggiornati.
- La **segreteria** e il **tesoriere** possono modificare le pagine e caricare le locandine.

## Cosa vede chi visita la pagina

La pagina mostra la locandina accanto ai dati, **dove si svolge** (con il collegamento alla mappa), i **contatti** con WhatsApp ed email, il pulsante **«Salva nel tuo calendario»**, i pulsanti per **condividere**, l'evento **precedente e successivo** e il collegamento **«Tutti gli eventi»**. Gli eventi che si ripetono ogni settimana compaiono nell'elenco con **una sola scheda** («Tutti i giovedì»), finché sono futuri.

## Eventi già svolti

Gli eventi passati **non spariscono**: restano visibili nell'elenco, una scheda per ogni data (qui niente «Tutti i giovedì»), con il pulsante **«Dettagli»** al posto di «Dettagli e prenotazione». Gli eventi annullati non compaiono nell'elenco.

Nella loro pagina si legge **«Si è svolto…»** con la data (oppure «Si è svolto in N date, l'ultima…»), il luogo dell'ultima data e la sezione **«Date passate»**. Non si può più prenotare e non c'è «Salva nel tuo calendario». Un evento **in corso** (per esempio una gara iniziata ieri e non ancora finita) non è considerato passato.

## Date su più giorni

Una data può avere anche una **data di fine**, nel campo **«fino al»**: serve per una gara di più giorni o per una serata che finisce dopo mezzanotte (per esempio dalle **21:30** all'**01:00** del giorno dopo). Lo trovi nella sezione **«Quando»** del modulo di creazione, su ogni **data unica**, e anche quando modifichi una data. Se lo lasci vuoto, la data finisce lo stesso giorno.

Nelle **date ricorrenti** questo campo non c'è: lì **«fino al»** indica fino a quando la data si ripete, non quando finisce.

- Una data dura **al massimo 31 giorni**. Oltre **7 giorni** il pannello ti avvisa («Questa data dura N giorni: è giusto?») e chiede di spuntare **«Confermo la durata (oltre 7 giorni)»** prima di salvare.
- Chi si prenota a una data su più giorni paga **una quota sola**, non una per giorno.
- **Solo le date su più giorni** si prenotano online **fino al giorno prima**: dal giorno di inizio, e mentre sono in corso, prenota solo la segreteria. Chi prova a prenotare online legge «Le prenotazioni online si chiudono il giorno prima: per questa data rivolgiti alla segreteria.»
- Le date **di un giorno solo** non cambiano: si prenotano online come sempre, **anche il giorno stesso**, finché non sono passate.
- L'elenco scrive la data come «Dal 12 al 14 giugno», con il distintivo **«In corso»** finché dura, e la mostra in ogni mese che attraversa.

## Cosa succede se l'evento cambia

- La pagina **non si elimina** finché l'evento esiste: se non la vuoi più vedere, rimettila in bozza.
- Se **elimini l'evento**, la pagina va nel **cestino**.
- Se l'evento viene **annullato**, scegli nella pagina se nasconderla o lasciarla con l'avviso «annullato».

## L'elenco e il calendario

Inserisci le viste con uno **shortcode**, con il blocco **«Associazione Semplice»** o con il widget **Elementor**.

| Shortcode | Cosa mostra |
|---|---|
| `[associazionesemplice_eventi]` | schede degli eventi divise per mese: di norma il mese corrente e il prossimo, con le frecce **«← mese»** e **«mese →»** per andare avanti e indietro, anche nel passato (senza frecce con `filtri="no"`) |
| `[associazionesemplice_calendario_eventi]` | calendario mensile |
| `[associazionesemplice_prossimi_eventi]` | i prossimi eventi |

Opzioni comuni: `categoria`, `limite`, `mesi` (nell'elenco a schede, quanti mesi si vedono insieme: da 1 a 6, di partenza 2), `modo="elenco"` o `modo="mese"`, `filtri="si"` o `filtri="no"`, e per i testi `titolo` (l'intestazione sopra la vista), `vuoto` (il messaggio quando non ci sono eventi) e `pulsante` (il testo del pulsante nelle schede). Esempio: `[associazionesemplice_eventi titolo="I nostri prossimi eventi"]`.

I **corsi** compaiono nel calendario con le loro **lezioni**, ciascuna con il collegamento alla pagina del corso.

## Comporre la pagina dell'evento a modo tuo

La pagina ha un'impaginazione automatica. Per comporla come vuoi, inserisci nel contenuto dell'evento i **pezzi** che ti servono: `[associazionesemplice_evento_locandina]`, `[associazionesemplice_evento_dati]`, `[associazionesemplice_evento_prenotazione]`, `[associazionesemplice_evento_dove]`, `[associazionesemplice_evento_navigazione]` e `[associazionesemplice_evento_condividi]` (o i blocchi e i widget equivalenti). Appena ce n'è uno, la pagina mostra **solo il tuo layout**. Con altri costruttori di pagine (Divi, Bricks e simili) usa lo shortcode nel loro blocco «Shortcode».

## WhatsApp della segreteria

Il pulsante **«Scrivici su WhatsApp»** usa il telefono della segreteria indicato nei **Dati dell'ente** e compare sul sito, anche nelle pagine degli eventi, solo se accendi la casella **«Pulsante WhatsApp»** in **Impostazioni → Soci e quote**. Accendila solo se quel numero è un cellulare con WhatsApp. Se per un evento hai scelto un numero diverso, vale quello.

## Se arrivi da The Events Calendar

Con **Importa da The Events Calendar** si compilano anche le pagine pubbliche: descrizione, immagine, categoria, organizzatore, luogo e sito dell'evento. Le pagine restano in bozza; la casella **«Pubblica subito le pagine degli eventi futuri»** (spenta di partenza) pubblica solo quelle con una data futura. Gli eventi che si ripetono diventano **un solo evento ricorrente**. Si importa anche la **data di fine**, quindi gli eventi su più giorni restano su più giorni. Se l'evento dura più di 31 giorni, le date restano scritte nelle note. Rilanciando l'importazione non nascono doppioni e una pagina già scritta non viene toccata.

## Pubblicare le pagine in bozza

Le pagine rimaste in bozza le trovi nell'elenco **Attività**, nella scheda **«Pagine in bozza: non si vedono ancora sul sito»**. Spunta quelle da pubblicare (o **«Seleziona tutte»**), premi **«Pubblica le pagine selezionate»** e conferma: compaiono subito sul sito.

Le pagine le pubblica chi ha il permesso per quell'area: **Eventi** per le pagine degli eventi, **Corsi** per quelle dei corsi. Amministratori e segreteria le pubblicano tutte.

Se usi una cache delle pagine, **svuotala** dopo aver pubblicato o modificato un evento. Per prenotare e iscriversi dalla pagina leggi [Prenotarsi a un evento o a un corso dal sito](guida:prenotarsi-a-un-evento-o-a-un-corso-dal-sito).
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'prenotarsi-a-un-evento-o-a-un-corso-dal-sito',
        'title' => 'Prenotarsi a un evento o a un corso dal sito',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Dalla pagina pubblica di un evento o di un corso prenotano sia i **soci** sia chi **non è socio**.

## Il socio

Il socio **prenota con un clic**, entrando con il suo accesso: il modulo e le regole sono quelli dell'area soci.

**Il pagamento non si fa nella pagina.** Il contributo risulta «da versare»: il socio lo paga dall'area soci, in **Pagamenti**, se i pagamenti online sono attivi, oppure con bonifico o contanti come sempre.

## Chi non è socio

Chi non è socio compila un **modulo breve** e riceve una email con un collegamento da aprire **entro 24 ore** per confermare l'indirizzo. Il collegamento **mostra la richiesta** con il pulsante **«Conferma»**: **aprirlo non basta**, la richiesta è confermata solo premendo il pulsante (così un'anteprima della posta non conferma da sola). Prenota sempre un adulto, che risponde dei minori indicati come accompagnati.
**Dopo la conferma** legge che la segreteria esaminerà la richiesta e gli scriverà appena è approvata (per i corsi, spiegando anche come pagare). Lo stesso gli arriva per email, con oggetto **«Richiesta ricevuta: …»**.

Se non conferma entro 24 ore, la richiesta si cancella da sola.

Per gli eventi **a pagamento** il non socio **versa in anticipo con bonifico istantaneo** e manda la **ricevuta alla segreteria** entro il termine impostato in **Impostazioni → Limiti e soglie** (24 ore se non lo cambi). Se la ricevuta non arriva, il posto si annulla. Non è un pagamento online: l'incasso lo registra la segreteria.

**Se il codice fiscale è già registrato**, chi chiede non vede né il nome né lo stato di quella persona: la richiesta passa alla segreteria. Se i dati sono diversi, la segreteria riceve l'email **«Richiesta di partecipazione da verificare: …»**: approva se è la stessa persona (si usa la scheda che c'è già), altrimenti rifiuta.

## I tetti alle richieste

Per non riempire il sito di richieste inutili ci sono tre limiti, che cambi in **Impostazioni → Limiti e soglie**, gruppo **«Accessi e sicurezza»**:

- **«Richieste di evento o corso in attesa, al massimo»**: di partenza **100** (da 10 a 5000);
- **«Posti chiedibili con lo stesso indirizzo email, in 24 ore»**: di partenza **20** (da 10 a 200);
- **«Posti chiedibili dalla stessa connessione, in 24 ore»**: di partenza **40** (da 10 a 500).

**Quando il limite è raggiunto**, chi scrive legge che per ora non si ricevono altre richieste e che può riprovare più tardi o scrivere alla segreteria. Tu trovi in **Bacheca** l'avviso **«Le richieste di evento e di corso sono al limite»**, con il numero di richieste in attesa e di quelle scartate. Per far posto **approva o rifiuta** le richieste in attesa dalla scheda delle attività, oppure **alza il limite** da **Limiti e soglie**.

**Se il limite è per email o per connessione**, chi scrive legge che da quell'indirizzo o da quella connessione sono già stati chiesti molti posti nelle ultime 24 ore, con l'invito a scrivere alla segreteria. La richiesta **non viene registrata** e non parte nessuna email. L'avviso in Bacheca conta anche le richieste respinte nell'ultima settimana.

**La segreteria non ha questi limiti** quando prenota.

## Limiti per socio

Oltre ai tetti sopra, ogni socio può fare un numero limitato di operazioni ogni **10 minuti**. I valori di partenza, che cambi in **Impostazioni → Limiti e soglie → Accessi e sicurezza**, sono:

- prenotarsi o mettersi in lista d'attesa: **20**;
- annullare una prenotazione: **20**;
- modificare il proprio profilo: **10**;
- scaricare ricevute e rendiconti: **30**;
- rigenerare il proprio codice QR: **10**.

Se li supera, il socio legge «Hai fatto troppe richieste in poco tempo…» e riprova dopo qualche minuto.

## Le scelte per ogni evento

Nella pagina dell'evento, nel riquadro **«Prenotazione online»**, scegli:

- se **anche chi non è socio** può chiedere un posto dalla pagina;
- se la prenotazione è **automatica** o **su richiesta**. Su richiesta, chi vuole partecipare chiede dalla pagina dell'evento e la segreteria approva in ordine di arrivo. Non c'è lista d'attesa: chi era già in lista resta dov'è ma non entra da solo, lo prenoti tu dalla scheda dell'evento;
- quanti **posti al massimo** si possono chiedere per volta (0 = nessun limite);
- cosa fare se l'evento viene **annullato**: nascondere la pagina oppure lasciarla con l'avviso «annullato».

## Dove approva la segreteria

Le richieste compaiono nella scheda dell'attività, nel riquadro **«Richieste di partecipazione»** (per i corsi **«Richieste di iscrizione»**). Approvando, il non socio viene registrato come **ospite** e iscritto.

## I corsi

Nella pagina del corso trovi **«Come iscriversi»**: il socio con la tessera valida chiede l'iscrizione **in un clic**, chi non è socio compila il modulo e paga in anticipo con bonifico. L'iscrizione **parte dal mese corrente**, quello dell'approvazione. Approva sempre la segreteria.

## Protezioni

Il modulo si protegge da solo dalle richieste automatiche, senza CAPTCHA di terzi. Chi non è socio non ha un'area personale: per annullare la prenotazione scrive alla segreteria.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'prenotare-un-evento-per-piu-persone',
        'title' => 'Prenotare un evento per più persone',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Il socio può prenotare in un solo modulo per sé, per i suoi ospiti, per i minori di cui è tutore e per altri soci.

## Come prenota il socio

1. Entra nell'**area soci** e apri la pagina dell'evento.
2. Sotto la data apri **«Prenota per più persone»**.
3. Scegli chi prenoti: te, i tuoi ospiti, i minori di cui sei tutore. Puoi aggiungere ospiti nuovi (nome, cognome e cellulare) e altri soci (cognome e numero di tessera).
4. Se l'evento ha più contributi, scegli un contributo per ogni persona.
5. Premi **«Vedi il totale»**: vedi il contributo di ciascuno e il totale. Finché non confermi, nessuno è prenotato. Il riepilogo vale 10 minuti.
6. Premi **«Conferma e prenota»**. Si prenotano tutti o nessuno.

Le regole:

- al massimo **10 persone** per volta, o meno se l'evento lo prevede;
- il contributo di tutti è **a carico di chi prenota**;
- per gli eventi su richiesta e per i corsi la prenotazione per più persone non c'è;
- a posti esauriti la lista d'attesa resta per una persona alla volta.

## Un altro socio ha prenotato per te

Ricevi un'email. Nell'area soci, in **«Prossime prenotazioni»**, puoi scegliere:

- **«Conferma»**: la prenotazione diventa tua, con le regole normali, e chi l'ha fatta non può più annullarla. Il contributo resta a suo carico.
- **«Non partecipo»**: il posto si libera. Lo puoi fare fino all'inizio dell'evento. Per quella data nessun altro socio può prenotare per te.

Se non vuoi che altri soci prenotino per te, togli la spunta **«Altri soci possono prenotare per me»** in **«Il mio profilo»**.

Chi ha prenotato vede in **«Prenotazioni fatte per altri soci»** i prenotati con il numero di tessera e può usare **«Annulla prenotazione»** finché l'altro socio non conferma.

## Per la segreteria

- Nell'elenco dei prenotati, sotto il contributo, compare **«a carico di …»**.
- Il contributo si incassa da chi ha prenotato: in **«Nuovo incasso»** scegli quella persona e trovi tra i suoi contributi anche le voci prenotate per altri, segnate **«· per Nome Cognome»**, senza altra conferma.
- Se un socio si toglie e il contributo era già versato, la segreteria riceve un'email: la restituzione è a chi ha pagato.
- Se chi partecipa vuole pagare il suo contributo, in **«Nuovo incasso»** scegli quella persona: il contributo compare in **«+ Contributo evento…»** con **«(a carico di Nome Cognome)»**, non si compila da solo e si aggiunge solo dopo una conferma. In quel caso paga chi partecipa e la parte incassata non è più dovuta da chi ha prenotato.
- Nella ricevuta di chi paga, un altro socio compare con il numero di tessera.

## Se qualcosa non va

- Se leggi **«Non è stato possibile prenotare una delle persone indicate»**: controlla cognome e numero di tessera. Non è stata prenotata nessuna persona. Il motivo preciso non viene detto, per riservatezza.
- Dopo alcuni tentativi sbagliati devi aspettare qualche minuto.
- Se usi il Pro e non l'hai aggiornato, la sezione **«Altri soci»** non compare e in amministrazione vedi l'avviso **«Aggiorna Associazione Semplice Pro…»**: aggiorna il Pro.

Per la prenotazione di una sola persona leggi [Prenotarsi a un evento o a un corso dal sito](guida:prenotarsi-a-un-evento-o-a-un-corso-dal-sito).
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Attività', 'slug' => 'listini-e-pacchetti-per-eventi-e-corsi',
        'title' => 'Listini e pacchetti di ingressi per eventi e corsi a date',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Con i **listini** la stessa attività può avere prezzi diversi, per esempio *Individuale*, *Di gruppo* o *Pacchetto 4 ingressi*. Chi prenota sceglie il listino che gli interessa.

## Creare i listini

1. Apri la **scheda dell'attività** (evento o corso a date) da **Attività**.
2. Nella riga **Listini** indica **nome**, **prezzo** e numero di **ingressi** di ogni listino. Per aggiungerne un altro premi **«+ Aggiungi un prezzo»**.
3. Salva.

Un listino con un solo ingresso è una prenotazione normale; un listino con più ingressi è un **pacchetto**.

## Prenotare con un listino

- **Dall'amministrazione**: in **Cassa → Prenota** (o dalla scheda dell'evento) scegli la persona e il listino.
- **Dall'area soci**: il socio vede e sceglie solo il listino, senza altre opzioni. **Se l'attività ha dei listini, la tariffa è obbligatoria**: senza sceglierne una compare «Scegli la tariffa: per questa attività bisogna sceglierne una.» Vale anche per la lista d'attesa.

## Come funzionano i pacchetti

1. Il pacchetto si **paga sulla prima prenotazione**.
2. Le altre date si prenotano con **usa pacchetto**: non c'è altro da pagare.
3. Ogni prenotazione consuma un ingresso del pacchetto. Nel modulo **«Prenota»** di un evento, scelto il socio, la casella dice quale pacchetto ha e quanti ingressi restano.

Per vedere a chi restano ingressi:

- nella pagina dell'evento c'è **«Pacchetti con ingressi ancora da usare»**, con quanti ingressi restano a ciascuno;
- in **Cassa → Prenota**, scelta la persona, vedi i suoi **«Pacchetti di …»**.

> I listini non valgono per i **corsi a mensilità**: lì il contributo resta quello mensile del corso (vedi [Creare un corso](guida:creare-un-corso)).
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Ingressi e comunicazioni', 'slug' => 'registrare-gli-ingressi',
        'title' => 'Registrare gli ingressi a un evento',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale} (incasso sul posto: Pro)

Chi gestisce un evento può registrare chi entra direttamente dal telefono, senza accedere all'amministrazione.

## Prepara

1. Nella scheda dell'evento, in **Gestori dell'evento**, indica i soci o volontari che lo gestiscono (oltre al referente e agli amministratori).
2. Crea la pagina **Ingressi** (shortcode `[associazionesemplice_ingressi]`, già inclusa nell'area soci).
3. Se vuoi usare i QR, attiva il **biglietto QR** per l'evento (vedi [Attivare il QR della tessera e dei biglietti](guida:attivare-il-qr-della-tessera)).

## Il giorno dell'evento

1. Apri la pagina **Ingressi** dall'area soci e scegli la data.
2. Vedi la **lista dei prenotati** con tipo (socio o ospite), contributo (versato, da versare, gratuito), ora di ingresso e i contatori. Email e telefoni non compaiono.
3. Premi **Registra ingresso** accanto alla persona. Puoi annullare la registrazione.
4. Con il QR: premi **Scansiona** (nei browser che leggono i QR) oppure usa la fotocamera del telefono, che apre la pagina del biglietto, e lì **Registra ingresso**.

Un biglietto già usato risulta «Ingresso già registrato»: una copia del QR non entra due volte. Se il contributo non è versato, lo vedi subito.

Con **Pro**, se per l'evento è **abilitato l'incasso**, lo **staff dell'evento** può anche **addebitare crediti** al socio con il QR, **senza che serva un utente WordPress**.

## Da sapere

- L'ingresso si registra **nel giorno dell'evento**. Gli amministratori possono farlo anche in un altro giorno dalla scheda dell'evento.
- Togliere una persona dai gestori la esclude subito.
- Con **Pro**, se per l'evento è **abilitato l'incasso**, lo **staff dell'evento** può incassare il contributo sul posto e **addebitare crediti** al socio con il QR, anche se il socio non ha un utente WordPress.
MD,
    ],
    [
        'topic' => 'corsi-ed-eventi', 'section' => 'Ingressi e comunicazioni', 'slug' => 'inviare-un-avviso-agli-iscritti',
        'title' => 'Inviare un avviso agli iscritti di un\'attività',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Chi tiene un'attività (referente), chi gestisce un evento e gli amministratori possono inviare un avviso a tutti gli iscritti di quell'attività.

## Cosa fare

1. Apri l'**area volontari** e trova l'attività.
2. Nel modulo **Invia un avviso agli iscritti** scrivi il titolo (fino a 120 caratteri) e il testo (fino a 2000).
3. Per un evento puoi inviarlo a tutte le prossime date o a una sola, dal modulo sotto la lista dei prenotati.
4. Premi **Invia**.

## Chi lo riceve e come

- Arriva **per email a ciascun iscritto**, singolarmente: nessuno vede gli indirizzi degli altri.
- Resta anche nella **bacheca «Avvisi»** dell'area soci per 45 giorni.
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
> Disponibile in: {piano_contabile}, {piano_fiscale}

Le Comunicazioni inviano un messaggio email a un gruppo, con anteprima dei destinatari e storico degli invii. Sono disponibili anche alla segreteria.

## Cosa fare

1. Vai in **Rubrica soci → Comunicazioni**.
2. Scegli il **gruppo**: soci in regola, tutti i soci, tessera in scadenza o scaduta, soci e volontari, consiglio direttivo, ospiti, chi non ha accettato il regolamento, iscritti a un corso o a un evento, prenotati a una data.
3. Scrivi oggetto e testo. Puoi usare `{nome}` e `{associazione}` per personalizzare.
4. Guarda l'**anteprima dei destinatari** (e di chi non ha email).
5. Fai una **prova solo a te**.
6. Premi **Invia**.

## Come partono

Ognuno riceve la **propria** email: nessun indirizzo è visibile agli altri. I primi 25 partono subito, gli altri poco dopo (circa 25 al minuto). Gli ospiti senza email la ricevono tramite il socio che li ospita.

## Dopo l'invio

Lo **storico** mostra chi l'ha ricevuta e chi no, con il pulsante **Riprova le non partite**. Se i dispositivi dei soci hanno le notifiche attive, il messaggio arriva anche come notifica.

> Puoi inviare al massimo 20 comunicazioni in 24 ore: così le tue email non finiscono nello spam.
MD,
    ],
];
