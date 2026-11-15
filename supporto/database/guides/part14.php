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

## Chi può farlo

**L'amministratore, la Segreteria e il Dipendente.**
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Primi passi', 'slug' => 'dati-dell-ente-e-contatti-della-segreteria',
        'title' => 'Dati dell\'ente e contatti della segreteria',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

I **dati dell'ente** si inseriscono nella configurazione guidata e si cambiano quando vuoi in **Impostazioni → Ente e fiscalità → Dati e fiscalità**.

## Cosa serve

Sono **obbligatori**: la **denominazione**; la **forma dell'ente** («ASD – associazione sportiva dilettantistica», «APS – associazione di promozione sociale», «ODV – organizzazione di volontariato», «ETS – altro ente del Terzo settore», «Circolo», «Altro»); il **codice fiscale**; la **sede legale** (indirizzo, CAP, comune, provincia); l'**email** e il **telefono della segreteria**.

Per **partita IVA**, **numero RUNTS** e **PEC** scrivi il dato oppure, se non esiste, metti la spunta **«L'associazione non ha la partita IVA»**, **«L'associazione non è iscritta al RUNTS»** o **«L'associazione non ha una PEC»**.

Il **legale rappresentante** non si scrive qui: è la persona con la **carica di presidente**, che indichi tra le **Persone**. Se manca, la banda in alto ti porta lì con il pulsante **«Indica il presidente»**.

## Perché servono email e telefono della segreteria

I soci devono sapere **a chi scrivere**. Li trovano:

- nelle **email** che ricevono dal programma: la segreteria è l'indirizzo di risposta e l'email finisce con la riga «Segreteria: email · tel.»;
- nell'**informativa sulla privacy**;
- nell'**area soci**;
- nel **modulo di adesione online**: «Per esercitare i tuoi diritti scrivi a … o chiama …»;
- nel messaggio che legge il socio quando non riesce a scaricare una ricevuta.

## La banda gialla in alto

Finché manca qualche dato, in cima alle pagine del plugin c'è una **banda gialla fissa** (non si chiude): **«Mancano dei dati dell'associazione: …»**, con l'elenco di ciò che manca e il pulsante **«Completali ora»**. Chi non amministra legge «Chiedi a chi amministra di completarli.». La banda sparisce da sola quando è tutto compilato.

## Senza email e telefono della segreteria

La segreteria **non può inviare i messaggi scritti a mano**: comunicazioni, avvisi agli iscritti e ricevute inviate dall'operatore. Il programma dice: «Prima inserisci email e telefono della segreteria (Impostazioni › Ente): senza, la segreteria non può inviare messaggi ai soci.» (per le ricevute aggiunge: «Intanto scarica la ricevuta e consegnala a mano.»).

**Tutto ciò che il programma manda da solo parte comunque**: accessi e link, conferme, promemoria, avvisi di cambio o annullo lezione, ricevuta del pagamento online.

## Il pulsante WhatsApp

Usa il telefono della segreteria e compare solo se accendi la casella **«Pulsante WhatsApp»** in **Impostazioni → Soci e quote**: accendila solo se è un **cellulare su cui usate WhatsApp**.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'perche-vedo-la-banda-mancano-dei-dati',
        'title' => 'Perché vedo la banda gialla «Mancano dei dati dell\'associazione»?',
        'body' => <<<'MD'
