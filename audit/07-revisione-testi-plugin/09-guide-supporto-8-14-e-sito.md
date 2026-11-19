# Guide di supporto (part8–part14) e pagine del sito di supporto
File letti: 24 · Proposte: 109 (Alta 4, Media 56, Bassa 49)

File letti per intero: `supporto/database/guides/part8.php`, `part9.php`, `part10.php`, `part11.php`, `part13.php`, `part14.php` (27 guide del plugin, nessuna con `'topic' => 'gestionale'`); le 9 viste di `supporto/resources/views/site/`; le 7 di `supporto/resources/views/errors/`; le email `mail/ticket-received.blade.php` e `mail/staff-replied.blade.php`, con i due oggetti in `app/Mail/`. Per capire come i testi arrivano a schermo ho letto anche `supporto/app/Support/ArticleBody.php` e `database/seeders/GuideContentSeeder.php`: le voci «si vede a schermo» nascono dalla lettura di quel codice, non da una prova nel browser.

Nelle voci delle guide «Ora» riporta alla lettera la frase interessata, non tutta la riga (molte righe sono paragrafi interi).

## Difetti ricorrenti

- **Markdown che il sito non sa mostrare.** `ArticleBody` riconosce solo titoli `##`/`###`, elenchi, citazioni, tabelle, `**grassetto**`, `` `codice` `` e link. Tutto il resto esce come testo: i commenti HTML `<!-- … -->` (part14:165-166) compaiono nella guida; il corsivo con un asterisco solo mostra gli asterischi (part8:106, part9:36, part13:50, part14:196); l'elenco numerato rientrato dentro un punto elenco diventa un paragrafo unico (part11:79-81). Regola: nelle guide solo grassetto, mai corsivo, mai commenti, mai elenchi annidati.
- **Frasi da nota di rilascio.** «come prima», «ora puoi», «come nelle versioni precedenti», «non c'è più una pagina…», «elenco aggiornato dal Pro 1.1.37», «Dopo l'aggiornamento», «prima di questa funzione». Chi cerca «come si fa» non sa com'era prima. Punti: part8:75, 102; part9:128, 203, 278, 483, 508; part13:119, 127-129; part14:134, 138, 175-178. Regola: la guida descrive come funziona oggi; la storia sta nel changelog.
- **Lo stesso concetto con più nomi.** (a) Il software è «il programma», «il plugin», «il sito», a volte nella stessa guida (part9:36, 387, 404, 442 «il sito»; part11:56, 153 «il plugin»; part13 e part14 «il programma»). Regola proposta: «il programma» per ciò che fa il software, «il sito» solo per il sito web. (b) «area riservata» per l'area soci (part9:299, 322, 368), contro il glossario. (c) Il tutore «che opera» / «resta operativo» / «smette di agire» (part8:21, 59, 64; part9:499): usare sempre «agisce». (d) Il fondo «senza spese a supporto», «con documentazione da coprire», «con documentazione scoperta», «non documentato», «da documentare» (part11:56, 62, 69, 90): usare sempre «da documentare», come la colonna a schermo. (e) «a gruppi» e «a lotti» (part14:138, 145, 171).
- **Passi numerati che non sono passi.** Un numero contiene tre o quattro frasi, oppure è una conseguenza e non un'azione (part8:15, 37-38, 64, 79; part9:82; part10:44-45; part11:191-192). Regola: un'azione per numero; conseguenze e avvertenze in un paragrafo sotto l'elenco.
- **Frasi ripetute dentro la stessa guida o tra guide.** Sezioni che ridicono quanto scritto poco sopra (part9:349-352; part11:35-37, 181-186; part14:42, 175-178, 184, 191, 195-196) e formule di contorno ripetute in ogni guida Pro («Lo usano segreteria, presidente, vicepresidente e amministratori», «Senza Pro, o con la licenza non in regola, la voce sparisce; i dati restano»: part9:11, 156, 299; part10:11, 59, 97). Regola: ogni cosa una volta; la formula sulla licenza sempre identica e in fondo all'apertura (in part9:156 manca «; i dati restano»).
- **Percorsi di menu scritti in modi diversi.** A volte interi e in grassetto (**Impostazioni → Limiti e soglie**), a volte a metà e senza grassetto («in Limiti e soglie» part9:524, part11:83, 95; «in **Impostazioni**» part10:119; «Impostazioni → Privacy e regolamento» senza grassetto part9:339; «**Associazione Semplice → Registri → Convenzioni**» part9:299 contro «**Registri → …**» altrove). Regola: sempre il percorso intero, in grassetto, senza il nome del plugin davanti.
- **Doppia punteggiatura dopo un messaggio citato.** «…completarli.».» (part14:73), «…riallineare.».» (part11:40), «…scegline un altro.»:» (part10:33). Regola: se il messaggio citato finisce con il punto, dopo la » non si aggiunge altro.
- **Titoli che nominano l'argomento ma non cosa si impara.** Vedi le voci sui titoli (part11:52, part13:33, 59, part14:47, 134, 157). Attenzione: alcuni titoli sono ripresi come testo dei link `guida:` in altre guide; lo slug non cambia, ma il testo del link andrebbe allineato.

## Proposte

### supporto/database/guides/part8.php:15 · Media · guida `domanda-di-adesione-online`
- Ora: «Vai in **Impostazioni → Soci e quote → Domanda di adesione online** e attivala (è spenta di default). Se invece usi la [configurazione guidata](guida:completare-la-configurazione-guidata), la accende da sola scegliendo **«Chiunque può chiedere di iscriversi»** (e la spegne con **«Solo su presentazione»**): chi ha già configurato non cambia nulla finché non riapre la procedura.»
- Proposta: «Vai in **Impostazioni → Soci e quote → Domanda di adesione online** e attivala (è spenta di partenza).» E, sotto l'elenco: «Nella [configurazione guidata](guida:completare-la-configurazione-guidata) la domanda si accende scegliendo **«Chiunque può chiedere di iscriversi»** e si spegne con **«Solo su presentazione»**.»
- Perché: un passo, un'azione; «di default» è gergo (altrove è «di partenza»); l'ultima frase è una nota di rilascio.

### supporto/database/guides/part8.php:16 · Bassa · guida `domanda-di-adesione-online`
- Ora: «Indica, se vuoi, l'indirizzo per l'**Avviso alla segreteria**, a cui arriva un'email a ogni nuova domanda (se non lo indichi, va all'indirizzo di amministrazione del sito).»
- Proposta: «Se vuoi, in **Avviso alla segreteria** scrivi l'indirizzo che riceve un'email a ogni nuova domanda. Se lo lasci vuoto, l'email va all'amministratore del sito.»
- Perché: due frasi brevi al posto di una con relativa e parentesi.

### supporto/database/guides/part8.php:21 · Media · guida `domanda-di-adesione-online`
- Ora: «codice fiscale (con controllo di forma e di carattere finale), email, cellulare, tipo di socio (ordinario, e «socio e volontario» se lo hai acceso) la **liberatoria per foto e video** (per i minori risponde il tutore che opera)»
- Proposta: «codice fiscale (il modulo controlla che sia scritto bene), email, cellulare, tipo di socio (ordinario, e «socio e volontario» se lo hai acceso), la **liberatoria per foto e video** (per i minori risponde il genitore o tutore)»
- Perché: manca la virgola dopo la parentesi; «controllo di forma e di carattere finale» e «tutore che opera» sono gergo.

### supporto/database/guides/part8.php:25 · Bassa · guida `domanda-di-adesione-online`
- Ora: «**Nome e cognome** (anche del genitore o tutore) vanno da **1 a 60 caratteri**, con solo **lettere, spazi, apostrofi e trattini**.»
- Proposta: «**Nome e cognome** (anche del genitore o tutore) possono avere fino a **60 caratteri**: solo **lettere, spazi, apostrofi e trattini**.»
- Perché: «vanno da 1 a 60 caratteri, con solo» è scritto da programmatore.

### supporto/database/guides/part8.php:29 · Media · guida `domanda-di-adesione-online`
- Ora: «La domanda entra con stato **«richiesta»**: non compare in rubrica, libro soci, esportazioni, tessere né nei conti, non ha tessera né utente WordPress e non può usare il «Primo accesso».»
- Proposta: «Finché non la approvi, la domanda resta in stato **«richiesta»**: la persona non compare in rubrica, libro soci, esportazioni e conti, non ha la tessera né un accesso al sito e non può usare il «Primo accesso».»
- Perché: il soggetto passa dalla domanda alla persona senza dirlo; «utente WordPress» è gergo.

### supporto/database/guides/part8.php:36 · Bassa · guida `domanda-di-adesione-online`
- Ora: «Premi **Approva** e scegli il **numero di tessera**: ti viene proposto il successivo libero, controllando che non sia già assegnato. Oppure premi **Rifiuta**.»
- Proposta: «Premi **Approva** e conferma il **numero di tessera** (il programma propone il primo libero). Oppure premi **Rifiuta**.»
- Perché: passivo e gerundio senza soggetto; «libero» dice già che non è assegnato.

