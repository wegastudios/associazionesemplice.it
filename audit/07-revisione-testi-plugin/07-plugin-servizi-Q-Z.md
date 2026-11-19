# Plugin · Servizi, email e messaggi (file Q–Z)
File letti: 61 · Proposte: 83 (Alta 4, Media 51, Bassa 28)

Percorso comune dei file: `wordpress/associazionesemplice/includes/`.
Convenzione: dove il testo è composto con variabili PHP, queste sono indicate tra parentesi quadre (es. [nome], [data]); tutto il resto è citato alla lettera. Le variabili restano dove sono.

File con testi per l'utente: QrCode, ReceiptFiles, Receipts, RefundRules, Refunds, Regulation, Reminders, Reset, ResourceGate, ResourceRules, Resources, Rules, RuntsRules, SatispayApi, SatispayRules, Schedule, Settings, SheetReader, Sms, SmsRules, Statement, StatementSnapshot, StripeApi, SumUpApi, TextsEngine, TribeImport, TwilioSms, UnexpectedError, UpdateCheck, Updater, Visibility, Waitlist, Wallet, WalletCredentials, Wizard, WooBridge, WooLinks, WpAllImport, Xlsx, YearEndCheck.
File senza testi per l'utente (saltati): ReportService (solo etichette già corrette), RequestMemo, RowClean, Runts, Secrets, SessionToken, SmsProvider, SocialYear, SportCatalog (elenco discipline), StaffReminders (solo impaginazione), StripeWebhook, TaxCode, Terms, Text, TextGuard, Texts, VotingRight, WebPush (errori interni), WooLinks (già a posto), WpRoleCleanup, YearLock (usa solo `FiscalYears::closed_message`, fuori lotto).

## Difetti ricorrenti