È la **banda gialla in alto**: elenca i dati dell'ente che mancano (per esempio l'email della segreteria o il codice fiscale). Premi **«Completali ora»** e inseriscili. I dati fiscali non bloccano nulla; mancando **email e telefono della segreteria**, invece, non si possono inviare i messaggi scritti a mano. Vedi [Dati dell'ente e contatti della segreteria](guida:dati-dell-ente-e-contatti-della-segreteria).
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

**Cosa fare:** premi **«Vedi cosa fare»** (porta a **Impostazioni → Copia di sicurezza**, sezione «Cartella dei file riservati») e poi **«Controlla ora»**. Se il risultato è rosso, **inoltra il messaggio già pronto a chi gestisce il sito o l'hosting**: deve bloccare l'accesso diretto a quella cartella o spostarla fuori dalla parte pubblica del sito. Quando è sistemato, premi di nuovo **«Controlla ora»**.

Per chi gestisce il server: le istruzioni tecniche (cartella fuori dal sito, pulsante «Sposta i file») sono nella stessa pagina, sotto «Cosa fare se la cartella è raggiungibile (o per metterla al sicuro)».
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
- **non verificabile** (il sito è protetto da una password del server, la prova è stata mandata a un'altra pagina, il server ha risposto con un errore o nella cartella non si riesce a creare il file): **nessun allarme**.
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Manutenzione', 'slug' => 'cifratura-dei-file-riservati',
        'title' => 'Cifratura dei file riservati',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Nel **gratuito** si salvano **cifrati** i **documenti e le scansioni**, gli **allegati dei movimenti** e le **foto dell'inventario**. Con {piano_contabile} e {piano_fiscale} si cifrano anche i **certificati medici**, i **giustificativi dei rimborsi** e i **PDF delle ricevute** (emessi e «ANNULLATA»), che nella copia di sicurezza restano cifrati. Lo ZIP delle ricevute di un anno fiscale (**«Scarica i PDF di questo anno fiscale (ZIP)»**), invece, esce **non cifrato**: il programma avvisa «I PDF dello ZIP non sono cifrati e contengono dati personali (nome, codice fiscale, importi): conservalo con cura e cancellalo quando non ti serve più.» Si gestisce da **Impostazioni → Copia di sicurezza**, sezione «Cifratura dei file riservati».

<!-- DECISO da Luca il 15/11/2026 («Sì, da soli»): nel Pro i certificati medici già caricati si cifrano da soli dopo l'aggiornamento. La PR del plugin è in costruzione: TENERE questo segnaposto finché l'Audit non scrive che è unita, poi inserire le frasi a schermo (avvisi «partita / in corso / finita», cosa succede senza la libreria di cifratura). Luca: non pubblicare il plugin finché questa parte non c'è. -->
<!-- Verificato sul codice di main dopo la PR 745 del plugin (cifratura dei PDF delle ricevute). -->

## File nuovi e file già caricati

- I **file nuovi** si salvano cifrati **subito**.
- I **file già caricati** si cifrano quando l'**amministratore preme «Cifra i file già caricati»**. Con {piano_contabile} e {piano_fiscale} i **certificati medici** già caricati si cifrano **da soli** dopo l'aggiornamento; per gli altri file già caricati resta il pulsante. Prima di cominciare conviene fare una **copia di sicurezza con gli allegati** (il programma ti propone «Scarica la copia con gli allegati»). Da lì il lavoro prosegue da solo, a lotti, e puoi anche chiudere la pagina.

Su alcuni server manca la libreria che serve a cifrare: il programma lo dice e ti dà un **messaggio pronto da inoltrare a chi gestisce il sito**. In quel caso i certificati medici (con {piano_contabile} e {piano_fiscale}) non si possono caricare e gli altri file restano in chiaro, con un avviso.

## Dopo l'aggiornamento

- **Gratuito**: per i file già caricati premi **«Cifra i file già caricati»** in **Impostazioni → Copia di sicurezza**.
- **Con {piano_contabile} e {piano_fiscale}**: i certificati medici già caricati vengono cifrati da soli; per gli altri file già caricati premi **«Cifra i file già caricati»**.

## La frase per riaprire i documenti

La frase serve a **riaprire i documenti** se il sito viene spostato o riparato, se i codici di sicurezza del sito cambiano e per **ripristinare una copia di sicurezza su un altro sito**. **Conservala fuori dal sito**, su carta o in un gestore di password: se perdi il sito, altrimenti non la ritrovi.

- Nel **gratuito** è la **data e l'ora di attivazione** del plugin: «La frase è la data e l'ora di attivazione del plugin: {data}. Va scritta esattamente come la vedi qui, spazi e trattini compresi.» (c'è il pulsante **«Stampa»**).
- Con la **licenza** è il **codice di licenza**, che ritrovi nell'area clienti di Associazione Semplice; il codice di licenza apre sempre i documenti.

Dopo aver cambiato la frase, fai subito una **copia nuova**: quelle fatte prima si aprono con la frase di allora.

## Quanto protegge

Con le parole del programma: chi si portasse via la sola cartella dei file non può leggerli. Chi ha anche il database e il file di configurazione del sito può aprirli se conosce o indovina la frase. **La data di attivazione si indovina facilmente**; il codice di licenza **lo conosce anche Associazione Semplice** e sta nelle email di acquisto. Per le copie di sicurezza: **con la data di attivazione la copia è protetta da un'apertura per sbaglio, non da chi vuole aprirla.**

## Per proteggere di più

Scegli una **frase tua e lunga** (almeno quattro parole) con **«Cambia la frase»**; con **«Torna alla frase automatica»** torni indietro. Prima di cambiare i codici di sicurezza del sito, controlla di avere la frase.

*Per chi gestisce il sito:* la protezione più alta si ottiene con una frase propria e lunga e con la costante `ASEM_ENC_KEY` nel file `wp-config.php` (un testo lungo e casuale). Va conservata con cura: senza di essa e senza la frase i file non si aprono, a meno di avere il codice di licenza, che apre sempre. Se i codici `AUTH_KEY` e `AUTH_SALT` mancano o sono quelli di esempio, la chiave è più debole e il programma lo segnala.

## Se la chiave non si apre

Nella pagina **«Verifica delle ricevute»** (vedi [Ricevute in PDF e attestazione annuale](guida:ricevute-in-pdf)) compare il conteggio **«File cifrati che la chiave non apre»**, con il rimando a **Impostazioni → Copia di sicurezza → «Cifratura dei file riservati»**.


Se i codici di sicurezza sono cambiati o il database è stato copiato da un altro sito, il programma dice che la chiave non è valida su questo sito: i file cifrati non si leggono e i nuovi non si salvano finché non viene sistemata. Si usa la frase; per questo va conservata. Vedi anche [Copia di sicurezza e ripristino](guida:copia-di-sicurezza-e-ripristino).
MD,
    ],
];