### supporto/database/guides/part8.php:37-38 · Media · guida `domanda-di-adesione-online`
- Ora: «3. Con l'approvazione la persona entra nel **libro soci** e al richiedente parte un'email con il **link per scegliere email e password**.» / «4. Con il rifiuto la domanda sparisce e a chi l'aveva inviata arriva un breve avviso.»
- Proposta: togliere i numeri 3 e 4 e farne due punti elenco sotto i passi: «- **Se approvi**, la persona entra nel **libro soci** e riceve un'email con il **link per scegliere email e password**.» / «- **Se rifiuti**, la domanda sparisce e chi l'aveva inviata riceve un breve avviso. Se non c'è stato un pagamento, non resta alcun dato personale.»
- Perché: non sono azioni ma conseguenze; «la persona», «il richiedente» e «chi l'aveva inviata» sono la stessa persona con tre nomi.

### supporto/database/guides/part8.php:42 · Media · guida `domanda-di-adesione-online`
- Ora: «La domanda viene confrontata con le persone già presenti, e con quelle in coda, per **email** e **codice fiscale**.»
- Proposta: «Il programma confronta **email** e **codice fiscale** con quelli delle persone già registrate e delle altre domande in attesa.»
- Perché: passivo; «in coda» è gergo e subito sotto si dice «in attesa».

### supporto/database/guides/part8.php:48 · Media · guida `domanda-di-adesione-online`
- Ora: «L'iscrizione all'anno fiscale **non si registra all'approvazione**: la tessera diventa valida **con l'incasso della quota**. La quota resta tra le voci da pagare, in sede o online dall'area soci.»
- Proposta: «Approvare non basta: la tessera diventa valida **quando incassi la quota**. Fino ad allora la quota resta tra le voci da pagare, in sede o online dall'area soci.»
- Perché: «l'iscrizione all'anno fiscale non si registra all'approvazione» è linguaggio interno; conta solo quando vale la tessera.

### supporto/database/guides/part8.php:59 · Media · guida `minori-con-genitori-o-tutori`
- Ora: «Sul sito però **opera uno solo, il primo**: è lui che gestisce per il minore tessera, prenotazioni, pagamenti e avvisi. Per i minori già registrati resta operativo il primo tutore già collegato. Nel **libro soci** il minore eredita le date (domanda, delibera) del tutore che opera.»
- Proposta: «Sul sito però **agisce solo il primo**: gestisce per il minore tessera, prenotazioni, pagamenti e avvisi. Per i minori già registrati vale il primo tutore già collegato. Nel **libro soci** il minore prende le date (domanda, delibera) di questo tutore.»
- Perché: «opera», «resta operativo», «il tutore che opera» sono burocratese; più sotto la stessa guida dice già «agire».

### supporto/database/guides/part8.php:64 · Media · guida `minori-con-genitori-o-tutori`
- Ora: «Scegli **uno o due tutori**: un socio o una persona di tipo **«genitore o tutore»**, anche **senza email**. Un tutore deve essere maggiorenne (se la sua data è nota). Un ospite non può essere tutore. Se ne indichi due, sul sito **opera solo il primo**.»
- Proposta: «Scegli **uno o due tutori**: soci o persone di tipo **«genitore o tutore»**, maggiorenni, anche **senza email**. Un ospite non può essere tutore.»
- Perché: quattro frasi in un passo; che agisce solo il primo è già detto tre righe sopra.

### supporto/database/guides/part8.php:67 · Bassa · guida `minori-con-genitori-o-tutori`
- Ora: «Il genitore o tutore di tipo **«Genitore o tutore (non socio)»** **non è socio**: non compare nel libro soci» … «Il minore può anche non avere né email né utente WordPress, e può usare l'email del genitore.»
- Proposta: «Chi è registrato come **«Genitore o tutore (non socio)»** non compare nel libro soci» … «Il minore può non avere né email né un accesso suo, oppure usare l'email del genitore.»
- Perché: «il genitore o tutore di tipo genitore o tutore non socio non è socio» si ripete tre volte; «utente WordPress» è gergo.

### supporto/database/guides/part8.php:71 · Bassa · guida `minori-con-genitori-o-tutori`
- Ora: «(il codice fiscale è facoltativo), e lo fa dichiarare come tale.»
- Proposta: «(il codice fiscale è facoltativo) e gli fa dichiarare di esserlo.»
- Perché: «dichiarare come tale» è burocratese.

### supporto/database/guides/part8.php:75 · Alta · guida `minori-con-genitori-o-tutori`
- Ora: «Se l'email del genitore o tutore è già quella di una **persona in anagrafica**, quando **approvi la domanda** il collegamento con il minore e le accettazioni (regolamento, informativa privacy e scelta sulle immagini, date **a nome del minore**) **non vengono attribuiti a lui da soli**: deve prima **confermare**. Se il genitore è **nuovo**, invece, **tutto va come prima**.»
- Proposta: «Se l'email del genitore o tutore è già quella di una **persona in anagrafica**, approvare la domanda non basta: prima il genitore deve **confermare**. Solo allora nascono il collegamento con il minore e le accettazioni date a nome del minore (regolamento, informativa privacy, scelta sulle immagini). Se il genitore è **nuovo**, non serve nessuna conferma.»
- Perché: frase di 55 parole con sette grassetti, quasi illeggibile; «tutto va come prima» non dice nulla a chi non sa com'era prima. Da confermare che «come prima» significhi «senza conferma».

### supporto/database/guides/part8.php:79 · Media · guida `minori-con-genitori-o-tutori`
- Ora: «3. **Il collegamento tra genitore e minore nasce solo dopo la conferma.** Finché non conferma, queste accettazioni non gli vengono attribuite.»
- Proposta: togliere il punto 3.
- Perché: non è un passo e ripete il paragrafo che introduce l'elenco.

### supporto/database/guides/part8.php:98 · Bassa · guida `minori-con-genitori-o-tutori`
- Ora: «Aggiungendo la loro email nella scheda, gli arriva l'invito per scegliere la password.»
- Proposta: «Aggiungi la loro email nella scheda: ricevono l'invito per scegliere la password.»
- Perché: gerundio slegato e «gli» per il plurale.

### supporto/database/guides/part8.php:102 · Bassa · guida `minori-con-genitori-o-tutori`
- Ora: «Se preferisci che il tutore continui anche dopo i 18 anni, come nelle versioni precedenti, spegni in»
- Proposta: «Se preferisci che il tutore continui anche dopo i 18 anni, spegni in»
- Perché: nota di rilascio.

### supporto/database/guides/part8.php:106 · Media · guida `minori-con-genitori-o-tutori`
- Ora: «Il libro soci e le esportazioni hanno le colonne *Data di nascita* e *Tutore*.»
- Proposta: «Il libro soci e le esportazioni hanno le colonne **Data di nascita** e **Tutore**.»
- Perché: il sito non mostra il corsivo: a schermo restano gli asterischi. Stesso difetto in part9:36 («*chi*», «*cosa*»: togliere gli asterischi), part13:50 («*persona* oppure *ente*»: usare «persona» oppure «ente») e part14:196.

### supporto/database/guides/part9.php:24-26 · Media · guida `votazioni-e-sondaggi`
- Ora: «Tutti i soci in regola con la tessera, oppure solo gli iscritti a una certa attività.» / «Il **diritto di voto** ce l'hanno **tutti i soci maggiorenni**.»
- Proposta: «Per ogni votazione scegli: tutti i soci in regola con la tessera, oppure solo gli iscritti a un'attività.» / «In ogni caso vota solo chi ha il **diritto di voto**, cioè **tutti i soci maggiorenni**.»
- Perché: i due paragrafi sembrano contraddirsi; va detto che il primo è la scelta per la singola votazione e il secondo la regola generale.

### supporto/database/guides/part9.php:75 · Bassa · guida `inventario-e-prestiti`
- Ora: «Non è il sistema delle **Risorse** prenotabili (sale, campi, armadietti, solo Pro): qui si tiene l'elenco dei beni.»
- Proposta: «Non confonderlo con le **Risorse** prenotabili (sale, campi, armadietti: solo Pro): qui tieni solo l'elenco dei beni.»
- Perché: «il sistema delle» è gergo; il tu al posto dell'impersonale.

### supporto/database/guides/part9.php:82 · Bassa · guida `inventario-e-prestiti`
- Ora: «4. Salva. Con l'esportazione in **CSV** scarichi l'elenco completo.»
- Proposta: «4. Salva.» E, sotto l'elenco: «Con **Esporta (CSV)** scarichi l'elenco completo.» (se il pulsante si chiama così, come nelle altre guide).
- Perché: l'esportazione non è parte del registrare un bene.

### supporto/database/guides/part9.php:97 · Media · guida `inventario-e-prestiti`
- Ora: «Lo storico dei prestiti si vede per bene e per persona.»
- Proposta: «Puoi vedere lo storico dei prestiti di ogni bene e di ogni persona.»
- Perché: «si vede per bene» si legge come «si vede bene».

### supporto/database/guides/part9.php:128 · Bassa · guida `archivio-documenti`
- Ora: «Word, Excel e LibreOffice si accettano **solo se il contenuto del file corrisponde al formato**: un file rinominato non passa. I documenti già caricati restano apribili.»
- Proposta: «Word, Excel e LibreOffice si accettano **solo se il file è davvero di quel formato**: un file a cui è stata solo cambiata l'estensione viene rifiutato.»
- Perché: «il contenuto corrisponde al formato» e «non passa» sono da tecnico; l'ultima frase è una nota di rilascio.

### supporto/database/guides/part9.php:138 · Bassa · guida `archivio-documenti`
- Ora: «chi non ha fatto l'accesso viene mandato al login, tranne per i documenti pubblici.»
- Proposta: «chi non ha fatto l'accesso viene mandato alla pagina di accesso, tranne che per i documenti pubblici.»
- Perché: «accesso» e «login» nella stessa frase.