- **«area riservata» al posto di «area soci».** Il glossario riserva «area riservata» all'account sul portale. Nelle email e nei messaggi al socio di questo lotto: Reminders.php:161, 244, 328, 368; Waitlist.php:170; Regulation.php:75; Resources.php:747 («dalla tua area»). Regola: sempre «area soci». Nota: nel plugin «area riservata» compare in 55 file, va uniformata ovunque insieme.
- **Trattino lungo come inciso o separatore.** Receipts.php:640, 643, 823, 932, 1029; Reminders.php:368; Resources.php:748; YearEndCheck.php:52. Regola: virgola, due punti o punto. (Il «—» da solo su una riga come separatore della firma nelle email va bene.)
- **Il programma che parla in prima persona.** «Non riesco a…» (ReceiptFiles.php:339, 343), «ti ricordo» (Resources.php:744), «non la tocco» (TribeImport.php:739). Regola: forma impersonale sul fatto («Il file non si può scrivere») oppure il «noi» dell'associazione nelle email («ti ricordiamo», già usato in Reminders.php:367).
- **Gergo tecnico negli errori.** «impronta» (Receipts.php:315, 788; ReceiptFiles.php:857), «errore del database» (Refunds.php:115; StatementSnapshot.php:103), «percorso non valido» (ReceiptFiles.php:177, 332), «PDF fissato» (Receipts.php:302), «firma», «soglia minima», «nuovo formato» (Updater.php:517-526, 540), «servito in https» (SatispayRules.php:92), «formato HH:MM» (Rules.php:135, 143), «estensione zip/openssl di PHP» (ReceiptFiles.php:802; WalletCredentials.php:26; SatispayApi.php:19). Regola: dire cosa è successo con parole comuni («risulta modificato dopo l'emissione», «per un errore tecnico») e, quando serve un tecnico, dire a chi rivolgersi.
- **Errori che non dicono come rimediare, con «Impossibile…».** ReceiptFiles.php:332, 811, 827, 864; Receipts.php:503, 1018, 1024; Refunds.php:115. Regola: «Cosa non è riuscito: cosa fare» (es. «Il file ZIP non è stato creato: riprova.»). La stessa cosa oggi è detta in due modi (ReceiptFiles.php:827 e 864).
- **Lo stesso concetto con più nomi.**
  - «cartella riservata» (Receipts.php:1018, 1024; ReceiptFiles.php:811) e «cartella dei file riservati» (ReceiptFiles.php:180, 339, 343): sceglierne uno; propongo «cartella dei file riservati», che è il nome usato nelle Impostazioni («Cifratura dei file riservati»).
  - «supporto» (UnexpectedError.php:12) e «assistenza» (StatementSnapshot.php:103, 186): sempre «supporto».
  - «cancellare» dove il glossario vuole «eliminare» (Reset.php:101, 115; ReceiptFiles.php:857).
  - «quota» per la somma destinata a un fondo (Resources.php:118, 124, 143, 635, 828): il glossario riserva «quota» alla quota associativa.
  - «tariffa» e «listino» per la stessa scelta (Waitlist.php:43 e 46).
  - «la data di fine non può essere prima dell'inizio» in tre forme diverse (Schedule.php:78, 107; Rules.php:152).
  - «rifiutato» (stato e motivo, RefundRules.php:28; Refunds.php:228) e «non accolto» (email, Refunds.php:237).
- **Virgolette dritte invece di «…».** SheetReader.php:24; TextsEngine.php:427; Wallet.php:84; WalletCredentials.php:30, 79.
- **Forme impersonali fredde dove basta il tu.** «Si prenota per…», «Si può prenotare…» (ResourceRules.php:83, 100), «Si può chiudere…» (YearEndCheck.php:48), «Il pagamento si effettua…» (Settings.php:16), «occorre rinnovarla» (Reminders.php:248).
- **«Non autorizzato.» a pagina intera.** Receipts.php:1063; Refunds.php:309, 314; TextsEngine.php:481; Wallet.php:213. Regola: «Non hai i permessi per aprire questa pagina.»
- **Email: oggetto e chiusura non uniformi.** I promemoria hanno l'oggetto «[Associazione] Oggetto» (Reminders.php:162); la ricevuta ha «Oggetto — Associazione» (Receipts.php:1029); lista d'attesa e fine anno non hanno il nome (Waitlist.php:168; Resources.php:1363; YearEndCheck.php:175). In chiusura, Reminders.php:161 scrive «Per informazioni rivolgiti alla segreteria.» e subito dopo `Texts::mail` aggiunge la riga con i contatti della segreteria: è un doppione.
- **Errori dei fornitori di pagamento con prefisso e gergo** («Stripe: risposta senza indirizzo di pagamento.»): SatispayApi.php:79, 88, 92; StripeApi.php:68; SumUpApi.php:51, 55. Vedi «Da verificare» per chi li legge.

## Proposte

### includes/ReceiptFiles.php:177 · Media · bacheca (apertura del PDF di una ricevuta); il socio vede un testo generico
- Ora: «Il PDF della ricevuta n. {n} ha un percorso non valido: per sicurezza non viene aperto. Avvisa un amministratore.»
- Proposta: «Il PDF della ricevuta n. {n} non è al suo posto: per sicurezza non viene aperto. Avvisa un amministratore.»
- Perché: «percorso non valido» è gergo; il resto va già bene.

### includes/ReceiptFiles.php:180 · Media · bacheca (apertura del PDF di una ricevuta)
- Ora: «Il PDF della ricevuta n. {n} non si trova più sul sito (la cartella dei file riservati è stata svuotata o spostata). Non viene rifatto in automatico: un amministratore può ripristinarlo da una copia di sicurezza oppure, da «Verifica delle ricevute», rigenerarlo dai dati salvati.»
- Proposta: «Il PDF della ricevuta n. {n} non è più sul sito: la cartella dei file riservati è stata svuotata o spostata. Un amministratore può ripristinarlo da una copia di sicurezza o rigenerarlo da «Verifica delle ricevute».»
- Perché: 46 parole con parentesi e doppia spiegazione; resta solo cosa è successo e cosa fare.

### includes/ReceiptFiles.php:332 · Media · bacheca («Verifica delle ricevute», rifare un PDF)
- Ora: «Impossibile rifare il PDF: percorso o contenuto non validi.»
- Proposta: «Il PDF non è stato rifatto: il file o i dati non sono validi. Avvisa un amministratore.»
- Perché: gergo e nessuna indicazione su cosa fare.

### includes/ReceiptFiles.php:339 · Media · bacheca («Verifica delle ricevute»)
- Ora: «Non riesco a mettere da parte il file alterato: controlla i permessi della cartella dei file riservati.»
- Proposta: «Il file alterato non si può mettere da parte: controlla i permessi della cartella dei file riservati.»
- Perché: il programma non parla in prima persona.

### includes/ReceiptFiles.php:343 · Media · bacheca («Verifica delle ricevute»)
- Ora: «Non riesco a scrivere il nuovo PDF: controlla i permessi della cartella dei file riservati.»
- Proposta: «Il nuovo PDF non si può salvare: controlla i permessi della cartella dei file riservati.»
- Perché: come sopra.

### includes/ReceiptFiles.php:802 · Media · bacheca (scarica lo ZIP delle ricevute dell'anno)
- Ora: «Questo server non può creare file ZIP (manca l'estensione zip di PHP).»
- Proposta: «Questo server non può creare file ZIP: chiedi a chi gestisce il sito di attivare l'estensione «zip» di PHP.»
- Perché: il volontario non sa cosa fare; il dato tecnico serve, ma rivolto a chi può agire. Stesso schema per WalletCredentials.php:26 e SatispayApi.php:19.

### includes/ReceiptFiles.php:811 · Media · bacheca (ZIP delle ricevute)
- Ora: «Impossibile creare il file ZIP: la cartella riservata non è scrivibile.»
- Proposta: «Il file ZIP non è stato creato: controlla i permessi della cartella dei file riservati.»
- Perché: «scrivibile» è gergo; nome della cartella uniformato.

### includes/ReceiptFiles.php:827, 864 · Bassa · bacheca (ZIP delle ricevute)
- Ora: «Impossibile creare il file ZIP.» (827) · «Creazione del file ZIP non riuscita.» (864)
- Proposta: «Il file ZIP non è stato creato: riprova.» (entrambe)
- Perché: stesso esito detto in due modi, senza dire cosa fare.

### includes/ReceiptFiles.php:857 · Bassa · file LEGGIMI.txt dentro lo ZIP delle ricevute
- Ora: «I PDF in questo archivio NON sono cifrati e contengono dati personali (nome, codice fiscale, importi): conservalo con cura e cancellalo quando non ti serve più.»
- Proposta: «I PDF in questo archivio non sono cifrati e contengono dati personali (nome, codice fiscale, importi): conservalo con cura ed eliminalo quando non ti serve più.»
- Perché: maiuscolo gridato; «eliminare» da glossario. Nella prima riga dello stesso file, «Le impronte sono nel database del sito» è gergo: vedi difetti ricorrenti.

### includes/Receipts.php:302 · Media · bacheca («Verifica delle ricevute»)
- Ora: «Questa ricevuta non ha un PDF fissato da rifare.»
- Proposta: «Questa ricevuta non ha ancora un PDF definitivo: non c'è nulla da rifare.»
- Perché: «PDF fissato» è una parola interna; altrove si dice «PDF definitivo».

### includes/Receipts.php:306 · Media · bacheca («Verifica delle ricevute»)
- Ora: «Il PDF di questa ricevuta è cifrato e la chiave di questo sito non lo apre: non viene rifatto, per non perdere il file. Sistema prima la chiave da Impostazioni → Copia di sicurezza → «Cifratura dei file riservati».»
- Proposta: «Il PDF di questa ricevuta è cifrato e questo sito non riesce ad aprirlo. Per non perdere il file non viene rifatto: sistema prima la chiave da Impostazioni → Copia di sicurezza → «Cifratura dei file riservati».»
- Perché: tre concetti in una frase; così causa e rimedio sono separati.

### includes/Receipts.php:315 · Media · bacheca («Verifica delle ricevute»)
- Ora: «I dati salvati di questa ricevuta non coincidono con la loro impronta (o non ne hanno una): il PDF non viene rifatto, per non mostrare dati diversi da quelli emessi. Ripristina il file da una copia di sicurezza.»
- Proposta: «I dati salvati di questa ricevuta risultano modificati dopo l'emissione: il PDF non viene rifatto, per non mostrare dati diversi da quelli emessi. Ripristina il file da una copia di sicurezza.»
- Perché: «impronta» non dice nulla a un volontario.

### includes/Receipts.php:503 · Bassa · bacheca (emissione di una ricevuta)
- Ora: «Impossibile assegnare il numero della ricevuta.»
- Proposta: «Il numero della ricevuta non è stato assegnato: riprova.»
- Perché: manca cosa fare.

### includes/Receipts.php:640, 643 · Bassa · PDF della ricevuta (pagamento per un minore)
- Ora: «Altro genitore o tutore: [nome] — C.F. [codice]» · «Per conto di [nome] — C.F. [codice], nato/a il [data]»
- Proposta: «Altro genitore o tutore: [nome], C.F. [codice]» · «Per conto di [nome], C.F. [codice], data di nascita [data]»
- Perché: trattino come inciso e doppia forma con la barra.

### includes/Receipts.php:788 · Media · bacheca (apertura di una ricevuta); il socio vede un testo generico
- Ora: «I dati salvati della ricevuta n. {n} non coincidono con la loro impronta: per sicurezza non viene mostrata. Avvisa un amministratore.»
- Proposta: «I dati salvati della ricevuta n. {n} risultano modificati dopo l'emissione: per sicurezza non viene mostrata. Avvisa un amministratore.»
- Perché: stessa parola tecnica della riga 315.

### includes/Receipts.php:823 · Bassa · PDF della ricevuta annullata
- Ora: «Non ha valore. Annullata il [data] — motivo: [motivo].»
- Proposta: «Non ha valore. Annullata il [data]. Motivo: [motivo].»
- Perché: trattino come inciso su un documento ufficiale.

### includes/Receipts.php:932 · Bassa · PDF dell'attestazione dei versamenti
- Ora: «ATTESTAZIONE DEI VERSAMENTI — ANNO [anno]»
- Proposta: «ATTESTAZIONE DEI VERSAMENTI · ANNO [anno]»
- Perché: trattino lungo nel titolo; il punto mediano è già usato nel resto dei PDF (riga 873).

### includes/Receipts.php:1013 · Media · bacheca (invia la ricevuta per email)
- Ora: «Chi ha pagato non ha un indirizzo email (né il socio che lo ospita).»
- Proposta: «Chi ha pagato non ha un'email, e nemmeno il socio che lo ospita: aggiungila e riprova.»
- Perché: l'errore non diceva come rimediare.

### includes/Receipts.php:1018 · Media · bacheca (invia la ricevuta per email)
- Ora: «Impossibile preparare la ricevuta: controlla i permessi della cartella riservata. L'email non è stata inviata.»
- Proposta: «Email non inviata: la ricevuta non si può preparare. Controlla i permessi della cartella dei file riservati.»
- Perché: prima l'esito che interessa (l'email non è partita), poi il rimedio; nome della cartella uniformato.

### includes/Receipts.php:1024 · Media · bacheca (invia la ricevuta per email)
- Ora: «Impossibile preparare la ricevuta: il PDF non si può scrivere (controlla lo spazio e i permessi della cartella riservata). L'email non è stata inviata.»
- Proposta: «Email non inviata: il PDF non si può salvare. Controlla lo spazio e i permessi della cartella dei file riservati.»
- Perché: come sopra, senza parentesi.

### includes/Receipts.php:1029 · Media · email al socio (ricevuta), oggetto
- Ora: «Ricevuta n. [numero] — [associazione]»
- Proposta: «[[associazione]] Ricevuta n. [numero]»
- Perché: tutte le altre email mettono il nome dell'associazione tra parentesi quadre all'inizio (Reminders.php:162); così il socio le riconosce a colpo d'occhio.

### includes/Receipts.php:1030 · Media · email al socio (ricevuta), testo
- Ora: «Ciao [nome],\n\nin allegato la ricevuta n. [numero] del tuo pagamento.\n\nGrazie!»
- Proposta: «Ciao [nome],\n\nin allegato trovi la ricevuta n. [numero] del tuo pagamento.\n\nGrazie.»
- Perché: frase senza verbo e punto esclamativo.

### includes/Receipts.php:1039 · Bassa · bacheca (invia la ricevuta per email)
- Ora: «Invio non riuscito: controlla la posta in uscita del sito.»
- Proposta: «Email non inviata: controlla le impostazioni di posta del sito.»
- Perché: «posta in uscita» è poco chiaro per chi non è tecnico.

### includes/Receipts.php:1063; Refunds.php:309, 314; TextsEngine.php:481; Wallet.php:213 · Media · pagina di errore a schermo intero (bacheca e area soci)
- Ora: «Non autorizzato.»
- Proposta: «Non hai i permessi per aprire questa pagina.»
- Perché: due parole fredde su una pagina bianca; chi le legge non capisce cosa è successo.

### includes/Receipts.php:1072, 1073 · Bassa · area soci (pagina di errore quando la ricevuta non si apre)
- Ora: «Questa ricevuta non è disponibile in questo momento. Avvisa la segreteria: {email}, tel. {telefono}.» · «Questa ricevuta non è disponibile in questo momento. Avvisa la segreteria.»
- Proposta: «Al momento questa ricevuta non è disponibile. Scrivi alla segreteria: {email}, tel. {telefono}.» · «Al momento questa ricevuta non è disponibile. Scrivi alla segreteria.»
- Perché: «questa… questo» ripetuto; «scrivi» è il verbo già usato negli altri messaggi al socio.

### includes/Refunds.php:115 · Media · area soci e bacheca (nuova richiesta di rimborso)
- Ora: «Impossibile salvare la richiesta (errore del database).»
- Proposta: «La richiesta non è stata salvata per un errore tecnico: riprova.»
- Perché: «database» è gergo e manca cosa fare.

### includes/Refunds.php:187 · Bassa · bacheca (approva o paga un rimborso)
- Ora: «Non puoi approvare o pagare un rimborso a tuo nome: lo fa un altro membro della segreteria o del direttivo (oppure attiva l'opzione nelle impostazioni dei rimborsi).»
- Proposta: «Non puoi approvare o pagare un rimborso a tuo nome: deve farlo un'altra persona della segreteria o del direttivo. Per permetterlo, attiva l'opzione nelle impostazioni dei rimborsi.»
- Perché: 30 parole con parentesi; due frasi si leggono meglio.

### includes/Refunds.php:219 · Bassa · email al volontario (rimborso approvato)
- Ora: «la tua richiesta di rimborso [riepilogo] è stata approvata. Ti sarà pagato dalla segreteria.»
- Proposta: «la tua richiesta di rimborso [riepilogo] è stata approvata. La segreteria ti pagherà il rimborso.»
- Perché: passivo e soggetto sottinteso («ti sarà pagato» cosa?).

### includes/Refunds.php:237 · Bassa · email al volontario (rimborso rifiutato), oggetto e testo
- Ora: «Rimborso non accolto» · «la tua richiesta di rimborso [riepilogo] non è stata accolta. Motivo: [motivo]»
- Proposta: «Rimborso rifiutato» · «la tua richiesta di rimborso [riepilogo] è stata rifiutata. Motivo: [motivo]»
- Perché: lo stato che il volontario vede nella sua area è «Rifiutato» (RefundRules.php:28): una sola parola per lo stesso esito.

### includes/Refunds.php:271 · Media · bacheca (paga un rimborso)
- Ora: «In prima nota manca la categoria «Rimborso spese socio/volontario»: creala dalle categorie.»
- Proposta: «In prima nota manca la voce «Rimborso spese socio/volontario»: creala tra le voci di prima nota.»
- Perché: negli altri messaggi di questo lotto si dice «voce di prima nota» (Resources.php:105, 1420). Vedi «Da verificare» per il nome del menu.

### includes/Regulation.php:12 · Bassa · bacheca (come il socio ha accettato il regolamento)
- Ora: «A voce / altro»
- Proposta: «A voce o altro»
- Perché: doppia forma con la barra.

### includes/Regulation.php:75 · Media · area soci (prenotazione bloccata)
- Ora: «Per prenotare devi prima accettare il [titolo del regolamento]: lo trovi in cima alla tua area riservata.»
- Proposta: «Per prenotare devi prima accettare il [titolo del regolamento]: lo trovi in cima alla tua area soci.»
- Perché: termine da glossario.

### includes/Reminders.php:121, 122 · Media · email al socio (prestito non restituito), oggetto e testo
- Ora: «Rientro scaduto: [oggetto]» · «il rientro di «[oggetto]» era previsto per il [data]. Quando puoi, riportalo alla segreteria o scrivici se ti serve più tempo. Se l'hai già riportato, ignora questo messaggio.»
- Proposta: «Da restituire: [oggetto]» · «la restituzione di «[oggetto]» era prevista per il [data]. Quando puoi, riportalo in segreteria, oppure scrivici se ti serve più tempo. Se l'hai già riportato, ignora questo messaggio.»
- Perché: «rientro scaduto» è il linguaggio del magazzino, non del socio che ha preso in prestito un oggetto.

### includes/Reminders.php:161 · Media · email al socio (chiusura di tutti i promemoria)
- Ora: «Area riservata: [indirizzo]\nPer informazioni rivolgiti alla segreteria.»
- Proposta: «Area soci: [indirizzo]»
- Perché: termine da glossario; la frase sulla segreteria è un doppione della riga con email e telefono che `Texts::mail` aggiunge subito sotto.

### includes/Reminders.php:244 · Media · email al socio (tessera in scadenza)
- Ora: «la tua tessera associativa scade il [data]. Puoi rinnovarla in segreteria o, se attivi, online dall'area riservata.»
- Proposta: «la tua tessera associativa scade il [data]. Puoi rinnovarla in segreteria o, dove previsto, online dall'area soci.»
- Perché: «se attivi» si legge come «se tu attivi»; qui vuol dire «se i pagamenti online sono attivi».

### includes/Reminders.php:248 · Media · email al socio (tessera scaduta)
- Ora: «la tua tessera associativa è scaduta il [data]. Per prenotare eventi e corsi occorre rinnovarla: rivolgiti alla segreteria.»
- Proposta: «la tua tessera associativa è scaduta il [data]. Per prenotare eventi e corsi devi rinnovarla: scrivi alla segreteria.»
- Perché: «occorre» è burocratese; con il tu è più diretto.

### includes/Reminders.php:327-328 · Media · email al socio (mensilità del corso)
- Ora: «il corso «[corso]» si rinnova all'inizio di [mese]: [per nome ]la mensilità è di [importo] (resta da versare). Puoi pagare in segreteria o, se attivo, online dall'area riservata.\nSe non vuoi più frequentare, comunicalo alla segreteria prima dell'inizio del mese. Se hai già pagato, ignora questo messaggio.»
- Proposta: «il corso «[corso]» si rinnova all'inizio di [mese]: [per nome ]la mensilità è di [importo] (la parte ancora da pagare). Puoi pagare in segreteria o, dove previsto, online dall'area soci.\nSe non vuoi più frequentare, avvisa la segreteria prima dell'inizio del mese. Se hai già pagato, ignora questo messaggio.»
- Perché: «(resta da versare)» dopo l'importo non si capisce; «se attivo» è ambiguo; «comunicalo» è burocratese; «area soci» da glossario.

### includes/Reminders.php:366-368 · Media · email al socio (evento di domani)
- Ora: «ti ricordiamo la prenotazione [per nome ]per «[evento]» domani, [data] alle [ora] — [luogo]. Se non puoi venire, annulla dall'area riservata (se l'evento lo permette).»
- Proposta: «ti ricordiamo la prenotazione [di nome ]per «[evento]» di domani, [data] alle [ora], [luogo]. Se non puoi venire, annulla dall'area soci, se l'evento lo permette.»
- Perché: con un minore o un ospite esce «la prenotazione per Luca per «Yoga»»; in più trattino lungo, parentesi e «area riservata». La parola «per» sta nella riga 366 («'per ' . $to['about']»).

### includes/Reset.php:101 · Bassa · bacheca (azzeramento dei dati)
- Ora: «Scarica prima una copia completa dei dati e conservala: non è stato cancellato nulla.»
- Proposta: «Scarica prima una copia completa dei dati e conservala: non è stato eliminato nulla.»
- Perché: glossario («eliminare» = togliere per sempre). Stessa parola alla riga 115: «Azzeramento non riuscito, nulla è stato cancellato: » → «Azzeramento non riuscito, nulla è stato eliminato: ».

### includes/ResourceGate.php:67 · Bassa · schermata di controllo ingressi (lettura del QR)
- Ora: «Già entrato»
- Proposta: «Ingresso già registrato»
- Perché: evita il maschile per tutti.

### includes/ResourceRules.php:83 · Media · area soci (prenotazione di una risorsa a fasce)
- Ora: «Si prenota per [n] fascia.» / «Si prenota per [n] fasce.» · «Si prenotano da [min] a [max] fasce di seguito.»
- Proposta: «Puoi prenotare [n] fascia.» / «Puoi prenotare [n] fasce.» · «Puoi prenotare da [min] a [max] fasce di seguito.»
- Perché: impersonale freddo; il resto dell'area soci dà del tu.

### includes/ResourceRules.php:100 · Media · area soci (prenotazione di una risorsa a fasce)
- Ora: «Si può prenotare al massimo [n] giorno prima.» / «… [n] giorni prima.»
- Proposta: «Puoi prenotare al massimo [n] giorno prima.» / «… [n] giorni prima.»
- Perché: come sopra.

### includes/Resources.php:118, 124 · Media · bacheca (scheda della risorsa, parte per un fondo)
- Ora: «Indica la quota per il fondo (importo o percentuale maggiori di zero).» · «Scegli il fondo in cui accantonare la quota, oppure creane uno nuovo.»
- Proposta: «Indica quanto accantonare nel fondo (importo o percentuale maggiori di zero).» · «Scegli il fondo in cui accantonare la somma, oppure creane uno nuovo.»
- Perché: il glossario riserva «quota» alla quota associativa e la vieta per i fondi.

### includes/Resources.php:143, 635, 828 · Bassa · bacheca (nome del fondo creato in automatico e descrizione dei movimenti del fondo)
- Ora: «Quota «[risorsa]»» · «Quota di «[risorsa]» pagata in crediti» · «Quota di «[risorsa]»»
- Proposta: «Accantonamento «[risorsa]»» · «Accantonamento di «[risorsa]» pagata in crediti» · «Accantonamento di «[risorsa]»»
- Perché: stessa regola del glossario; da allineare alle etichette della scheda (fuori lotto).

### includes/Resources.php:423 · Alta · area soci e bacheca (prenotazione a una data di un corso o evento che occupa una risorsa)
- Ora: «Questa fascia non è disponibile: la risorsa è già occupata da [elenco].»
- Proposta: «Questa fascia non è disponibile. La risorsa è già occupata: [elenco].»
- Perché: errore di grammatica. Le voci dell'elenco nascono alla riga 1174 come «la prenotazione di [nome] del [data]», quindi a schermo esce «occupata da la prenotazione di Mario Rossi…».

### includes/Resources.php:744 · Media · email al socio (promemoria della prenotazione di una risorsa)
- Ora: «ti ricordo la prenotazione di «[risorsa]» il [data] dalle [ora]»
- Proposta: «ti ricordiamo la prenotazione di «[risorsa]» il [data] dalle [ora]»
- Perché: negli altri promemoria parla l'associazione («ti ricordiamo», Reminders.php:367).

### includes/Resources.php:747 · Media · email al socio (promemoria della prenotazione di una risorsa)
- Ora: «Se non puoi venire, annullala dalla tua area (o scrivi alla segreteria): così la fascia torna libera per altri soci.»
- Proposta: «Se non puoi venire, annullala dall'area soci o scrivi alla segreteria: così la fascia torna libera per altri soci.»
- Perché: «la tua area» è un terzo nome per l'area soci; parentesi inutile.

### includes/Resources.php:748 · Bassa · email al socio (promemoria della prenotazione), oggetto
- Ora: «Promemoria: [risorsa] — [gg/mm hh:mm]»
- Proposta: «Promemoria: [risorsa], [gg/mm hh:mm]»
- Perché: trattino lungo nell'oggetto.

### includes/Resources.php:991 · Media · area soci (annullare un'assegnazione a periodo)
- Ora: «Un'assegnazione la rilascia la segreteria.»
- Proposta: «Per lasciare una risorsa assegnata scrivi alla segreteria.»
- Perché: frase contorta e «rilasciare» è gergo; così dice cosa fare.

### includes/Resources.php:1261 · Bassa · bacheca (spostamento su un'altra risorsa non riuscito)
- Ora: ««[attività]» non entra sulla risorsa di prima (si sovrappone a qualcosa)»
- Proposta: ««[attività]» non entra sulla risorsa di prima (l'orario è già occupato)»
- Perché: «a qualcosa» è troppo colloquiale e vago.

### includes/Resources.php:1363-1365 · Media · email al socio (lista d'attesa di una risorsa), oggetto e testo
- Ora: «Si è liberato: [risorsa]» · «Ciao [nome],\n\nsei il primo in lista d'attesa per «[risorsa]» e la risorsa si è liberata.\n\nLa segreteria ti contatterà per l'assegnazione.»
- Proposta: «Si è liberata: [risorsa]» · «Ciao [nome],\n\n«[risorsa]» si è liberata e tu sei in cima alla lista d'attesa.\n\nLa segreteria ti contatterà per l'assegnazione.»
- Perché: concordanza sbagliata nell'oggetto (la risorsa); «sei il primo» al maschile per tutti; la notizia (si è liberata) va prima.

### includes/Resources.php:1420 · Bassa · bacheca (incasso di una prenotazione)
- Ora: «Manca la voce «Altra entrata» in prima nota.»
- Proposta: «In prima nota manca la voce «Altra entrata»: creala e riprova.»
- Perché: manca cosa fare; stessa forma di Refunds.php:271.

### includes/Rules.php:61, 67 · Bassa · bacheca e moduli (scheda della persona)
- Ora: «[campo] è troppo lungo (al massimo [n] caratteri).» · «Il numero di tessera è troppo lungo (massimo 40 caratteri).»
- Proposta: usare «(al massimo [n] caratteri)» in entrambe.
- Perché: stessa informazione in due forme. Nota: con «L'email» e «La provincia» la riga 61 dà «L'email è troppo lungo»: vedi «Da verificare».

### includes/Rules.php:73 · Bassa · bacheca e area soci (aggiunta di un ospite)
- Ora: «Il numero di cellulare è obbligatorio per gli ospiti (serve a riconoscerli ed evitare doppioni, e a contattarli su WhatsApp).»
- Proposta: «Per gli ospiti il cellulare è obbligatorio: serve a riconoscerli, evitare doppioni e contattarli su WhatsApp.»
- Perché: parentesi lunga con doppia «e»; più corta e lineare.

### includes/Rules.php:82 · Media · bacheca e area soci (aggiunta di un ospite)
- Ora: «Il socio ospitante non è valido (deve essere un socio, non un altro ospite).»
- Proposta: «Chi ospita deve essere un socio, non un altro ospite.»
- Perché: «non è valido» più la spiegazione tra parentesi; basta la spiegazione.

### includes/Rules.php:135, 143 · Media · bacheca (date di un evento)
- Ora: «L'orario non è valido (usa il formato HH:MM).» · «L'orario di fine non è valido (usa il formato HH:MM).»
- Proposta: «L'orario non è valido: scrivilo così, 18:30.» · «L'orario di fine non è valido: scrivilo così, 18:30.»
- Perché: «formato HH:MM» è linguaggio da programmatore; un esempio si capisce subito.

### includes/SatispayApi.php:79, 88, 92; StripeApi.php:68; SumUpApi.php:51, 55 · Media · area soci al momento di pagare, oppure bacheca (da verificare)
- Ora: «Satispay: risposta senza identificativo della chiave.» · «Satispay: risposta senza indirizzo di pagamento.» · «Satispay: indirizzo di pagamento non sicuro.» · «Stripe: risposta senza indirizzo di pagamento.» · «SumUp: risposta senza indirizzo di pagamento.» · «SumUp: indirizzo di pagamento non sicuro.»
- Proposta: per le cinque sul pagamento, «[Fornitore] non ha avviato il pagamento: riprova tra qualche minuto.»; per la riga 79, «Satispay non ha completato il collegamento: riprova.»
- Perché: frasi da registro tecnico; chi sta pagando non sa cosa farne.

### includes/SatispayRules.php:89 · Bassa · bacheca (impostazioni dei pagamenti)
- Ora: «Satispay: non è ancora collegato. Inserisci il codice di attivazione che trovi nell'area Satispay Business e premi «Collega Satispay».»
- Proposta: «Satispay non è ancora collegato. Inserisci il codice di attivazione che trovi nell'area Satispay Business e premi «Collega Satispay».»
- Perché: i due punti dopo il nome spezzano una frase normale.

### includes/SatispayRules.php:92 · Media · bacheca (impostazioni dei pagamenti)
- Ora: «Satispay richiede che il sito sia servito in https: oggi l'indirizzo del sito è http (serve per la conferma automatica dei pagamenti).»
- Proposta: «Satispay funziona solo se l'indirizzo del sito inizia con https (oggi inizia con http): serve per la conferma automatica dei pagamenti.»
- Perché: «servito in https» è gergo; il motivo era relegato in parentesi dopo la frase.

### includes/SatispayRules.php:100 · Bassa · bacheca (impostazioni dei pagamenti)
- Ora: «Satispay è in modalità REALE: i pagamenti saranno veri.»
- Proposta: «Satispay è in modalità reale: i pagamenti sono veri.»
- Perché: maiuscolo gridato; il presente è più chiaro del futuro.

### includes/Schedule.php:78, 107 · Bassa · bacheca (calendario di un'attività)
- Ora: «Riga [n]: la data di fine non può essere prima dell'inizio.» · «Riga [n]: la data di fine è prima di quella di inizio.»
- Proposta: «Riga [n]: la data di fine non può essere prima dell'inizio.» (entrambe, uguale a Rules.php:152)
- Perché: stesso errore in due forme nello stesso modulo.

### includes/Settings.php:16 · Media · area soci (testo predefinito mostrato a chi ha importi da pagare; la segreteria può cambiarlo)
- Ora: «Il pagamento si effettua in sede presso la segreteria.»
- Proposta: «Puoi pagare in sede, in segreteria.»
- Perché: «si effettua… presso» è burocratese; è la prima cosa che il socio legge accanto a un importo. Nota per chi applica: è una costante usata come valore predefinito.

### includes/SheetReader.php:24 · Media · bacheca (importazione da foglio di calcolo)
- Ora: «Il vecchio formato Excel (.xls) non è supportato: in Excel scegli File → Salva con nome → "Cartella di lavoro di Excel (.xlsx)" oppure CSV.»
- Proposta: «Il vecchio formato Excel (.xls) non si può leggere: in Excel scegli File → Salva con nome → «Cartella di lavoro di Excel (.xlsx)» oppure CSV.»
- Perché: «non è supportato» è gergo; virgolette dritte.

### includes/StatementSnapshot.php:103 · Media · bacheca (chiusura dell'anno fiscale ed emissione del rendiconto)
- Ora: «Il rendiconto definitivo non è stato emesso (errore del database): non è stato salvato nulla. Riprova; se succede ancora scrivi all'assistenza.» · «Il rendiconto definitivo non è stato emesso (errore del database): la chiusura non è avvenuta. Riprova; se succede ancora scrivi all'assistenza.»
- Proposta: «Il rendiconto definitivo non è stato emesso per un errore tecnico: non è stato salvato nulla. Riprova; se succede ancora scrivi al supporto.» · «Il rendiconto definitivo non è stato emesso per un errore tecnico: la chiusura non è avvenuta. Riprova; se succede ancora scrivi al supporto.»
- Perché: «database» è gergo; «supporto» è il termine usato altrove (UnexpectedError.php:12).

### includes/StatementSnapshot.php:186 · Bassa · bacheca (scarica il PDF del rendiconto definitivo)
- Ora: «Il PDF emesso risulta cambiato dopo l'emissione: per sicurezza non si può scaricare. Scrivi all'assistenza.»
- Proposta: «Il PDF risulta modificato dopo l'emissione: per sicurezza non si può scaricare. Scrivi al supporto.»
- Perché: «emesso… emissione» ripetuto; «supporto» come sopra.

### includes/StatementSnapshot.php:259-260 · Media · bacheca (anni fiscali, riquadro «Emetti il rendiconto definitivo degli anni già chiusi»)
- Ora: «Il rendiconto di questi anni fiscali chiusi non è ancora definitivo: i numeri che si vedono sono quelli di oggi e possono cambiare. Anni: <strong>[anni]</strong>. Emetterlo ora lo <strong>ricostruisce dai dati di oggi</strong> (le cifre dei movimenti sono quelle registrate, ma le voci per area sono quelle attuali) e ne emette il PDF con numero e data. Porta la dicitura «Emesso il gg/mm/aaaa per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura.» e l'operazione resta scritta nello storico. Non si può annullare.»
- Proposta: «Il rendiconto di questi anni fiscali chiusi non è ancora definitivo: i numeri sono quelli di oggi e possono cambiare. Anni: <strong>[anni]</strong>. Se lo emetti ora, viene <strong>ricostruito dai dati di oggi</strong>: gli importi dei movimenti sono quelli registrati, le voci per area quelle attuali. Il PDF avrà numero, data e la dicitura «Emesso il gg/mm/aaaa per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura.» L'operazione resta nello storico e non si può annullare.»
- Perché: «Emetterlo ora lo ricostruisce… e ne emette» è difficile da seguire, con una parentesi lunga e «Porta la dicitura» senza soggetto.

### includes/TextsEngine.php:427 · Bassa · bacheca (importazione dei testi personalizzati)
- Ora: «Il file non ha le colonne "Originale" e "Personalizzato": usa il file esportato da qui.»
- Proposta: «Il file non ha le colonne «Originale» e «Personalizzato»: usa il file esportato da qui.»
- Perché: virgolette dritte.

### includes/TribeImport.php:739 · Media · bacheca (annullamento di un'importazione da The Events Calendar)
- Ora: «pagina «[titolo]» (modificata dopo l'import: non la tocco)»
- Proposta: «pagina «[titolo]» (modificata dopo l'importazione: resta com'è)»
- Perché: il programma parla in prima persona; «import» è inglese.

### includes/Updater.php:57 · Media · testo suggerito per l'informativa privacy del sito (Impostazioni → Privacy di WordPress)
- Ora: «Lo fa al massimo ogni 12 ore o più (di solito una volta al giorno), anche senza chiave di licenza, e mai su un sito di prova locale.»
- Proposta: «Lo fa non più di una volta ogni 12 ore (di solito una volta al giorno), anche senza chiave di licenza, e mai su un sito di prova locale.»
- Perché: «al massimo ogni 12 ore o più» si contraddice.

### includes/Updater.php:59 · Bassa · testo suggerito per l'informativa privacy del sito
- Ora: «Si spegne da Impostazioni → Limiti e soglie, togliendo il segno da «Aggiornamenti diretti di Associazione Semplice e del Pro»: se è già spento, non parte nessuna richiesta, e gli aggiornamenti vanno scaricati dall'area clienti e caricati a mano.»
- Proposta: «Si spegne da Impostazioni → Limiti e soglie, togliendo il segno da «Aggiornamenti diretti di Associazione Semplice e del Pro». Da spento non parte nessuna richiesta: gli aggiornamenti si scaricano dall'area clienti e si caricano a mano.»
- Perché: frase di 38 parole; «se è già spento» confonde.

### includes/Updater.php:508 · Media · bacheca (Aggiornamenti di WordPress, aggiornamento non riuscito)
- Ora: «L'aggiornamento non è disponibile adesso dal portale di Associazione Semplice (licenza non in regola, troppe richieste o portale non raggiungibile). Riprova più tardi o scaricalo dall'area clienti.»
- Proposta: «Ora l'aggiornamento non si può scaricare dal portale di Associazione Semplice. Le cause possibili: licenza non in regola, troppe richieste o portale non raggiungibile. Riprova più tardi o scaricalo dall'area clienti.»
- Perché: la parentesi con tre cause in mezzo alla frase si perde; così sono separate.

### includes/Updater.php:517, 526 · Media · bacheca (Aggiornamenti di WordPress)
- Ora: «Il pacchetto offerto dal portale non ha una firma valida: non è stato installato. Scaricalo dall'area clienti e scrivici.»
- Proposta: «L'aggiornamento non ha superato il controllo di sicurezza: non è stato installato. Scaricalo dall'area clienti e scrivici.»
- Perché: «pacchetto» e «firma» sono gergo; conta sapere che è un controllo di sicurezza e cosa fare.

### includes/Updater.php:518, 519 · Media · bacheca (Aggiornamenti di WordPress)
- Ora: «La versione offerta dal portale è sotto la soglia minima ammessa: non è stata installata.» · «La versione offerta dal portale è più vecchia di una già vista: non è stata installata.»
- Proposta: «La versione offerta dal portale è troppo vecchia: non è stata installata. Riprova più tardi; se succede ancora scrivici.» (entrambe)
- Perché: «soglia minima ammessa» e «già vista» descrivono il controllo interno, non cosa fare.

### includes/Updater.php:520 · Media · bacheca (Aggiornamenti di WordPress)
- Ora: «Il pacchetto offerto dal portale non ha la firma del nuovo formato, richiesta dopo il primo pacchetto così firmato: non è stato installato. Scaricalo dall'area clienti e scrivici.»
- Proposta: «L'aggiornamento non ha superato il controllo di sicurezza: non è stato installato. Scaricalo dall'area clienti e scrivici.»
- Perché: la spiegazione sul formato della firma è incomprensibile per chi legge e non cambia cosa deve fare.

### includes/Updater.php:540 · Media · bacheca (Aggiornamenti di WordPress)
- Ora: «Il file scaricato non corrisponde alla firma del portale: non è stato installato.»
- Proposta: «Il file scaricato non è quello pubblicato dal portale: non è stato installato. Riprova; se succede ancora scrivici.»
- Perché: gergo e nessuna indicazione su cosa fare.

### includes/Waitlist.php:43, 46 · Media · area soci e bacheca (iscrizione alla lista d'attesa)
- Ora: «Scegli la tariffa: per questa attività bisogna sceglierne una.» · «Listino non trovato per questa attività.»
- Proposta: «Scegli il listino: per questa attività è obbligatorio.» · «Listino non trovato per questa attività.»
- Perché: la stessa scelta è chiamata «tariffa» e «listino» a tre righe di distanza; la prima frase ripete «scegli… sceglierne».

### includes/Waitlist.php:165-170 · Media · email al socio e notifica sul telefono (posto liberato)
- Ora: notifica «Si è liberato un posto e la prenotazione [per nome ]del [data] è stata registrata.» · email «Ciao [nome],\n\nsi è liberato un posto e la prenotazione [per nome ]per «[evento]» del [data] è stata registrata automaticamente.\n\nSe non puoi più partecipare, annulla dall'area riservata: [indirizzo]»
- Proposta: notifica «Si è liberato un posto: la prenotazione [di nome ]del [data] è confermata.» · email «Ciao [nome],\n\nsi è liberato un posto: la prenotazione [di nome ]per «[evento]» del [data] è confermata.\n\nSe non puoi più partecipare, annulla dall'area soci: [indirizzo]»
- Perché: con un minore o un ospite esce «la prenotazione per Luca per «Yoga»» (la parola «per» è alla riga 162); «registrata automaticamente» è linguaggio da macchina; «area soci» da glossario.

### includes/Wallet.php:84; WalletCredentials.php:30, 79 · Bassa · bacheca (impostazioni di Apple Wallet e Google Wallet)
- Ora: «(serve il file .cer di Apple, "Worldwide Developer Relations")» · «Se il file viene da "Accesso Portachiavi" di macOS…» · «(mancano "client_email" e "private_key")»
- Proposta: le stesse frasi con «Worldwide Developer Relations», «Accesso Portachiavi», «client_email» e «private_key» tra «…».
- Perché: virgolette dritte.

### includes/Wizard.php:76 · Alta · bacheca (configurazione guidata, primo passo)
- Ora: «Prima di continuare conferma i due mesi: l'inizio dell'anno sociale e quello dell'anno fiscale.»
- Proposta: «Prima di continuare conferma i due mesi: l'inizio dell'anno delle attività e quello dell'anno fiscale.»
- Perché: nella stessa schermata il campo si chiama «anno delle attività» (Admin/WizardPage.php:83); l'errore usa un nome che l'utente non vede.

### includes/YearEndCheck.php:48 · Alta · bacheca (avviso «Verifiche in vista della fine dell'anno fiscale»)
- Ora: «Si può chiudere per questa settimana; da lunedì ricompare finché ci sono discrepanze.»
- Proposta: «Puoi nascondere questo avviso: da lunedì ricompare finché restano discrepanze.»
- Perché: in un avviso sulla fine dell'anno fiscale, «Si può chiudere» si legge come «si può chiudere l'anno»; qui si parla solo di chiudere l'avviso.

### includes/YearEndCheck.php:52 · Bassa · bacheca (elenco delle verifiche di fine anno)
- Ora: «[n] [etichetta] — <a href="[indirizzo]">verifica</a>»
- Proposta: «[n] [etichetta]: <a href="[indirizzo]">verifica</a>»
- Perché: trattino lungo come separatore.

### includes/YearEndCheck.php:119 · Alta · bacheca (avviso «Anno fiscale {anno} in disavanzo.»)
- Ora: «L'anno fiscale {anno} è finito ma è ancora aperto e ha un disavanzo di {importo}: le uscite superano le entrate. Se mancano delle entrate, registrale in Prima nota con una data dentro quell'anno. Se invece i conti sono giusti, controllali e poi chiudi l'anno.»
- Proposta: «L'anno fiscale {anno} è finito ma è ancora aperto e ha un disavanzo di {importo}: le uscite superano le entrate. Se mancano delle entrate, registrale in Prima nota con una data di quell'anno. Se invece i conti sono giusti, puoi chiudere l'anno.»
- Perché: «se i conti sono giusti, controllali» è contraddittorio: chi legge non capisce se deve ancora controllare o può chiudere.

### includes/YearEndCheck.php:172, 175 · Media · email a segreteria, tesoriere e presidente (verifiche di fine anno)
- Ora: oggetto «Verifiche di fine anno fiscale» · testo «L'anno fiscale finisce il [data]. Conviene sistemare ora queste discrepanze, così non restano correzioni da fare dopo la chiusura:\n\n[elenco]»
- Proposta: oggetto «[[associazione]] Verifiche di fine anno fiscale» · testo «Ciao,\n\nl'anno fiscale finisce il [data]. Conviene sistemare ora queste discrepanze, così non restano correzioni da fare dopo la chiusura:\n\n[elenco]»
- Perché: è l'unica email del lotto senza saluto e senza il nome dell'associazione nell'oggetto; sembra un messaggio di sistema. (Più lunga dell'originale: mancano informazioni di contesto.)

## Da verificare (sospetti errori di fatto, non riscritti)

- **Visibility.php:28 e 86**: la stessa regola è «Solo soci e volontari» nell'elenco di scelta e «Solo volontari» nell'etichetta breve della colonna «Accesso». Una delle due descrive male chi può vedere il contenuto.
- **Rules.php:59-61**: il messaggio «[campo] è troppo lungo» è al maschile, ma tra i campi ci sono «L'email» e «La provincia»: a schermo esce «L'email è troppo lungo», «La provincia è troppo lungo». Serve una forma neutra (es. «[campo]: testo troppo lungo (al massimo [n] caratteri).») o la concordanza per campo.
- **Refunds.php:271**: dice «creala dalle categorie». Nel lotto la stessa cosa è «voce di prima nota»; nel resto del plugin «categoria» compare in 34 file. Va deciso un solo nome e controllato come si chiama davvero la pagina di menu.
- **SatispayApi.php:88, 92; StripeApi.php:68; SumUpApi.php:51, 55**: da questi file non si vede se il messaggio arriva al socio che sta pagando o solo alla segreteria. Se arriva al socio, la gravità sale ad Alta.
- **StripeApi.php:100, 104**: i messaggi «rimborso failed» / «rimborso canceled» e «risposta [codice]» contengono lo stato in inglese di Stripe. Da controllare se vengono mostrati in bacheca così come sono.
- **Reminders.php:161 con Texts.php:64-66**: l'email esce con due separatori («—» prima della firma, «--» prima dei contatti) e due inviti a contattare la segreteria. Da controllare su un'email vera prima di togliere la frase.
- **Reminders.php:121**: «rientro» compare in 11 file del plugin (prestiti del magazzino). Se in area soci il prestito è descritto con «rientro», la proposta va allineata a quelle schermate.
- **Reminders.php:327-328**: quando il socio ha già versato una parte, il testo dice «la mensilità è di [importo]» ma l'importo è solo il residuo (`$fee - $paid`). La frase andrebbe composta in due varianti («la mensilità è di…» / «della mensilità restano da pagare…»), non solo ritoccata.
- **Wizard.php:76 e Settings**: «anno sociale» compare ancora in 28 file del plugin. In questo lotto l'unico testo a schermo è Wizard.php:76; gli altri sono commenti.
- **UpdateCheck.php:79, 84, 122**: il motivo tra parentesi in «ultimo controllo del [data] non riuscito ([motivo])» può essere «Risposta 500» o un messaggio di WordPress in inglese. Da valutare se mostrare solo «non riuscito» e lasciare il dettaglio al registro.
