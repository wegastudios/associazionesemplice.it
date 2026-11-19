<?php

// Guide del plugin 1.3.25 (free) / 1.3.12 (Pro): rimborso dal programma, dati dell'ente e contatti, file riservati e cifratura.
// BOZZE: da pubblicare (seeder) SOLO quando la sessione audit conferma che il plugin 1.3.25 / Pro 1.3.12 è rilasciato.
return [
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'restituire-un-pagamento-online-al-socio',
        'title' => 'Restituire un pagamento online al socio',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

Un pagamento fatto con **Stripe** o **PayPal** si può restituire al socio **direttamente dal programma**, senza passare dal pannello del gestore.

## Dove si fa

- **L'amministratore** va in **Impostazioni → Cassa → Pagamenti online**.
- **La Segreteria e il Dipendente** vanno in **Cassa** e premono **«Pagamenti online»**. Le chiavi di Stripe e PayPal le vede solo l'amministratore.

Il pulsante **«Rimborsa»** compare accanto ai pagamenti incassati che non sono in prima nota: pagamenti mai registrati (per esempio la domanda di adesione è stata rifiutata, o il pagamento è «da controllare») oppure con l'incasso già annullato. **Se l'incasso è già in prima nota, prima annullalo da lì: poi qui compare «Rimborsa».** Se la domanda di adesione è ancora in esame, il programma ti ricorda che, se la rifiuti, la quota risulterà «da rimborsare».

## Come si fa

1. Premi **«Rimborsa»** sul pagamento.
2. Scrivi **quanto restituire**: tutto o una parte, **da 0,50 € in su**, anche in più volte. L'importo non è mai precompilato.
3. Scrivi il **motivo del rimborso**: è obbligatorio e **lo leggerà il socio nell'email**, quindi scrivilo in modo chiaro e gentile.
4. Metti la spunta **«Ho capito: il rimborso non si può annullare»** e premi **«Rimborsa»**.

A operazione fatta compare «Rimborso di X,XX € inviato al socio.» e, se il socio ha un'email, «Il socio è stato avvisato per email.»; altrimenti «Il socio non ha un'email o l'invio non è riuscito: avvisalo tu.» Nell'elenco il pagamento diventa **«Rimborsato»** o **«Rimborsato in parte»**, con la cifra restituita.

## Cosa fa e cosa non fa

- I soldi tornano sul **metodo di pagamento del socio**: in genere entro **pochi giorni lavorativi, fino a 10**, a seconda della banca. L'email al socio («Rimborso del tuo pagamento · nome dell'associazione») riporta importo, data del pagamento, motivo e questi tempi, e chiude con i contatti della segreteria.
- **Non scrive nulla in prima nota.**
- Restituisce **solo i soldi**: iscrizioni, prenotazioni e crediti collegati al pagamento **restano come sono**. Se vanno tolti, sistemali a mano.
- Se hai già restituito i soldi dal pannello di Stripe o PayPal, **non rimborsare di nuovo da qui**: segna il pagamento con **«Rimborsato»**.
- Con **SumUp**, **Satispay** e **WooCommerce** il rimborso si fa dal pannello del gestore (con WooCommerce, dall'ordine nel negozio).
- Se il gestore rifiuta il rimborso, il programma ne spiega il motivo (per esempio la chiave non ha il permesso di fare rimborsi, o il saldo non basta): non cambia nulla.
- Se **Stripe o PayPal non rispondono** o danno un errore loro, il pagamento resta **«da controllare»** e compare «Rimborso di … non confermato dal gestore: per ripeterlo usa lo stesso importo.» Si può ripetere **solo lo stesso importo**: così non si rimborsa due volte.

MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Primi passi', 'slug' => 'dati-dell-ente-e-contatti-della-segreteria',
        'title' => 'Dati dell\'ente e contatti della segreteria',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

I **dati dell'ente** si inseriscono nella configurazione guidata e si cambiano quando vuoi in **Impostazioni → Ente e fiscalità → Dati e fiscalità**.

## Cosa serve

Sono **obbligatori**: **denominazione**, **forma dell'ente** (ASD, APS, ODV, ETS, Circolo o Altro), **codice fiscale**, **sede legale** (indirizzo, CAP, comune, provincia), **email** e **telefono della segreteria**.

Per **partita IVA**, **numero RUNTS** e **PEC** scrivi il dato oppure, se non esiste, metti la spunta **«L'associazione non ha la partita IVA»**, **«L'associazione non è iscritta al RUNTS»** o **«L'associazione non ha una PEC»**.

Il **legale rappresentante** non si scrive qui: è la persona con la **carica di presidente**, che indichi tra le **Persone**. Se manca, la banda in alto ti porta lì con il pulsante **«Indica il presidente»**.

## Perché servono email e telefono della segreteria

I soci devono sapere **a chi scrivere**. Li trovano:

- nelle **email** che ricevono dal programma: la segreteria è l'indirizzo di risposta e l'email finisce con la riga «Segreteria: email · tel.»;
- nell'**informativa sulla privacy**;
- nell'area soci;
- nel **modulo di adesione online**: «Per esercitare i tuoi diritti scrivi a … o chiama …»;
- nel messaggio che legge il socio quando non riesce a scaricare una ricevuta.

## La banda gialla in alto

Finché manca qualche dato, in cima alle pagine del plugin c'è una **banda gialla fissa** (non si chiude): **«Mancano dei dati dell'associazione: …»**, con l'elenco di ciò che manca e il pulsante **«Completali ora»**. Chi non amministra legge «Chiedi a chi amministra di completarli.». La banda sparisce da sola quando è tutto compilato.

## Senza email e telefono della segreteria

La segreteria **non può inviare i messaggi scritti a mano**: comunicazioni, avvisi agli iscritti e ricevute inviate a mano. Il programma dice: «Prima inserisci email e telefono della segreteria (Impostazioni › Ente): senza, la segreteria non può inviare messaggi ai soci.» (per le ricevute aggiunge: «Intanto scarica la ricevuta e consegnala a mano.»).

**Tutto ciò che il programma manda da solo parte comunque**: inviti e link di accesso, conferme, promemoria, avvisi di lezione spostata o annullata, ricevuta del pagamento online.

## Il pulsante WhatsApp

Usa il telefono della segreteria e compare solo se spunti la casella **«Pulsante WhatsApp»** in **Impostazioni → Soci e quote**: fallo solo se è un **cellulare su cui la segreteria usa WhatsApp**.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'perche-vedo-la-banda-mancano-dei-dati',
        'title' => 'Perché vedo la banda gialla «Mancano dei dati dell\'associazione»?',
        'body' => <<<'MD'
È la **banda gialla in alto**: elenca i dati dell'ente che mancano (per esempio l'email della segreteria o il codice fiscale). Premi **«Completali ora»** e inseriscili. Se mancano i dati fiscali non si blocca nulla; senza **email e telefono della segreteria**, invece, non puoi inviare i messaggi scritti a mano. Vedi [Dati dell'ente e contatti della segreteria](guida:dati-dell-ente-e-contatti-della-segreteria).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'perche-non-riesco-a-inviare-una-comunicazione-ai-soci',
        'title' => 'Perché non riesco a inviare una comunicazione o una ricevuta ai soci?',
        'body' => <<<'MD'
Probabilmente mancano l'**email** o il **telefono della segreteria**. Il programma ti dice: «Prima inserisci email e telefono della segreteria (Impostazioni › Ente): senza, la segreteria non può inviare messaggi ai soci.» Inseriscili nei **Dati dell'ente** e riprova. Intanto una ricevuta si scarica e si consegna a mano. Le email automatiche (accessi, conferme, promemoria) partono comunque. Vedi [Dati dell'ente e contatti della segreteria](guida:dati-dell-ente-e-contatti-della-segreteria).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'forma-dell-ente-e-sezione-del-runts',
        'title' => 'Forma dell\'ente e sezione del RUNTS sono la stessa cosa?',
        'body' => <<<'MD'
No, sono **due dati separati**. Per un'APS o un'ODV, se la sezione del RUNTS che hai indicato è diversa dalla forma dell'ente, compare un avviso che ti dice di controllare quale delle due è giusta. **L'avviso non blocca nulla.**
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'perche-compare-l-avviso-rosso-sulla-cartella-dei-file',
        'title' => 'Perché compare l\'avviso rosso sulla cartella dei file riservati?',
        'body' => <<<'MD'
Lo vede solo l'amministratore. Dice **«La cartella dei file riservati è raggiungibile da internet.»**: il controllo automatico ha provato a scaricare dall'esterno un piccolo file di prova e ci è riuscito. Chi conosce l'indirizzo esatto di un file potrebbe leggerlo senza accedere al sito. I nomi dei file non si indovinano, ma un indirizzo finito per sbaglio in una email o in un registro basterebbe.

**Cosa fare:** premi **«Vedi cosa fare»** (porta a **Strumenti → Copia di sicurezza**, sezione «Cartella dei file riservati») e poi **«Controlla ora»**. Se il risultato è rosso, **inoltra il messaggio già pronto a chi gestisce il sito o l'hosting**: deve bloccare l'accesso diretto a quella cartella o spostarla fuori dalla parte pubblica del sito. Quando è sistemato, premi di nuovo **«Controlla ora»**.

Per chi gestisce il server: le istruzioni tecniche (cartella fuori dal sito, pulsante «Sposta i file») sono nella stessa pagina, sotto «Cosa fare se la cartella è raggiungibile (o per metterla al sicuro)».

La **nuova cartella entra in uso solo a spostamento finito**: finché non hai premuto **«Sposta i file»** e il lavoro non è completo, i file restano dove sono e il sito li legge da lì. **«Sposta i file»** copia ogni file e **controlla che la copia sia identica prima di cancellare l'originale**. La cartella deve essere **vuota e dedicata a questo sito**: ogni altra installazione, anche di prova, ne vuole una sua.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'cosa-fa-controlla-ora-sulla-cartella-dei-file',
        'title' => 'Cosa fa «Controlla ora» sulla cartella dei file riservati?',
        'body' => <<<'MD'
Crea un piccolo **file di prova, senza dati**, prova a scaricarlo dall'indirizzo web del sito e lo cancella subito. Si ripete da solo circa **una volta a settimana**. L'esito può essere:

- **raggiungibile da internet** (rosso): vedi [l'avviso rosso](guida:perche-compare-l-avviso-rosso-sulla-cartella-dei-file);
- **il file di prova non si scarica**: a posto, ma il controllo parte dal sito stesso, quindi per la certezza chiedi conferma a chi gestisce il server;
- **la cartella sta fuori dalla parte pubblica del sito**: non serve altro;
- **non verificabile** (il sito è protetto da una password del server, la prova è stata mandata a un'altra pagina, il server ha risposto con un errore o nella cartella non si riesce a creare il file): **non è un allarme**, ma il controllo non è riuscito. Chiedi conferma a chi gestisce il server.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'cosa-succede-ai-certificati-medici-dopo-l-aggiornamento',
        'title' => 'Cosa succede ai certificati medici dopo l\'aggiornamento?',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

**Prima di aggiornare, fai una copia di sicurezza.** Dopo l'aggiornamento i certificati medici già caricati vengono **cifrati da soli**, senza premere nulla. La cifratura parte alla **prima visita di un amministratore** o **entro un'ora**, e prosegue un po' alla volta, **un gruppo di file al minuto**. Se si interrompe, riprende da dove era arrivata.

Gli **altri file già caricati** (documenti, allegati, giustificativi, foto) si cifrano invece con il pulsante **«Cifra i file già caricati»**. Lo stato si vede in **Strumenti → Copia di sicurezza → «Cifratura dei file riservati»**.

L'amministratore vede questi avvisi (in **Bacheca**, **Copia di sicurezza** e **Certificati**):

- **«La cifratura dei certificati medici è partita.»**: «Si fa da sola, mentre usi il programma: non serve premere nulla.»
- **«Cifratura dei certificati medici in corso: restano …»** (con il numero): «Il lavoro prosegue da solo, un lotto al minuto.»
- **«La cifratura dei certificati medici è finita.»**: tutti i certificati già caricati sono ora cifrati.
- **Finita, ma non per tutti**: il programma dice quanti certificati non si sono potuti cifrare e quanti file non ha trovato. I certificati non cifrati sono rimasti dove stavano: l'elenco è in **Strumenti → Copia di sicurezza → «Cifratura dei file riservati»**. Apri l'elenco e premi **«Riprova»**; se il file non si trova, elimina il certificato dalla pagina **Certificati** e caricalo di nuovo, oppure ripristina una copia di sicurezza.
- **Libreria mancante**: «I certificati medici già caricati restano in chiaro: su questo server manca la libreria di cifratura (sodium).» Premi **«Vedi il messaggio da inoltrare»** e mandalo a chi gestisce il sito; la cifratura riparte da sola quando la libreria c'è.
- **Chiave non valida**: «La cifratura dei certificati medici è ferma: la chiave non si apre su questo sito.» Non viene toccato nulla; si sistema da **«Sistemare la chiave»** e poi riparte da sola.

Vedi anche [Cifratura dei file riservati](guida:cifratura-dei-file-riservati).
MD,
    ],

    [
        'topic' => 'installazione', 'section' => 'Manutenzione', 'slug' => 'cifratura-dei-file-riservati',
        'title' => 'Cifratura dei file riservati',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

**Questa protezione serve se qualcuno porta via la cartella dei file. Non sostituisce la sicurezza del sito e del suo database.**

Nel **gratuito** si salvano **cifrati** i **documenti e le scansioni**, gli **allegati dei movimenti** e le **foto dell'inventario**. Con {piano_contabile} e {piano_fiscale} si cifrano anche i **certificati medici**, i **giustificativi dei rimborsi** e i **PDF delle ricevute** (emessi e «ANNULLATA»), che nella copia di sicurezza restano cifrati. Lo ZIP delle ricevute di un anno fiscale (**«Scarica i PDF di questo anno fiscale (ZIP)»**), invece, esce **non cifrato**: il programma avvisa «I PDF dello ZIP non sono cifrati e contengono dati personali (nome, codice fiscale, importi): conservalo con cura e cancellalo quando non ti serve più.» Si gestisce da **Strumenti → Copia di sicurezza**, sezione «Cifratura dei file riservati».

## File nuovi e file già caricati

- I **file nuovi** si salvano cifrati **subito**.
- I **file già caricati** si cifrano quando l'**amministratore preme «Cifra i file già caricati»**. Con {piano_contabile} e {piano_fiscale} i **certificati medici** già caricati si cifrano **da soli** dopo l'aggiornamento; per gli altri file già caricati resta il pulsante. Dettagli e avvisi: [Cosa succede ai certificati medici dopo l'aggiornamento?](guida:cosa-succede-ai-certificati-medici-dopo-l-aggiornamento) Prima di cominciare conviene fare una **copia di sicurezza con gli allegati** (il programma ti propone «Scarica la copia con gli allegati»). Da lì il lavoro prosegue da solo, a lotti, e puoi anche chiudere la pagina.

Su alcuni server manca la libreria che serve a cifrare: il programma lo dice e ti dà un **messaggio pronto da inoltrare a chi gestisce il sito**. In quel caso i certificati medici (con {piano_contabile} e {piano_fiscale}) non si possono caricare e gli altri file restano in chiaro, con un avviso.

## Dopo l'aggiornamento

- **Gratuito**: per i file già caricati premi **«Cifra i file già caricati»** in **Strumenti → Copia di sicurezza**.
- **Con {piano_contabile} e {piano_fiscale}**: i certificati medici già caricati vengono cifrati da soli; per gli altri file già caricati premi **«Cifra i file già caricati»**.

## La frase per riaprire i documenti

Trovi la frase in **Strumenti → Copia di sicurezza → «Frase per riaprire i documenti»**. Serve a **riaprire i documenti** se il sito viene spostato o riparato, se i codici di sicurezza del sito cambiano e per **ripristinare una copia di sicurezza su un altro sito**. **Conservala fuori dal sito**, su carta o in un gestore di password: se perdi il sito, altrimenti non la ritrovi.

- Nel **gratuito** il programma te la mostra così: «La frase è la data e l'ora in cui è stata creata la chiave dei file, in UTC: {data}. Non è la data di installazione del plugin. Va scritta esattamente così, spazi e trattini compresi.» (c'è il pulsante **«Stampa»**).
- Con la **licenza** è il **codice di licenza**, che ritrovi nell'area clienti di Associazione Semplice; il codice di licenza apre sempre i documenti.

**Cambiare la frase non cambia la chiave dei file**: le copie fatte prima si aprono ancora con la frase di allora, e con quella si aprono anche i file salvati dopo. Le copie vecchie conservale con cura o cancellale, e fai una **copia nuova**.

Una frase automatica protegge meno di una frase scelta da te: vedi il paragrafo seguente.

## Quanto protegge

Chi si porta via la sola cartella dei file non può leggerli. **Chi ha anche il database può aprirli quando la frase è quella automatica o il codice di licenza**, perché stanno nel database; il codice di licenza **lo conosce anche Associazione Semplice** e sta nelle email di acquisto. Nel plugin senza licenza, con una **frase tua**, servono anche il file `wp-config.php` o la frase.

## Testi che restano in chiaro nella copia

Alcuni campi di testo riservati (per esempio il medico nei certificati) su certi siti non si possono ancora cifrare. Quanti sono lo vedi in **Strumenti → Copia di sicurezza**, subito dopo aver fatto la copia: compare un avviso con il numero e il rimando alla sezione **«Documenti riservati»**. Il numero non compare al ripristino. Nessun dato va perso: il numero è scritto anche nel file di descrizione della copia.

## Per proteggere di più

Nel plugin **senza licenza**, scegli una **frase tua e lunga** (almeno quattro parole) con **«Cambia la frase»**; con **«Torna alla frase automatica»** torni indietro. **In ogni caso** chiedi a chi gestisce il sito di aggiungere la costante `ASEM_ENC_KEY` (vedi sotto). **Con la licenza il codice di licenza apre sempre**: la frase tua serve solo a riaprire i documenti. Prima di cambiare i codici di sicurezza del sito, controlla di avere la frase.

**Per chi gestisce il sito:** la protezione più alta si ottiene aggiungendo al file `wp-config.php` la costante `ASEM_ENC_KEY` (un testo lungo e casuale), che sta fuori dal database. Va conservata con cura: senza di essa e senza la frase i file non si aprono, a meno di avere il codice di licenza, che apre sempre. Se i codici `AUTH_KEY` e `AUTH_SALT` mancano o sono quelli di esempio, la chiave è più debole e il programma lo segnala.

## Se la chiave non si apre

Nella pagina **«Verifica delle ricevute»** (vedi [Ricevute in PDF e attestazione annuale](guida:ricevute-in-pdf)) compare il conteggio **«File cifrati che la chiave non apre»**, con il rimando a **Strumenti → Copia di sicurezza → «Cifratura dei file riservati»**.


Se i codici di sicurezza sono cambiati o il database è stato copiato da un altro sito, il programma dice che la chiave non è valida su questo sito: i file cifrati non si leggono e i nuovi non si salvano finché non viene sistemata. Si usa la frase; per questo va conservata. Vedi anche [Copia di sicurezza e ripristino](guida:copia-di-sicurezza-e-ripristino).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'compare-errore-imprevisto-con-un-codice',
        'title' => 'Compare «Errore imprevisto» con un codice: cosa faccio?',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Se nel pannello compare **«Errore imprevisto (codice …)»**, qualcosa non è andato come doveva e il plugin non ha potuto finire l'operazione.

1. **Riprova** una volta: a volte era solo un problema momentaneo.
2. **Se si ripete**, scrivi al supporto di Associazione Semplice **indicando il codice** che vedi nel messaggio. Con quel codice ritroviamo subito che cosa è successo.

Scrivi anche **che cosa stavi facendo** (per esempio «registravo un incasso»).
MD,
    ],
];