### supporto/database/guides/part9.php:140 · Media · guida `archivio-documenti`
- Ora: «Se limiti **un contenuto del sito** (pagina o articolo) ai soci, chi non può leggerlo **non lo trova nemmeno cercando una parola del suo testo** e non vede i **commenti**, gli **allegati collegati** né le **descrizioni** che i plugin SEO mostrano nei motori di ricerca e nelle anteprime social. **Attenzione:** l'indirizzo diretto di un file messo nella Libreria media di WordPress resta raggiungibile da chi lo conosce. Per i file riservati usa questo **Archivio documenti**, che controlla i permessi a ogni apertura.»
- Proposta: «Se limiti ai soci **una pagina o un articolo del sito**, chi non può leggerlo non lo trova con la ricerca e non ne vede commenti, allegati e descrizioni (quelle che i plugin SEO mostrano nei motori di ricerca e nelle anteprime social). **Attenzione:** un file caricato nella Libreria media di WordPress resta raggiungibile da chi ne conosce l'indirizzo. Per i file riservati usa l'**Archivio documenti**.»
- Perché: prima frase di 50 parole con cinque grassetti; il finale ripete il paragrafo precedente.

### supporto/database/guides/part9.php:180 · Alta · guida `certificati-medici-sportivi`
- Ora: «Una volta al giorno il socio, o il suo tutore se è minorenne, riceve un'email quando il certificato sta per scadere e, una volta, quando è scaduto da poco.»
- Proposta: «Il socio, o il suo tutore se è minorenne, riceve un'email quando il certificato sta per scadere e un'altra quando è appena scaduto.»
- Perché: così sembra che il socio riceva un'email al giorno; due righe sotto la guida dice che ogni avviso parte una volta sola.

### supporto/database/guides/part9.php:183 · Bassa · guida `certificati-medici-sportivi`
- Ora: «**Preavviso**: i giorni prima della scadenza in cui il certificato risulta «in scadenza» (di partenza 30).»
- Proposta: «**Preavviso**: quanti giorni prima della scadenza il certificato diventa «in scadenza» (di partenza 30).»
- Perché: «i giorni prima della scadenza in cui» si capisce alla seconda lettura.

### supporto/database/guides/part9.php:189 · Media · guida `certificati-medici-sportivi`
- Ora: «lo vede solo la segreteria, con i suoi stessi permessi. Il file non ha un indirizzo pubblico e si scarica solo dal sito da chi è autorizzato.»
- Proposta: «lo vede solo la segreteria. Il file non ha un indirizzo pubblico: lo scarica solo chi è autorizzato, dopo l'accesso.»
- Perché: «con i suoi stessi permessi» non dice nulla; «si scarica solo dal sito da chi è autorizzato» è contorto.

### supporto/database/guides/part9.php:203 · Bassa · guida `affiliazioni-agli-enti`
- Ora: «oppure **«Altro ente»** con il nome scritto a mano (elenco aggiornato dal Pro 1.1.37).»
- Proposta: «oppure **«Altro ente»** con il nome scritto a mano.»
- Perché: il numero di versione è una nota di rilascio.

### supporto/database/guides/part9.php:260 · Media · guida `risorse-promemoria-qr-occupazione-richieste`
- Ora: «Non parte per chi prenota dentro la finestra scelta, per le prenotazioni provvisorie in attesa del pagamento online e per quelle annullate.»
- Proposta: «Non parte se si prenota a meno ore dall'inizio di quelle scelte, se la prenotazione aspetta ancora il pagamento online o se è annullata.»
- Perché: «dentro la finestra scelta» è gergo.

### supporto/database/guides/part9.php:264 · Media · guida `risorse-promemoria-qr-occupazione-richieste`
- Ora: «La quota associativa non è tra le voci scelte.»
- Proposta: «Tra le voci non c'è la quota associativa.»
- Perché: «le voci scelte» fa pensare a una scelta già fatta; si intende che non si può scegliere.

### supporto/database/guides/part9.php:271 · Media · guida `risorse-promemoria-qr-occupazione-richieste`
- Ora: «Per ogni risorsa a fasce vedi le ore **disponibili** (tra apertura e chiusura), **prenotate dai soci**, **occupate da corsi ed eventi**, **in allestimento** e **bloccate**, la **percentuale di occupazione** (allestimenti e blocchi si vedono a parte), gli incassi del periodo e i crediti spesi.»
- Proposta: «Per ogni risorsa a fasce vedi:» seguito da tre punti: «- le ore **disponibili** (tra apertura e chiusura), **prenotate dai soci**, **occupate da corsi ed eventi**, **in allestimento** e **bloccate**;» / «- la **percentuale di occupazione** (allestimenti e blocchi si vedono a parte);» / «- gli incassi del periodo e i crediti spesi.»
- Perché: frase di 45 parole con due parentesi; in elenco si scorre.

### supporto/database/guides/part9.php:278 · Media · guida `risorse-promemoria-qr-occupazione-richieste`
- Ora: «se è **occupata**, il socio si mette in coda come prima.»
- Proposta: «se è **occupata**, il socio si mette in lista d'attesa.»
- Perché: «come prima» è una nota di rilascio; due righe sotto la stessa cosa si chiama «lista d'attesa».

### supporto/database/guides/part9.php:294, 299, 322, 368 · Media · guide `convenzioni-per-i-soci` e `sms-ai-soci-e-consensi`
- Ora: «'section' => 'Area riservata'» (294, nome della sezione che il cliente vede) · «li mostrano ai soci giusti nell'area riservata» (299) · «Nell'**area riservata**, sezione **Convenzioni**» (322) · «nell'**area riservata**, sezione **«Le mie comunicazioni»**» (368)
- Proposta: «area soci» in tutti e quattro i punti («'section' => 'Area soci'», «nell'area soci», «Nell'**area soci**», «nell'**area soci**»).
- Perché: il glossario riserva «area riservata» all'account sul portale; le altre guide del lotto dicono già «area soci». Il nome della sezione va cambiato solo se anche le altre guide della stessa sezione, fuori dal mio lotto, passano allo stesso nome.

### supporto/database/guides/part9.php:299 · Bassa · guida `convenzioni-per-i-soci`
- Ora: «Le trovi in **Associazione Semplice → Registri → Convenzioni**.»
- Proposta: «Le trovi in **Registri → Convenzioni**.»
- Perché: tutte le altre guide scrivono il percorso senza il nome del plugin (anche il passo 1 di questa).

### supporto/database/guides/part9.php:312 · Media · guida `convenzioni-per-i-soci`
- Ora: «Dalla scheda puoi anche **archiviare** la convenzione (esce dall'area soci ma resta in elenco, e puoi **riportarla in elenco**) oppure **eliminarla**.»
- Proposta: «Dalla scheda puoi anche **archiviare** la convenzione: esce dall'area soci ma non si perde, e con **Riporta in elenco** torna attiva. Oppure puoi **eliminarla**.»
- Perché: «resta in elenco, e puoi riportarla in elenco» si contraddice.

### supporto/database/guides/part9.php:316 · Bassa · guida `convenzioni-per-i-soci`
- Ora: «**Solo quelle in corso** si vedono ai soci e sul sito.»
- Proposta: «**Solo quelle in corso** le vedono i soci e il pubblico.»
- Perché: «si vedono ai soci» non è italiano.

### supporto/database/guides/part9.php:332 · Media · guida `convenzioni-per-i-soci`
- Ora: «Le pagine con questi shortcode non finiscono nella cache di pagina, così le convenzioni scadute non restano visibili.»
- Proposta: «Le pagine con questi shortcode sono sempre aggiornate: una convenzione scaduta sparisce subito.»
- Perché: «cache di pagina» è gergo.

### supporto/database/guides/part9.php:339 · Bassa · guida `convenzioni-per-i-soci`
- Ora: «Non dipende dall'interruttore «Promemoria attivi» di Impostazioni → Privacy e regolamento.»
- Proposta: «Parte anche con i promemoria spenti («Promemoria attivi» in **Impostazioni → Privacy e regolamento**).»
- Perché: dice l'effetto pratico; il percorso va in grassetto come altrove.

### supporto/database/guides/part9.php:341 · Media · guida `convenzioni-per-i-soci`
- Ora: «**Una volta partito, l'avviso non si può rimandare.**» … «Per un'altra comunicazione usa le comunicazioni ai soci.»
- Proposta: «**Una volta partito, l'avviso non si può inviare di nuovo.**» … «Per scrivere ancora ai soci usa **Rubrica soci → Comunicazioni**.»
- Perché: «rimandare» si legge anche come «posticipare»; «per un'altra comunicazione usa le comunicazioni» gira a vuoto.

### supporto/database/guides/part9.php:349-352 · Media · guida `convenzioni-per-i-soci`
- Ora: la sezione «## Domande frequenti» con quattro voci («**Un socio non vede nessuna convenzione.**», «**La convenzione è scaduta ma serve ancora.**», «**Il logo non si vede.**», «**Il pubblico può vedere il codice?**»).
- Proposta: tenere solo «**Il logo non si vede.**», spostandola nel passo 4 di «Creare una convenzione» («Il logo deve essere un'immagine della libreria media: gli altri file vengono ignorati.»), e togliere la sezione.
- Perché: le altre tre ripetono le righe 316, 324 e 330; la guida è la più lunga del file e così torna scorribile.

### supporto/database/guides/part9.php:361 · Media · guida `sms-ai-soci-e-consensi`
- Ora: «Oltre alle email puoi scrivere ai soci per **SMS**, con il fornitore che preferisci. Il costo degli SMS è quello del fornitore scelto.»
- Proposta: «Oltre alle email puoi scrivere ai soci per **SMS**, con Brevo o Twilio. Gli SMS li paghi al fornitore.»
- Perché: «il fornitore che preferisci» promette più di quanto c'è: più sotto i fornitori sono due.

### supporto/database/guides/part9.php:391 · Bassa · guida `sms-ai-soci-e-consensi`
- Ora: «attiva **«Manda anche per SMS gli avvisi automatici»**: è **spento** di partenza.»
- Proposta: «attiva **«Manda anche per SMS gli avvisi automatici»** (di partenza è spenta).»
- Perché: concordanza: si parla dell'opzione.

### supporto/database/guides/part9.php:442, 448 · Bassa · guida `attivare-satispay-e-klarna`
- Ora: «Il sito crea e conserva da solo la **chiave di firma**.» · «In modalità reale il sito deve essere in **https**.»
- Proposta: «Al resto pensa il programma.» · «In modalità reale l'indirizzo del sito deve iniziare con **https**.»
- Perché: «chiave di firma» non serve al volontario; «essere in https» è gergo.

### supporto/database/guides/part9.php:461 · Media · guida `attivare-satispay-e-klarna`
- Ora: «Con Klarna acceso il sito **elenca a mano** i metodi (carta e Klarna): gli altri metodi attivati nel pannello di Stripe, come **Link**, **non compaiono**.»
- Proposta: «Con Klarna acceso il socio sceglie solo tra carta e Klarna: gli altri metodi attivati nel pannello di Stripe, come **Link**, **non compaiono**.»
- Perché: «elenca a mano i metodi» è la descrizione del codice, non di ciò che si vede.

### supporto/database/guides/part9.php:483 · Media · guida `libro-soci-completo`
- Ora: «Quando [chiudi l'anno fiscale](guida:aprire-e-chiudere-gli-anni-solari) il libro diventa **definitivo**: viene pubblicato e bloccato a quel momento e non cambia più (prima della chiusura è una **bozza**): lo trovi in **Registri → Libro soci**, scegliendo l'anno, e lo scarichi in PDF e in CSV.» … «Per gli anni fiscali chiusi prima di questa funzione, **«Emetti i documenti definitivi degli anni già chiusi»** (nella pagina Anni fiscali) li emette ora, con la dicitura»
- Proposta: «Finché l'anno è aperto il libro è una **bozza**; quando [chiudi l'anno fiscale](guida:aprire-e-chiudere-gli-anni-solari) diventa **definitivo** e non cambia più. Per vedere un anno, sceglilo in **Registri → Libro soci**.» … e, in un paragrafo a parte: «Per gli anni chiusi quando il libro definitivo non c'era ancora, premi **«Emetti i documenti definitivi degli anni già chiusi»** nella pagina Anni fiscali: il libro esce con la dicitura»
- Perché: due «:» in fila e una parentesi nella stessa frase; PDF e CSV sono già detti tre righe sopra; «prima di questa funzione» e «li emette ora» sono note di rilascio.

### supporto/database/guides/part9.php:495 · Bassa · guida `libro-soci-completo`
- Ora: «Per i soci che escono, registra la cessazione dalla scheda: restano nel libro con la data del recesso.»
- Proposta: «Per i soci che escono, registra il recesso dalla scheda: restano nel libro con quella data.»
- Perché: «uscita», «cessazione» e «recesso» nella stessa frase; in cima la guida parla di «recesso». Se a schermo il campo si chiama «cessazione», tenere quel nome in entrambi i punti.

### supporto/database/guides/part9.php:499 · Media · guida `libro-soci-completo`
- Ora: «Per un minore il libro riporta il tutore. Un minore può avere fino a due genitori o tutori, ma sul sito **opera solo il primo**: nel libro soci il minore eredita le date del tutore che opera (vedi [Iscrivere i minori con genitori o tutori](guida:minori-con-genitori-o-tutori)).»
- Proposta: «Per un minore il libro riporta il tutore che agisce per lui e ne prende le date (vedi [Iscrivere i minori con genitori o tutori](guida:minori-con-genitori-o-tutori)).»
- Perché: ripete la guida a cui rimanda, con il burocratese «opera».

### supporto/database/guides/part9.php:508 · Media · guida `registro-azioni-e-spazio-occupato`
- Ora: «Col tempo occupa spazio nel database: ora puoi vedere quanto e liberarlo.»
- Proposta: «Col tempo occupa spazio sul sito: qui vedi quanto, e come liberarlo.»
- Perché: «database» è gergo; «ora puoi» è una nota di rilascio.

### supporto/database/guides/part9.php:524 · Bassa · guida `registro-azioni-e-spazio-occupato`
- Ora: «Per cambiare il limite vai in **Limiti e soglie** e scegli un valore da **6 a 120 mesi**.»
- Proposta: «Per cambiare il limite vai in **Impostazioni → Limiti e soglie** e scegli un valore da **6 a 120 mesi**.»
- Perché: percorso intero, come nelle altre guide. Stessa correzione in part11:83 e part11:95 («in Limiti e soglie», senza grassetto).

### supporto/database/guides/part10.php:11 · Bassa · guida `assemblee-e-deleghe`
- Ora: «Lo trovi in **Registri → Assemblee**. Lo usano segreteria, presidente, vicepresidente e amministratori.»
- Proposta: «Le trovi in **Registri → Assemblee**. Le gestiscono segreteria, presidente, vicepresidente e amministratori.»
- Perché: il soggetto è «le assemblee».

### supporto/database/guides/part10.php:33 · Media · guida `assemblee-e-deleghe`
- Ora: «**Dall'area soci**, se il socio scelto non può ricevere la delega, il socio vede sempre lo stesso messaggio, «Non puoi scegliere questo socio come delegato: scegline un altro.»: non gli si dice come sta l'altro socio (se è iscritto, se ha già delegato, se ha già troppe deleghe). La **segreteria**, invece, vede il motivo preciso.»
- Proposta: «Dall'**area soci**, se il socio scelto non può ricevere la delega compare sempre lo stesso messaggio: «Non puoi scegliere questo socio come delegato: scegline un altro.» Il motivo (non è socio in regola, ha già delegato, ha già troppe deleghe) lo vede solo la **segreteria**.» Meglio in un paragrafo a parte, con le regole precedenti in elenco puntato.
- Perché: «il socio» indica due persone diverse nella stessa frase; «se è iscritto» usa «iscritto» per «socio»; doppia punteggiatura dopo la citazione.

### supporto/database/guides/part10.php:40 · Bassa · guida `assemblee-e-deleghe`
- Ora: «Il numero legale si scrive **assemblea per assemblea**, in percentuale degli aventi diritto.»
- Proposta: «Il numero legale si scrive **assemblea per assemblea**, in percentuale dei soci che hanno diritto di intervenire.»
- Perché: «aventi diritto» è burocratese; la sezione sopra si chiama «Chi ha diritto di intervenire».

### supporto/database/guides/part10.php:44 · Media · guida `assemblee-e-deleghe`
- Ora: «Dopo la chiusura l'assemblea **non si modifica più**. Anche le assemblee (anche quelle non svolte) di un **anno fiscale chiuso** non si modificano: per cambiarle va riaperto l'ultimo anno chiuso dalla pagina «Anni fiscali».»
- Proposta: lasciare nel passo 1 solo «Dopo la chiusura l'assemblea **non si modifica più**.» e spostare sotto l'elenco: «Nemmeno le assemblee di un **anno fiscale chiuso** si modificano, svolte o no: per cambiarle riapri l'ultimo anno chiuso dalla pagina «Anni fiscali».»
- Perché: il passo ha cinque frasi; «Anche… (anche…)» e «va riaperto» si alleggeriscono.

### supporto/database/guides/part10.php:45 · Bassa · guida `assemblee-e-deleghe`
- Ora: «Il verbale ha un **numero fisso** alla creazione (progressivo per anno fiscale della riunione), che non cambia e non viene mai riassegnato.»
- Proposta: «Alla creazione il verbale prende un **numero progressivo** nell'anno fiscale della riunione: non cambia più e non passa ad altri verbali.»
- Perché: «riassegnato» e la parentesi sono da tecnico.

### supporto/database/guides/part10.php:55 · Bassa · guida `bilancio-preventivo`
- Ora: «Preparare il bilancio preventivo e confrontarlo con la prima nota»
- Proposta: «Preparare il preventivo e confrontarlo con la prima nota»
- Perché: a schermo e nel resto della guida si chiama «preventivo»; il glossario sconsiglia «bilancio».

### supporto/database/guides/part10.php:59, 97 · Bassa · guide `bilancio-preventivo` e `rimborsi-ai-volontari`
- Ora: «Il preventivo ti fa scrivere quanto prevedi di incassare e spendere nell'anno e vedere, voce per voce, come va rispetto ai movimenti reali.» · «I rimborsi ti fanno gestire le richieste dei volontari dall'approvazione al pagamento, con registrazione in prima nota.»
- Proposta: «Nel preventivo scrivi quanto prevedi di incassare e spendere nell'anno e vedi, voce per voce, come va rispetto ai movimenti reali.» · «Qui gestisci le richieste di rimborso dei volontari, dall'approvazione al pagamento, che finisce da solo in prima nota.»
- Perché: «ti fa scrivere», «ti fanno gestire» sono giri inutili; «con registrazione in prima nota» è burocratese.

### supporto/database/guides/part10.php:63 · Bassa · guida `bilancio-preventivo`
- Ora: «scegli l'**anno** (ne hai uno per anno) e il titolo.»
- Proposta: «scegli l'**anno** (c'è un solo preventivo per anno) e il titolo.»
- Perché: «ne hai uno per anno» non dice di che cosa.

### supporto/database/guides/part10.php:106 · Media · guida `rimborsi-ai-volontari`
- Ora: «I limiti valgono **anche se più richieste arrivano nello stesso momento**. Se in quel momento ne sta arrivando un'altra, compare «Un'altra operazione è in corso: riprova tra qualche secondo.»: basta riprovare. Vale solo se l'**assemblea ha deliberato** i tipi di spesa.»
- Proposta: «Vale solo se l'**assemblea ha deliberato** i tipi di spesa. Se compare «Un'altra operazione è in corso: riprova tra qualche secondo.», riprova: il programma sta controllando un'altra richiesta.»
- Perché: «Vale solo se» è riferito all'autocertificazione ma arriva dopo due frasi su altro; il dettaglio sulle richieste contemporanee interessa solo chi ha scritto il codice.

### supporto/database/guides/part11.php:35-37 · Media · guida `inizio-dell-anno-fiscale`
- Ora: «compare il riquadro **«Cosa succede alle tessere»**: «Le tessere dei soci seguono l'anno fiscale. Cambiando il mese, le tessere degli anni fiscali ancora aperti che hanno un pagamento registrato si riallineano al nuovo anno fiscale: non si accorciano mai e quelle degli anni chiusi non si toccano.» Il riquadro dice anche quante tessere cambiano e quante risultano «sostituite» (restano registrate, i pagamenti non cambiano).» / «Si riallineano **solo le tessere con un pagamento registrato**: le allunga fino alla fine dell'anno fiscale in cui scadono, **mai le accorcia**.»
- Proposta: «compare il riquadro **«Cosa succede alle tessere»**, che dice quante tessere cambiano e quante risultano «sostituite». In breve:» / «Si riallineano **solo le tessere con un pagamento registrato** degli anni ancora aperti: si allungano fino alla fine dell'anno fiscale in cui scadono, **non si accorciano mai**.»
- Perché: il testo del riquadro è citato per intero e subito ripetuto nei punti; «le allunga… mai le accorcia» non ha soggetto.

### supporto/database/guides/part11.php:40 · Bassa · guida `inizio-dell-anno-fiscale`
- Ora: «Se nessuna tessera cambia, leggi «Nessuna tessera da riallineare.». Altrimenti, a cambio fatto, «Tessere riallineate: N. Tessere sostituite: S.»»
- Proposta: «Se nessuna tessera cambia, leggi «Nessuna tessera da riallineare.» Altrimenti, a cambio fatto, leggi «Tessere riallineate: N. Tessere sostituite: S.»»
- Perché: doppio punto dopo la citazione; alla seconda frase manca il verbo.

### supporto/database/guides/part11.php:52 · Bassa · guida `spese-documentate-e-fondi-dei-rimborsi`
- Ora: «Spese documentate e fondi: l'attribuzione automatica»
- Proposta: «Collegare le spese con scontrino ai fondi dei rimborsi»
- Perché: il titolo attuale nomina tre concetti senza dire cosa si fa.

### supporto/database/guides/part11.php:56 · Media · guida `spese-documentate-e-fondi-dei-rimborsi`
- Ora: «il plugin la attribuisce da solo al fondo dei rimborsi che ha **accantonato ma non ha ancora spese a supporto**.»
- Proposta: «il plugin la attribuisce da solo al fondo dei rimborsi che ha **soldi accantonati ancora da documentare**.»
- Perché: «ha accantonato ma non ha ancora spese a supporto» non si capisce; «da documentare» è il nome della colonna a schermo.

### supporto/database/guides/part11.php:62 · Media · guida `spese-documentate-e-fondi-dei-rimborsi`
- Ora: «oppure che non lo è ancora: succede da sola quando c'è un documento allegato e un fondo con documentazione da coprire.»
- Proposta: «oppure che non lo è ancora: l'attribuzione avviene da sola appena ci sono un documento allegato e un fondo da documentare.»
- Perché: «succede da sola» non ha soggetto; «documentazione da coprire» è il terzo nome dello stesso concetto. Stessa correzione alla riga 69: «con documentazione scoperta» → «ancora da documentare».

### supporto/database/guides/part11.php:75 · Bassa · guida `spese-documentate-e-fondi-dei-rimborsi`
- Ora: «Un **beneficiario scelto esplicitamente** vale sempre.»
- Proposta: «Se il **beneficiario lo scegli tu**, vale sempre la tua scelta.»
- Perché: «scelto esplicitamente» è da programmatore.

### supporto/database/guides/part11.php:77-81 · Alta · guida `spese-documentate-e-fondi-dei-rimborsi`
- Ora: «- Il **volontario** va **solo sui suoi fondi**. Ciò che supera resta **in credito**. Il credito si chiude da solo con i futuri incassi dei suoi corsi; per sanarlo subito:» seguito dai tre passi rientrati «  1. vai in **Cassa → Conti e fondi** e trova il fondo del volontario;» «  2. apri **«Accantona una somma»** (aggiunge soldi al fondo e li sottrae dalla disponibilità reale);» «  3. scrivi l'importo nel campo **«0,00 €»** e premi **«Accantona»**.»
- Proposta: chiudere il punto così: «- Le spese di un **volontario** vanno **solo sui suoi fondi**. La parte che li supera resta **in credito** e si chiude da sola con i prossimi incassi dei suoi corsi.» e spostare i tre passi fuori dall'elenco puntato, sotto un titolo «### Chiudere subito il credito di un volontario», senza rientro: «1. Vai in **Cassa → Conti e fondi** e trova il fondo del volontario.» «2. Apri **«Accantona una somma»** (aggiunge soldi al fondo e li sottrae dalla disponibilità reale).» «3. Scrivi l'importo nel campo **«0,00 €»** e premi **«Accantona»**.»
- Perché: il sito non riconosce gli elenchi rientrati: i tre passi escono come un paragrafo unico «1. vai… 2. apri… 3. scrivi…». In più «il volontario va sui suoi fondi» e «sanarlo» sono poco chiari.

### supporto/database/guides/part11.php:125, 153 · Bassa · guide `bilancio-provvisorio-e-avvisi-di-bilancio` e `verifiche-di-fine-anno-e-chiusura`
- Ora: «se tutto è a posto non c'è nessun messaggio verde.» · «L'avviso compare **solo se ci sono discrepanze**, senza messaggi verdi.»
- Proposta: «se tutto è a posto non compare nulla.» · «L'avviso compare **solo se ci sono discrepanze**.»
- Perché: «messaggio verde» è linguaggio di chi disegna le schermate.

### supporto/database/guides/part11.php:134 · Media · guida `bilancio-provvisorio-e-avvisi-di-bilancio`
- Ora: «A ogni fine mese un lavoro giornaliero **salva una foto** del consuntivo contro il preventivo, che poi **non cambia**.»
- Proposta: «A ogni fine mese il programma **salva da solo una foto** del consuntivo contro il preventivo, che poi **non cambia**.»
- Perché: «un lavoro giornaliero» è gergo tecnico.

### supporto/database/guides/part11.php:157 · Media · guida `verifiche-di-fine-anno-e-chiusura`
- Ora: «**Preavviso**: parte **3 mesi prima** della fase fissa (di partenza) e si può **chiudere**. La chiusura vale per la settimana corrente: ricompare il lunedì, ed è per utente.»
- Proposta: «**Preavviso**: di partenza compare **3 mesi prima** dell'avviso fisso e lo puoi **chiudere**: resta chiuso solo per te e ricompare il lunedì.»
- Perché: «parte… (di partenza)», «fase fissa», «è per utente» sono gergo; «la chiusura» in questa guida è la chiusura dell'anno.

### supporto/database/guides/part11.php:158 · Media · guida `verifiche-di-fine-anno-e-chiusura`
- Ora: «Di partenza parte dal **mese prima dell'ultimo mese** dell'anno fiscale: con l'anno da gennaio a dicembre il 1° novembre, con l'anno da settembre il 1° luglio.»
- Proposta: «Di partenza compare dal **penultimo mese** dell'anno fiscale: il 1° novembre se l'anno va da gennaio a dicembre, il 1° luglio se inizia a settembre.»
- Perché: «Di partenza parte» e «il mese prima dell'ultimo mese» inciampano.

### supporto/database/guides/part11.php:178 · Bassa · guida `verifiche-di-fine-anno-e-chiusura`
- Ora: «Nella riga della discrepanza, se vuoi, scrivi il campo **«Nota (facoltativa)»**.»
- Proposta: «Nella riga della discrepanza, se vuoi, compila il campo **«Nota (facoltativa)»**.»
- Perché: un campo si compila, non si scrive.

### supporto/database/guides/part11.php:181-185 · Media · guida `verifiche-di-fine-anno-e-chiusura`
- Ora: «la discrepanza torna «da verificare». Se l'importo cambia, l'approvazione **decade da sola**.» / «- L'approvazione vale **solo per quell'importo**: se l'importo cambia, la discrepanza torna.» / «- Restano elencate in **Contabilità → Bilancio provvisorio → «Discrepanze da verificare»**.»
- Proposta: togliere dal paragrafo la frase «Se l'importo cambia, l'approvazione **decade da sola**.» e togliere il punto «Restano elencate in…».
- Perché: lo stesso fatto è scritto due volte a distanza di due righe; che le approvate restano elencate è la prima frase del paragrafo.

### supporto/database/guides/part11.php:194 · Bassa · guida `verifiche-di-fine-anno-e-chiusura`
- Ora: «Dopo la chiusura le regolarizzazioni si fanno da **prima nota**.»
- Proposta: «Dopo la chiusura, le correzioni si fanno dalla **prima nota**.»
- Perché: «regolarizzazioni» è burocratese.

### supporto/database/guides/part13.php:33, 59 · Bassa · guide `soci-che-sono-enti` e `il-dipendente-e-i-suoi-permessi`
- Ora: «Soci che sono enti (associazioni, società, enti pubblici)» · «Il dipendente e i suoi permessi»
- Proposta: «Registrare come socio un ente (associazione, società, ente pubblico)» · «Creare un dipendente e scegliere cosa può fare»
- Perché: titoli che dicono cosa si impara. I due titoli sono ripresi come testo di link in part9:487, part13:18, 20: lo slug resta, il testo del link va allineato.

### supporto/database/guides/part13.php:19 · Bassa · guida `dati-per-il-runts`
- Ora: «a titolo di aiuto la pagina mostra quanti soci di tipo «Socio e volontario» c'erano.»
- Proposta: «come aiuto, la pagina mostra quanti soci di tipo «Socio e volontario» c'erano.»
- Perché: «a titolo di» è burocratese.

### supporto/database/guides/part13.php:50 · Media · guida `soci-che-sono-enti`
- Ora: «aggiungi la colonna **Tipo soggetto** (*persona* oppure *ente*), la **Denominazione** al posto di Nome e Cognome, il **Codice fiscale** dell'ente (11 cifre, oppure 16 caratteri) e, se serve, **Stessa sezione RUNTS** (sì o no).»
- Proposta: «aggiungi queste colonne:» e quattro punti: «- **Tipo soggetto**: «persona» oppure «ente»;» / «- **Denominazione**, al posto di Nome e Cognome;» / «- **Codice fiscale** dell'ente (11 cifre, oppure 16 caratteri);» / «- **Stessa sezione RUNTS** (sì o no), se serve.»
- Perché: frase di 45 parole con tre parentesi; in elenco si controlla colonna per colonna. Gli asterischi del corsivo a schermo restano visibili.

### supporto/database/guides/part13.php:63 · Bassa · guida `il-dipendente-e-i-suoi-permessi`
- Ora: «Il **Dipendente** è un tipo di utente a parte: è un utente del sito che **parte con gli stessi permessi della segreteria**, ma **non è un socio**.»
- Proposta: «Il **Dipendente** è un utente del sito che **parte con gli stessi permessi della segreteria**, ma **non è un socio**.»
- Perché: «è un tipo di utente a parte: è un utente» dice due volte la stessa cosa.

### supporto/database/guides/part13.php:113 · Media · guida `il-dipendente-e-i-suoi-permessi`
- Ora: «Il dipendente **non lo ha mai**, qualunque spunta abbia: tutte le **impostazioni**» … «Restano fuori anche le azioni personali di ciascuno (chiudere un avviso, la guida iniziale, le notifiche del proprio dispositivo) e quelle dell'area soci.»
- Proposta: «Queste funzioni il dipendente **non le ha mai**, qualunque spunta abbia: tutte le **impostazioni**» … «Non dipendono dalle spunte nemmeno le azioni personali di ciascuno (chiudere un avviso, la guida iniziale, le notifiche del proprio dispositivo) e quelle dell'area soci.»
- Perché: «non lo ha mai» non si riferisce a nulla; «restano fuori» si legge sia come «sono vietate» sia come «non c'entrano». Ho scelto la seconda lettura: da confermare.

### supporto/database/guides/part13.php:119 · Bassa · guida `il-dipendente-e-i-suoi-permessi`
- Ora: «**non danno accesso al plugin**: non c'è più una pagina «Ruoli e accessi» per darglielo.»
- Proposta: «**non danno accesso al plugin**.»
- Perché: nota di rilascio su una pagina che il lettore non vede.

### supporto/database/guides/part13.php:127-129 · Media · guida `il-dipendente-e-i-suoi-permessi`
- Ora: «## Se prima aveva dei permessi tolti» / «Quando i permessi sono passati all'assegnazione per area, in Bacheca compare a chi può gestire i dipendenti l'avviso **«Rivedi i permessi dei dipendenti: ora si danno per area.»** (solo se esiste almeno un dipendente). Non si chiude da solo: sparisce quando apri la pagina **Dipendenti**. Serve a controllare chi aveva dei permessi tolti prima.»
- Proposta: «## L'avviso «Rivedi i permessi dei dipendenti»» / «Chi gestisce i dipendenti può trovare in Bacheca l'avviso **«Rivedi i permessi dei dipendenti: ora si danno per area.»** Controlla i permessi di ciascuno nella pagina **Dipendenti**: aprendola, l'avviso sparisce.»
- Perché: titolo e testo raccontano la storia del programma; al lettore serve sapere cosa fare davanti all'avviso.

### supporto/database/guides/part14.php:42 · Bassa · guida `restituire-un-pagamento-online-al-socio`
- Ora: «## Chi può farlo» / «**L'amministratore, la Segreteria e il Dipendente.**»
- Proposta: togliere la sezione.
- Perché: è già scritto in «Dove si fa», in cima alla guida.

### supporto/database/guides/part14.php:47, 134, 157 · Bassa · guide `dati-dell-ente-e-contatti-della-segreteria`, `cosa-succede-ai-certificati-medici-dopo-l-aggiornamento`, `cifratura-dei-file-riservati`
- Ora: «Dati dell'ente e contatti della segreteria» · «Cosa succede ai certificati medici dopo l'aggiornamento?» · «Cifratura dei file riservati»
- Proposta: «Completare i dati dell'ente e i contatti della segreteria» · «Come vengono cifrati i certificati medici già caricati?» · «Proteggere i file riservati con la cifratura»
- Perché: titoli che dicono cosa si impara; «dopo l'aggiornamento» fra sei mesi non dirà più di quale aggiornamento si parla. I titoli sono ripresi nei link di part14:90, 97, 151, 171: allineare il testo dei link.

### supporto/database/guides/part14.php:55 · Media · guida `dati-dell-ente-e-contatti-della-segreteria`
- Ora: «Sono **obbligatori**: la **denominazione**; la **forma dell'ente** («ASD – associazione sportiva dilettantistica», «APS – associazione di promozione sociale», «ODV – organizzazione di volontariato», «ETS – altro ente del Terzo settore», «Circolo», «Altro»); il **codice fiscale**; la **sede legale** (indirizzo, CAP, comune, provincia); l'**email** e il **telefono della segreteria**.»
- Proposta: «Sono **obbligatori**: **denominazione**, **forma dell'ente** (ASD, APS, ODV, ETS, Circolo o Altro), **codice fiscale**, **sede legale** (indirizzo, CAP, comune, provincia), **email** e **telefono della segreteria**.»
- Perché: le sei voci del menu citate per esteso nascondono l'elenco dei dati; chi è un'ASD sa cosa vuol dire.

### supporto/database/guides/part14.php:77-79 · Media · guida `dati-dell-ente-e-contatti-della-segreteria`
- Ora: «comunicazioni, avvisi agli iscritti e ricevute inviate dall'operatore.» · «accessi e link, conferme, promemoria, avvisi di cambio o annullo lezione, ricevuta del pagamento online.»
- Proposta: «comunicazioni, avvisi agli iscritti e ricevute inviate a mano.» · «inviti e link di accesso, conferme, promemoria, avvisi di lezione spostata o annullata, ricevuta del pagamento online.»
- Perché: il glossario esclude «operatore» (è la segreteria); «annullo lezione» è linguaggio da modulo.

### supporto/database/guides/part14.php:83 · Bassa · guida `dati-dell-ente-e-contatti-della-segreteria`
- Ora: «compare solo se accendi la casella **«Pulsante WhatsApp»** in **Impostazioni → Soci e quote**: accendila solo se è un **cellulare su cui usate WhatsApp**.»
- Proposta: «compare solo se spunti la casella **«Pulsante WhatsApp»** in **Impostazioni → Soci e quote**: fallo solo se è un **cellulare su cui la segreteria usa WhatsApp**.»
- Perché: una casella si spunta; «usate» è l'unico voi del lotto.

### supporto/database/guides/part14.php:90 · Bassa · guida `perche-vedo-la-banda-mancano-dei-dati`
- Ora: «I dati fiscali non bloccano nulla; mancando **email e telefono della segreteria**, invece, non si possono inviare i messaggi scritti a mano.»
- Proposta: «Se mancano i dati fiscali non si blocca nulla; senza **email e telefono della segreteria**, invece, non puoi inviare i messaggi scritti a mano.»
- Perché: «i dati fiscali non bloccano nulla» si legge al contrario; gerundio e impersonale si evitano.

### supporto/database/guides/part14.php:129 · Media · guida `cosa-fa-controlla-ora-sulla-cartella-dei-file`
- Ora: «nella cartella non si riesce a creare il file): **nessun allarme**.»
- Proposta: «nella cartella non si riesce a creare il file): **non è un allarme**, ma il controllo non è riuscito. Chiedi conferma a chi gestisce il server.»
- Perché: «nessun allarme» non dice se la cartella è al sicuro né cosa fare; il consiglio è lo stesso del punto «il file di prova non si scarica». Unica proposta più lunga dell'originale: manca il «cosa fare dopo».

### supporto/database/guides/part14.php:138 · Bassa · guida `cosa-succede-ai-certificati-medici-dopo-l-aggiornamento`
- Ora: «Parte alla **prima visita di un amministratore** o **entro un'ora**, e prosegue a gruppi, **un gruppo al minuto**. Se si interrompe, riprende da dove era arrivata.»
- Proposta: «La cifratura parte alla **prima visita di un amministratore** o **entro un'ora**, e prosegue un po' alla volta, **un gruppo di file al minuto**. Se si interrompe, riprende da dove era arrivata.»
- Perché: manca il soggetto («Parte» che cosa?).

### supporto/database/guides/part14.php:146-147 · Bassa · guida `cosa-succede-ai-certificati-medici-dopo-l-aggiornamento`
- Ora: «tutti i certificati già caricati sono ora cifrati sul disco.» · «**Finita con residui**: il programma dice quanti non si sono potuti cifrare e quanti non sono stati trovati sul disco. I certificati non cifrati sono rimasti dove stavano, in chiaro: l'elenco è in»
- Proposta: «tutti i certificati già caricati sono ora cifrati.» · «**Finita, ma non per tutti**: il programma dice quanti certificati non si sono potuti cifrare e quanti file non ha trovato. I certificati non cifrati sono rimasti dove stavano: l'elenco è in»
- Perché: «sul disco», «residui», «in chiaro» sono gergo; «non cifrati… in chiaro» dice due volte la stessa cosa.

### supporto/database/guides/part14.php:163 · Media · guida `cifratura-dei-file-riservati`
- Ora: il paragrafo unico da «Nel **gratuito** si salvano **cifrati**» fino a «Si gestisce da **Impostazioni → Copia di sicurezza**, sezione «Cifratura dei file riservati».» (oltre 100 parole).
- Proposta: aprire con «Si gestisce da **Impostazioni → Copia di sicurezza**, sezione «Cifratura dei file riservati».», poi due punti elenco («- **Gratuito**: documenti e scansioni, allegati dei movimenti, foto dell'inventario.» / «- **Con {piano_contabile} e {piano_fiscale}**: anche certificati medici, giustificativi dei rimborsi e PDF delle ricevute (emessi e «ANNULLATA»), che nella copia di sicurezza restano cifrati.»), e in un paragrafo a parte la frase sullo ZIP: «Lo ZIP delle ricevute di un anno fiscale (**«Scarica i PDF di questo anno fiscale (ZIP)»**) esce invece **non cifrato**: conservalo con cura e cancellalo quando non ti serve più.»
- Perché: non si scorre in un minuto; dove si gestisce è l'informazione che serve per prima e sta in fondo. Il messaggio del programma citato per intero ripete la frase che lo introduce.

### supporto/database/guides/part14.php:165-166 · Alta · guida `cifratura-dei-file-riservati`
- Ora: «<!-- Certificati medici: frasi riverificate sul codice di main dopo la PR 754 del plugin (FileCryptPanel::cert_parts), 15/11/2026. -->» / «<!-- Verificato sul codice di main dopo la PR 745 del plugin (cifratura dei PDF delle ricevute). -->»
- Proposta: togliere le due righe dal testo della guida (se servono, diventano commenti PHP fuori dalla stringa).
- Perché: il sito non riconosce i commenti HTML e li mostra come testo: il cliente legge due note interne con numeri di PR e nomi di classi.

### supporto/database/guides/part14.php:171 · Media · guida `cifratura-dei-file-riservati`
- Ora: «Dettagli e avvisi: [Cosa succede ai certificati medici dopo l'aggiornamento?](guida:cosa-succede-ai-certificati-medici-dopo-l-aggiornamento) Prima di cominciare conviene fare una **copia di sicurezza con gli allegati** (il programma ti propone «Scarica la copia con gli allegati»). Da lì il lavoro prosegue da solo, a lotti, e puoi anche chiudere la pagina.»
- Proposta: chiudere il punto elenco dopo il link e portare il resto in un paragrafo sotto: «Prima di premere il pulsante fai una **copia di sicurezza con gli allegati** (il programma ti propone «Scarica la copia con gli allegati»). Poi il lavoro prosegue da solo, un po' alla volta: puoi chiudere la pagina.»
- Perché: il punto elenco ha cinque frasi e «Prima di cominciare» non dice cosa; «Da lì» e «a lotti» sono poco chiari.

### supporto/database/guides/part14.php:175-178 · Media · guida `cifratura-dei-file-riservati`
- Ora: la sezione «## Dopo l'aggiornamento» con i due punti «**Gratuito**: per i file già caricati premi **«Cifra i file già caricati»**…» e «**Con {piano_contabile} e {piano_fiscale}**: i certificati medici già caricati vengono cifrati da soli; per gli altri file già caricati premi **«Cifra i file già caricati»**.»
- Proposta: togliere la sezione.
- Perché: ripete parola per parola la sezione «File nuovi e file già caricati» che la precede.

### supporto/database/guides/part14.php:182 · Media · guida `cifratura-dei-file-riservati`
- Ora: «**Conservala fuori dal sito**, su carta o in un gestore di password: se perdi il sito, altrimenti non la ritrovi.»
- Proposta: «**Conservala fuori dal sito**, su carta o in un gestore di password: altrimenti, se perdi il sito, non la ritrovi.»
- Perché: l'ordine delle parole rende la frase incomprensibile.

### supporto/database/guides/part14.php:184 · Media · guida `cifratura-dei-file-riservati`
- Ora: «Nel **gratuito** è **la data e l'ora in cui è stata creata la chiave dei file, in UTC**: «La frase è la data e l'ora in cui è stata creata la chiave dei file, in UTC: {data}. Non è la data di installazione del plugin. Va scritta esattamente così, spazi e trattini compresi.» (c'è il pulsante **«Stampa»**). **Non è la data di installazione del plugin.**»
- Proposta: «Nel **gratuito** il programma te la mostra così: «La frase è la data e l'ora in cui è stata creata la chiave dei file, in UTC: {data}. Non è la data di installazione del plugin. Va scritta esattamente così, spazi e trattini compresi.» (c'è il pulsante **«Stampa»**).»
- Perché: la stessa informazione è scritta tre volte nella stessa riga (prima, dentro e dopo la citazione).

### supporto/database/guides/part14.php:187 · Bassa · guida `cifratura-dei-file-riservati`
- Ora: «Conservale con cura o cancellale; fai anche una **copia nuova**.»
- Proposta: «Le copie vecchie conservale con cura o cancellale, e fai una **copia nuova**.»
- Perché: «Conservale» è lontano dal nome a cui si riferisce (le copie): sembra riferito alle frasi.

### supporto/database/guides/part14.php:191 · Media · guida `cifratura-dei-file-riservati`
- Ora: «Nel plugin senza licenza, con una **frase propria**, servono anche il file `wp-config.php` o la frase. La frase serve a te, per riaprire i documenti su un altro sito o dopo un cambio dei codici di sicurezza.»
- Proposta: «Nel plugin senza licenza, con una **frase tua**, servono anche il file `wp-config.php` o la frase.»
- Perché: «frase propria» qui e «frase tua» quattro righe sotto; l'ultima frase ripete l'apertura della sezione «La frase per riaprire i documenti».

### supporto/database/guides/part14.php:195-196 · Media · guida `cifratura-dei-file-riservati`
- Ora: «**In ogni caso** conviene la costante `ASEM_ENC_KEY` in `wp-config.php`, che sta fuori dal database (vedi sotto, «per chi gestisce il sito»).» … / «*Per chi gestisce il sito:* la protezione più alta si ottiene con una frase propria e lunga e con la costante `ASEM_ENC_KEY` nel file `wp-config.php` (un testo lungo e casuale).»
- Proposta: riga 195: «**In ogni caso** chiedi a chi gestisce il sito di aggiungere la costante `ASEM_ENC_KEY` (vedi sotto).» Riga 196, preceduta da una riga vuota: «**Per chi gestisce il sito:** la protezione più alta si ottiene aggiungendo al file `wp-config.php` la costante `ASEM_ENC_KEY` (un testo lungo e casuale), che sta fuori dal database.»
- Perché: la stessa istruzione è data due volte di fila; senza riga vuota i due paragrafi a schermo si fondono; il corsivo mostra gli asterischi.

### supporto/database/guides/part14.php:203 · Bassa · guida `cifratura-dei-file-riservati`
- Ora: «Si usa la frase; per questo va conservata.»
- Proposta: «Per sistemarla serve la frase: per questo va conservata.»
- Perché: «si usa la frase» non dice per fare cosa.

### supporto/database/guides/part14.php:217 · Media · guida `compare-errore-imprevisto-con-un-codice`
- Ora: «Dì anche **che cosa stavi facendo** (per esempio «registravo un incasso»).»
- Proposta: «Scrivi anche **che cosa stavi facendo** (per esempio «registravo un incasso»).»
- Perché: refuso: l'imperativo di «dire» è «di'», non «dì»; «scrivi» evita il problema ed è il verbo giusto per una richiesta scritta.

### supporto/resources/views/site/index.blade.php:19 · Bassa · sito di supporto, pagina iniziale senza argomenti
- Ora: «Non trovi una guida? Scrivici: la risposta arriva per email.»
- Proposta: «Le guide stanno arrivando.»
- Perché: subito sotto il riquadro fisso dice già «Non trovi quello che cerchi? Scrivici: ti rispondiamo per email.»: due inviti quasi uguali uno sopra l'altro.

### supporto/resources/views/site/ticket-new.blade.php:18, 21, 25 · Bassa · sito di supporto, modulo «Apri una richiesta»
- Ora: «<option value="">—</option>» (tre menu: prodotto, argomento, piano)
- Proposta: «<option value="">Scegli…</option>»
- Perché: il trattino da solo non dice che c'è una scelta da fare, e «Quale prodotto usi?» è obbligatorio.

### supporto/resources/views/errors/403.blade.php:3 · Media · sito di supporto, pagina di errore
- Ora: «Per entrare serve un account con i permessi giusti. Torna al centro assistenza.»
- Proposta: «Per entrare serve un account con i permessi giusti. Torna al tuo account o alla home.»
- Perché: la pagina offre solo i pulsanti «Torna al tuo account» e «Vai alla home»: il centro assistenza non è raggiungibile da lì.

### supporto/resources/views/errors/404.blade.php:3 · Bassa · sito di supporto, pagina di errore
- Ora: «L\'indirizzo non esiste più o è stato scritto in modo diverso.»
- Proposta: «L\'indirizzo non esiste più o contiene un errore.»
- Perché: «scritto in modo diverso» non dice diverso da che cosa.

### supporto/resources/views/errors/419.blade.php:3 · Media · sito di supporto, pagina di errore
- Ora: «Il modulo è rimasto aperto troppo a lungo ed è scaduto. Torna indietro con il browser: il testo che hai scritto di solito è ancora lì. Ricarica e invia di nuovo.»
- Proposta: «Il modulo è rimasto aperto troppo a lungo. Torna indietro con il browser, copia il testo che avevi scritto, ricarica la pagina e invia di nuovo.»
- Perché: «il testo è ancora lì. Ricarica»: ricaricando il testo si perde; va detto di copiarlo prima. Il titolo dice già che la pagina è scaduta.

### supporto/resources/views/errors/429.blade.php:3 · Media · sito di supporto, pagina di errore
- Ora: «Aspetta un minuto e riprova. Se serve aiuto, torna al centro assistenza.»
- Proposta: «Aspetta un minuto e riprova.»
- Perché: come per la 403, la pagina non ha un link al centro assistenza.

### supporto/resources/views/mail/ticket-received.blade.php:1 · Bassa · email al cliente (anche `staff-replied.blade.php:1`)
- Ora: «'Buongiorno,'»
- Proposta: «'Ciao,'»
- Perché: con il nome l'email apre con «Ciao Maria,» e prosegue con il tu; senza nome passa a un saluto formale.

### supporto/resources/views/mail/ticket-received.blade.php:3 · Media · email al cliente
- Ora: «La tua richiesta «{{ $ticket->subject }}» è stata ricevuta.»
- Proposta: «Abbiamo ricevuto la tua richiesta «{{ $ticket->subject }}». Ti rispondiamo per email, di solito entro due giorni lavorativi.»
- Perché: passivo freddo, e manca cosa succede dopo; la pagina «Richiesta ricevuta» lo dice, l'email (che è ciò che resta al cliente) no. Per chi usa il gestionale la pagina promette un giorno: se l'email non deve distinguere, usare «Ti rispondiamo per email appena possibile.»

### supporto/resources/views/mail/ticket-received.blade.php:10-11 · Bassa · email al cliente (anche `staff-replied.blade.php:8`)
- Ora: «Grazie,» / «— Il supporto di {{ config('app.name') }}»
- Proposta: «Grazie,» / «il supporto di {{ config('app.name') }}» (in `staff-replied`: «Il supporto di {{ config('app.name') }}»)
- Perché: il trattino davanti alla firma è un vezzo da email automatica; dopo «Grazie,» la firma prosegue la frase.

## Da verificare (sospetti errori di fatto, non riscritti)

- **part9.php:232** (`affiliazioni-agli-enti`): «Il sito non conserva luogo di nascita e sesso: li ricava dal codice fiscale». Ma part8.php:21 dice che il modulo di adesione chiede «luogo di nascita e residenza» e part9.php:473 che il libro soci riporta «il luogo di nascita». Una delle due cose non torna (forse vale solo per il sesso, o solo per il file dell'ente).
- **part14.php:16-17 e 42** (`restituire-un-pagamento-online-al-socio`): il rimborso lo fanno «L'amministratore, la Segreteria e il Dipendente». In part13.php:88 i rimborsi dei pagamenti online stanno nell'area «Emissione dei rimborsi», che al dipendente si può togliere: la guida dovrebbe dire «il Dipendente con il permesso…»?
- **part14.php:185 e 195**: alla riga 185 «Con la licenza [la frase] è il codice di licenza»; alla 195 «Con la licenza il codice di licenza apre sempre: la frase tua serve solo a riaprire i documenti». Con la licenza si può avere anche una frase propria, o no? Le due righe lette insieme non si capiscono.
- **part14.php:184**: nel testo citato compare «{data}» così com'è. Non è un segnaposto sostituito dal sito (il seeder sostituisce solo i `{piano_*}`): a schermo il cliente legge «{data}». Forse meglio un esempio di data, ma il formato esatto non lo conosco.
- **part14.php:59**: «che indichi tra le **Persone**». Nelle altre guide il menu è «Rubrica soci»: controllare il nome della voce.
- **part10.php:119** (`rimborsi-ai-volontari`): «l'amministratore può attivare in **Impostazioni** l'opzione…». Quali impostazioni? Alla riga 107 la stessa guida cita **Cassa → Rimborsi → Impostazioni**: se è quella, scrivere il percorso intero.
- **part9.php:160** (`certificati-medici-sportivi`): «Apri il modulo dell'attività (corso o evento)». Altrove è «la scheda» dell'attività, e «modulo» nelle guide è quello che compila il socio. Controllare il nome a schermo.
- **part9.php:383-384** (`sms-ai-soci-e-consensi`): il passo 3 («Guarda l'anteprima») viene prima del passo 4 («Scrivi il testo»). Se a schermo il testo si scrive prima dell'anteprima, i due passi vanno scambiati.
- **part9.php:145** (`archivio-documenti`): «**Elimina** toglie il documento dagli elenchi, ma il file resta sul sito». Per il glossario «eliminare» è «togliere per sempre»: se il file resta davvero, il lettore va avvisato in modo più netto (e va detto come toglierlo); se invece viene cancellato, la frase è sbagliata.
- **part11.php:128, 141** (`bilancio-provvisorio-e-avvisi-di-bilancio`): l'avviso si chiama «Utile eccessivo», ma il glossario chiede «avanzo» e non «utile». È un'etichetta del plugin: se cambia lì, va cambiata anche qui.
- **part11.php:134 e 143**: la stessa cosa a schermo è «foto» (pulsante «Salva ora le foto dei mesi conclusi mancanti») e «istantanea» (impostazione «Istantanea mensile del bilancio»). Etichette del plugin, da uniformare alla fonte.
- **part11.php:171** (`verifiche-di-fine-anno-e-chiusura`): «movimenti senza categoria vera». Non si capisce cosa sia una categoria «vera» (forse movimenti lasciati su «Altra entrata/uscita»?). Da riscrivere con chi conosce la regola.
- **part11.php:203-204**: l'impostazione «Chiusura dell'anno con rimborsi non documentati» è descritta con codici «3 = … 2 = … 1 = … 0 = …». Se a schermo c'è un menu con le voci scritte, la guida dovrebbe citare le voci e non i numeri. «Tolleranza nella quadratura rimborsi e spese» usa «quadratura», che il glossario sconsiglia: etichetta del plugin.
- **part13.php:133** (`il-dipendente-e-i-suoi-permessi`): «Per il socio usa un'altra email, oppure l'accesso personale con la stessa email.» Non si capisce quale delle due cose fare né quando: da riscrivere con chi conosce il comportamento.
- **part11.php:9** (`inizio-dell-anno-fiscale`): la guida è «Disponibile in: Free, …» ma sta nell'argomento «Plugin Pro» (`'topic' => 'plugin-pro'`): chi ha il gratuito non la cercherà lì.
- **mail/staff-replied.blade.php:3**: «{{ $reply->author_name }} ha risposto alla tua richiesta…». Se il nome è vuoto la frase inizia con « ha risposto»; la pagina `site/ticket.blade.php:8` in quel caso scrive «Supporto».
- **errors/layout.blade.php:24-25**: tutte le pagine di errore del sito di supporto offrono solo «Torna al tuo account» e «Vai alla home» (del portale). Manca un pulsante «Torna al centro assistenza»: aggiungerlo renderebbe vere le frasi di 403 e 429 senza cambiarle.
- **Rendering delle guide**: le voci su commenti HTML, corsivo ed elenco rientrato (part14:165-166, part8:106, part9:36, part13:50, part14:196, part11:79-81) vengono dalla lettura di `supporto/app/Support/ArticleBody.php`. Non le ho viste nel browser: basta aprire le guide `cifratura-dei-file-riservati` e `spese-documentate-e-fondi-dei-rimborsi` per confermarle.
