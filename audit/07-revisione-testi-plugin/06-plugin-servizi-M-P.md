# Plugin · Servizi, email e messaggi (file M–P)
File letti: 56 · Proposte: 84 (Alta 4, Media 38, Bassa 42)

Tutti i percorsi sono relativi a `wordpress/associazionesemplice/includes/`.
File con testi per l'utente: MailchimpNewsletter, MemberBook, MemberLogin, Minutes, MissingBand, Newsletter, Notices, OfflinePayments, Pages, PaymentConfig, PaymentService, PeopleCsv, PeopleService, PersonSearchRules, Plugin, Polls, Privacy, PrivacyNotice, PrivateFiles, PrivateFolder, ProBoot, ProData, ProSettings, ProWatch, PublicEvents, Push.
File senza testi da rivedere (solo logica, o testi già a posto): MailLog, MailPoetNewsletter, MemberPassword, MemberQuery, MemberSession, MemberType, MembershipRoles, MembershipRules, Modules, Money, NewsletterProvider, NewsletterRules, PageCache, PayPalApi, PaymentCalc, PaymentItems, Pdf, Phone, Places, Png, PollRules, PreSession, PriceLists, Pricing, PrivateFolderRules, ProBlockC, ProBlockD, ProBlockE, ProSchema, Pwa.

## Difetti ricorrenti
- **«Area riservata» al posto di «area soci».** Il glossario riserva «area riservata» all'account sul portale; nel plugin la pagina si chiama «Area soci» (`Pages.php:34`), ma la voce di menu creata dal plugin, le email delle votazioni, l'informativa privacy e alcuni errori dicono «area riservata». Regola: sempre «area soci» (e «Area tesoriere» per quella del tesoriere).
- **«Ricevuta» usata per l'email di conferma.** Il glossario la vieta: la ricevuta è il documento numerato. L'oggetto dell'email dopo un pagamento online è «Ricevuta del pagamento». Regola: «Conferma del pagamento».
- **«Utile» e «perdita» invece di «avanzo» e «disavanzo»** nelle descrizioni dei limiti di `ProWatch.php` (righe 44, 46, 51, 55, 56, 57). Regola: avanzo/disavanzo, come da glossario.
- **Gergo tecnico negli errori di salvataggio: «(errore del database)», «Impossibile…».** Quattro punti (MemberBook 337, Minutes 94, PeopleService 583, Polls 111) più «Impossibile creare/aggiornare l'utente» (PeopleService 1020, 1072). Regola: «Non è stato salvato: riprova», senza nominare il database; «Non è stato possibile…» come già fanno le righe 627 e 718 di PeopleService.
- **Prima persona del programma**: «Non posso salvare», «Non ho spostato nulla» (`ProData.php:173, 182, 184`). Regola: forma neutra («Non salvato», «Nulla è stato spostato»).
- **Virgolette dritte "…" al posto di «…»** in tutti i messaggi dell'importazione (`PeopleCsv.php` 103, 348, 363, 372, 384, 521, 572, 576) e in `PeopleService.php:622`.
- **Lo stesso concetto con più nomi nei pagamenti**: «gateway», «gestore», «fornitore», «servizio di pagamento», «metodo»; e «credenziali» / «chiavi». Regola proposta: «gestore» per Stripe, PayPal e simili visti dalla segreteria, «metodo di pagamento» per chi paga, «chiavi» per ciò che si inserisce in Impostazioni → Pagamenti online.
- **Informativa privacy: tono misto.** Un paragrafo dà del tu («sui tuoi dati puoi rivolgerti», riga 179), gli altri sono impersonali o burocratici («l'interessato», «Il/La sottoscritto/a», «La presente informativa», «Natura del conferimento»). Regola: tu ovunque. Essendo un testo con valore legale, le riscritture vanno fatte leggere a un consulente prima di pubblicarle.
- **«Anonimizzata/anonimizza» e «resa anonima» alternati** (Privacy 173, 182 contro MemberBook 435 e l'informativa). Regola: «rendere anonimo»; «cancellare» diventa «eliminare» quando si parla di persone (glossario).
- **Punti esclamativi ed emoji** nei testi al socio: «grazie!» (PaymentService 731, 1135), «💬 Scrivici su WhatsApp» (PublicEvents 838).
- **Oggetti delle email in tre formati diversi**: «Oggetto — Associazione» (MemberLogin 139, PaymentService 1136), «Oggetto · Associazione» (PaymentService 677), «[Associazione] Attività: oggetto» (Notices 158). Regola proposta: «Oggetto · Associazione» ovunque (niente trattino lungo).
- **Descrizioni dei limiti troppo lunghe** (`ProWatch.php`): frasi oltre le 40 parole con parentesi a catena. Regola: una frase per idea, prima cosa fa, poi le eccezioni.

## Proposte

### MailchimpNewsletter.php:43 · Media · bacheca (impostazioni newsletter)
- Ora: «La chiave di Mailchimp deve finire con il datacenter, ad esempio «-us21».»
- Proposta: «La chiave di Mailchimp deve finire con una sigla come «-us21»: copiala per intero.»
- Perché: «datacenter» è gergo; l'errore tipico è una chiave copiata a metà.

### MemberBook.php:36 · Bassa · bacheca (scheda persona, libro soci)
- Ora: «La cessazione si registra per un socio.»
- Proposta: «La cessazione si registra solo per i soci.»
- Perché: così com'è non si capisce che è un errore.

### MemberBook.php:337 · Media · bacheca (chiusura anno, verbali, persone, votazioni)
- Ora: «Il libro soci definitivo non è stato emesso (errore del database): la chiusura non è avvenuta. Riprova; se succede ancora scrivi all'assistenza.» · stessa riga, seconda variante: «Il libro soci definitivo non è stato emesso (errore del database): non è stato salvato nulla. Riprova; se succede ancora scrivi all'assistenza.» · `Minutes.php:94`: «Impossibile salvare il verbale (errore del database).» · `PeopleService.php:583`: «Impossibile salvare la persona (errore del database).» · `Polls.php:111`: «Impossibile salvare (errore del database).»
- Proposta: «Il libro soci definitivo non è stato emesso: la chiusura non è avvenuta. Riprova; se succede ancora scrivi all'assistenza.» · «Il libro soci definitivo non è stato emesso: non è stato salvato nulla. Riprova; se succede ancora scrivi all'assistenza.» · «Il verbale non è stato salvato: riprova.» · «La persona non è stata salvata: riprova.» · «La votazione non è stata salvata: riprova.»
- Perché: «database» è gergo e non aiuta; gli ultimi tre non dicevano cosa fare.

### MemberBook.php:432 · Media · bacheca e PDF (titolo del libro soci)
- Ora: « per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura»
- Proposta: « per un anno già chiuso»
- Perché: la spiegazione è ripetuta parola per parola nella nota subito sotto (riga 435); il titolo diventa di tre righe.

### MemberBook.php:435 · Bassa · bacheca e PDF (nota del libro soci)
- Ora: «Chi era già stato cancellato non compare e i dati anagrafici sono quelli attuali.» · e, stessa riga: «non cambia più, anche se poi un socio viene modificato, cancellato o reso anonimo.»
- Proposta: «Chi era già stato eliminato non compare e i dati anagrafici sono quelli attuali.» · «non cambia più, anche se poi un socio viene modificato, eliminato o reso anonimo.»
- Perché: glossario, «eliminare» e non «cancellare».

### MemberLogin.php:133 · Bassa · email al socio (link di accesso)
- Ora: «per entrare» + « in » + nome dell'associazione + « apri questo link:»
- Proposta: «per entrare nell'area soci» + « di » + nome dell'associazione + « apri questo link:»
- Perché: «entrare in Associazione X» suona male e non dice dove si entra.

### MemberLogin.php:135 · Media · email al socio (link di accesso)
- Ora: «Se non hai chiesto tu questo messaggio, ignoralo: nessuno può entrare senza.»
- Proposta: «Se non l'hai chiesto tu, ignora questo messaggio: senza link o codice nessuno può entrare.»
- Perché: «senza» resta sospeso; così è uguale alla frase dell'email di recupero (riga 130).

### MemberLogin.php:136 · Media · email al socio (oggetto)
- Ora: «Il tuo accesso»
- Proposta: «Il tuo link per entrare»
- Perché: l'oggetto attuale non dice cosa c'è dentro né che serve subito.

### MemberLogin.php:286 · Media · area soci (scelta della password)
- Ora: «Non è possibile impostare la password per questo accesso.»
- Proposta: «Non è possibile impostare la password per questo accesso. Scrivi alla segreteria.»
- Perché: errore senza rimedio; la riga 293 lo dà già.

### Minutes.php:45 · Bassa · bacheca (verbali)
- Ora: «Indica un titolo (ad esempio «Approvazione del bilancio»).»
- Proposta: «Indica un titolo (ad esempio «Approvazione del rendiconto»).»
- Perché: il glossario usa «rendiconto», non «bilancio».

### Minutes.php:49 · Bassa · bacheca (verbali)
- Ora: «Il verbale non ha contenuto.»
- Proposta: «Scrivi il testo del verbale.»
- Perché: dice cosa fare, come gli altri messaggi dello stesso modulo.

### MissingBand.php:28 · Bassa · bacheca (banda fissa dei dati mancanti)
- Ora: «Finché mancano email e telefono della segreteria, la segreteria non può inviare messaggi ai soci (comunicazioni, avvisi agli iscritti e ricevute inviate a mano). Gli invii automatici, come il link di accesso, partono lo stesso.» · riga 29: «Finché mancano email e telefono della segreteria, la segreteria non può inviare messaggi ai soci.»
- Proposta: «Finché mancano email e telefono della segreteria non si possono inviare messaggi ai soci (comunicazioni, avvisi agli iscritti e ricevute inviate a mano). Gli invii automatici, come il link di accesso, partono lo stesso.» · «Finché mancano email e telefono della segreteria non si possono inviare messaggi ai soci.»
- Perché: «della segreteria, la segreteria» è una ripetizione.

### Newsletter.php:213 · Bassa · bacheca (esito della sincronizzazione)
- Ora: «Newsletter: %d iscritti, %d aggiornati, %d tolti, %d invariati%s%s.» con « (altri al prossimo passaggio)»
- Proposta: «Newsletter: %d aggiunti, %d aggiornati, %d tolti, %d invariati%s%s.» con « (gli altri proseguono da soli tra poco)»
- Perché: «iscritti» sembra il totale, mentre è il numero dei nuovi; «prossimo passaggio» è linguaggio interno.

### Notices.php:123 · Media · area volontari, email ai soci, menu del sito, informativa privacy
- Ora: «Per i pagamenti rimanda i soci all'area riservata o alla segreteria.» · `Pages.php:156`: «Area riservata» · `Polls.php:331`: « dall'area riservata: » · `Polls.php:350`: «Puoi votare dall'area riservata: » · `PrivacyNotice.php:146`: «Per entrare nell'area riservata si usano…» · `:147`: «…si cancella uscendo dall'area riservata.» · `:158`: «Per entrare nell'area riservata il sito usa…» · `:192`: «dati dell'accesso all'area riservata:…» · `:250`: «Accesso all'area riservata e cookie» · `:263`: «Chi ha l'accesso all'area riservata…» · `ProWatch.php:68`: «(amministrazione e area riservata del tesoriere)»
- Proposta: in tutti i punti «area soci» al posto di «area riservata» (es. «Per i pagamenti rimanda i soci all'area soci o alla segreteria.», voce di menu «Area soci», «Accesso all'area soci e cookie»); in `ProWatch.php:68` «(amministrazione e Area tesoriere)».
- Perché: il glossario riserva «area riservata» al portale; la pagina si chiama «Area soci» ma il plugin la mette nel menu come «Area riservata».

### Notices.php:138 · Bassa · area volontari (invio di un avviso)
- Ora: «avvisi nelle ultime 24 ore: aspetta un po'.»
- Proposta: «avvisi nelle ultime 24 ore: riprova più tardi.»
- Perché: «aspetta un po'» è colloquiale e vago.

### Notices.php:157 · Bassa · email al socio (avviso di un'attività)
- Ora: «Per rispondere rivolgiti alla segreteria dell'associazione.»
- Proposta: «Per rispondere scrivi alla segreteria.»
- Perché: più corto e diretto; il nome dell'associazione è già nella riga sopra.

### OfflinePayments.php:106 · Bassa · area soci (pagamento online)
- Ora: «I pagamenti online non sono attivi: la quota si paga in sede.» · `PaymentService.php:296`: «Il pagamento online non è disponibile: la quota si paga in sede.»
- Proposta: una sola forma per entrambi: «I pagamenti online non sono attivi: la quota si paga in sede.»
- Perché: stesso caso, due frasi diverse.

### Pages.php:42 · Bassa · bacheca (elenco delle pagine da creare)
- Ora: «richieste di accesso e collegamenti alla gestione.»
- Proposta: «richieste di accesso e link alla gestione.»
- Perché: il glossario sceglie «link».

### PaymentConfig.php:166 · Media · bacheca (Impostazioni → Pagamenti online)
- Ora: «Gateway di pagamento non valido.»
- Proposta: «Metodo di pagamento non valido: sceglilo dall'elenco.»
- Perché: «gateway» è gergo e manca il rimedio.

### PaymentConfig.php:206 · Media · bacheca (Impostazioni → Pagamenti online)
- Ora: «Stripe: manca il segreto del webhook (whsec_…): senza di esso i pagamenti non possono essere confermati automaticamente.»
- Proposta: «Stripe: manca il segreto del webhook (whsec_…): senza, i pagamenti non si confermano da soli.»
- Perché: «senza di esso» e il passivo sono burocratese.

### PaymentConfig.php:211 · Bassa · bacheca (Impostazioni → Pagamenti online)
- Ora: «Stripe in modalità reale richiede che il sito sia servito in https: oggi l'indirizzo del sito è http.» (uguale per PayPal alla riga 232; «il sito deve essere servito in https» alla riga 252)
- Proposta: «Stripe in modalità reale funziona solo se il sito usa https: oggi l'indirizzo del sito è http.» (e così per PayPal; riga 252: «il sito deve usare https»)
- Perché: «servito in https» è frase da tecnico.

### PaymentConfig.php:214 · Bassa · bacheca (Impostazioni → Pagamenti online)
- Ora: «Stripe è in modalità REALE: i pagamenti saranno veri.» · riga 235: «PayPal è in modalità REALE: i pagamenti saranno veri.»
- Proposta: «Stripe è in modalità reale: i pagamenti sono veri.» · «PayPal è in modalità reale: i pagamenti sono veri.»
- Perché: il maiuscolo grida; è già un avviso evidenziato.

### PaymentService.php:495 · Bassa · bacheca (rimborso di un pagamento online)
- Ora: «L'incasso è ancora registrato in prima nota: annullalo prima dalla prima nota, poi potrai restituire i soldi da qui.»
- Proposta: «L'incasso è ancora in prima nota: annullalo lì, poi potrai restituire i soldi da qui.»
- Perché: «prima nota» due volte e «prima dalla prima» inciampa.

### PaymentService.php:519 · Bassa · bacheca (rimborso di un pagamento online)
- Ora: «Le credenziali di {metodo} non sono valide: controllale in Impostazioni → Pagamenti online.» · righe 583 e 588: «Le credenziali di {metodo} non sono impostate: inseriscile in Impostazioni → Pagamenti online.»
- Proposta: «Le chiavi di {metodo} non sono valide: controllale in Impostazioni → Pagamenti online.» · «Le chiavi di {metodo} non sono impostate: inseriscile in Impostazioni → Pagamenti online.»
- Perché: altrove (riga 523, PaymentConfig) si chiamano sempre «chiavi».

### PaymentService.php:604 · Media · bacheca (rimborso non confermato)
- Ora: «Il servizio di {metodo} non risponde: il rimborso di {importo} non è stato confermato. Riprova tra poco con lo stesso importo: ripetere lo stesso invio non rimborsa due volte. Con un importo diverso non si riparte finché questo non è chiarito.»
- Proposta: «Il servizio di {metodo} non risponde: il rimborso di {importo} non è confermato. Riprova tra poco con lo stesso importo: non verrà rimborsato due volte. Un importo diverso non è accettato finché questo rimborso non è chiarito.»
- Perché: «ripetere lo stesso invio» e «non si riparte» sono oscuri per chi sta restituendo soldi.

### PaymentService.php:611 · Media · bacheca (rimborso non confermato)
- Ora: «Non è certo che il rimborso non sia partito: il pagamento resta «da controllare» e si può ripetere solo lo stesso importo ({importo}).»
- Proposta: «Il rimborso potrebbe essere partito lo stesso: il pagamento resta «da controllare» e puoi ripetere solo lo stesso importo ({importo}).»
- Perché: doppia negazione da rileggere due volte.

### PaymentService.php:626 · Bassa · bacheca (nota sul pagamento da controllare)
- Ora: «Rimborso di {importo} confermato dal gestore ma registrato in modo anomalo: da controllare.»
- Proposta: «Rimborso di {importo} confermato dal gestore ma non registrato bene qui: da controllare.»
- Perché: «in modo anomalo» non dice dove sta il problema.

### PaymentService.php:678 · Media · email al socio (rimborso)
- Ora: «Ciao, abbiamo restituito {importo} del pagamento che hai fatto il {data} a {associazione}. Motivo: {motivo}. L'accredito compare sul tuo metodo di pagamento in genere entro pochi giorni lavorativi, fino a 10 a seconda della banca o del metodo di pagamento. Se hai dubbi, scrivi alla segreteria. — {associazione}»
- Proposta: «Ciao, ti abbiamo restituito {importo} del pagamento del {data} a {associazione}. Motivo: {motivo}. L'accredito arriva di solito in pochi giorni lavorativi (fino a 10, secondo la banca o il metodo di pagamento). Se hai dubbi, scrivi alla segreteria. — {associazione}»
- Perché: la frase sull'accredito ripete due volte «metodo di pagamento». Attenzione: la riga 680 sostituisce il pezzo «scrivi alla segreteria. —», che va lasciato identico.

### PaymentService.php:731 · Bassa · area soci ed email al socio (esito del pagamento)
- Ora: «Pagamento ricevuto: grazie!» · riga 1135, chiusa dell'email: «Grazie!»
- Proposta: «Pagamento ricevuto, grazie.» · «Grazie.»
- Perché: niente punti esclamativi.

### PaymentService.php:1136 · Alta · email al socio (oggetto dopo il pagamento online)
- Ora: «Ricevuta del pagamento»
- Proposta: «Conferma del pagamento»
- Perché: il glossario vieta «ricevuta» per l'email di conferma: il socio la scambia per la ricevuta numerata.

### PeopleCsv.php:103 · Bassa · bacheca (importazione dei soci, anteprima ed errori)
- Ora: «Nella prima riga servono almeno le colonne "Nome" e "Cognome" (per un file di soli enti basta "Denominazione"). Trovate: » · stesse virgolette dritte alle righe 348, 363, 372, 384, 521, 572, 576 e in `PeopleService.php:622` («usa "Password dimenticata" oppure scrivi alla segreteria.»)
- Proposta: «Nella prima riga servono almeno le colonne «Nome» e «Cognome» (per un file di soli enti basta «Denominazione»). Trovate: » · in tutti gli altri punti «…» al posto di "…"
- Perché: lo standard è «…».

### PeopleCsv.php:473 · Bassa · bacheca (anteprima dell'importazione)
- Ora: «Senza tessera: assegnabile dopo»
- Proposta: «Senza tessera: potrai assegnarla dopo»
- Perché: «assegnabile» è freddo; si dà del tu.

### PeopleCsv.php:521 · Media · bacheca (errori dell'importazione)
- Ora: «Ospite senza l'indicazione del socio ospitante (colonna "Ospite di": tessera, email o nome e cognome). Per importare ospiti senza ospitante spegni la regola in Impostazioni → Limiti e soglie»
- Proposta: «Manca il socio che ospita (colonna «Ospite di»: tessera, email o nome e cognome). Per importare ospiti senza ospitante spegni la regola in Impostazioni → Limiti e soglie»
- Perché: «senza l'indicazione del socio ospitante» è burocratese.

### PeopleCsv.php:576 · Bassa · bacheca (errori dell'importazione)
- Ora: «Socio ospitante ambiguo: "» … «" (indica la tessera)»
- Proposta: «Più soci corrispondono a «» … «»: indica la tessera»
- Perché: «ambiguo» non spiega il problema.

### PeopleService.php:680 · Media · bacheca (attivazione dell'accesso di un socio)
- Ora: «Con questa email c'è già un dipendente del sito: i due accessi restano separati. Per dare al socio un potere (segreteria, tesoriere, staff) serve un'altra email nella sua scheda.» · riga 706: «Ha ancora dei poteri (segreteria, presidente o vicepresidente, tesoriere, staff o altri ruoli del sito): toglili prima.»
- Proposta: «Con questa email c'è già un dipendente del sito: i due accessi restano separati. Per dare al socio un ruolo (segreteria, tesoriere, staff) serve un'altra email nella sua scheda.» · «Ha ancora dei ruoli (segreteria, presidente o vicepresidente, tesoriere, staff o altri ruoli del sito): toglili prima.»
- Perché: «potere» è improprio; in tutto il resto si dice «ruolo» (es. riga 946).

### PeopleService.php:1020 · Bassa · bacheca (salvataggio di un socio)
- Ora: «Impossibile creare l'utente WordPress: » · riga 1072: «Impossibile aggiornare l'utente WordPress: »
- Proposta: «Non è stato possibile creare l'utente WordPress: » · «Non è stato possibile aggiornare l'utente WordPress: »
- Perché: stessa forma delle righe 627 e 718; «Impossibile» suona da macchina.

### PeopleService.php:1115 · Bassa · bacheca (tessera di un fondatore)
- Ora: «Il socio fondatore ha la tessera sempre rinnovata.»
- Proposta: «Il socio fondatore ha la tessera sempre valida: non c'è nulla da rinnovare o togliere.»
- Perché: è un errore ma sembra una semplice informazione; non si capisce perché l'azione non è riuscita.

### PersonSearchRules.php:53 · Media · bacheca (risultati della ricerca persone)
- Ora: «quota non in regola» · riga 55: «quota valida fino al »
- Proposta: «tessera non in regola» · «tessera valida fino al »
- Perché: la data è la scadenza della tessera; la quota è ciò che si paga. Altrove: «in regola con la tessera» (`PeopleService.php:292`).

### Plugin.php:38 · Bassa · bacheca (avviso di incompatibilità)
- Ora: «Aggiorna Associazione Semplice Pro e Associazione Semplice all'ultima versione; il resto funziona e i dati sono al sicuro.»
- Proposta: «Aggiorna entrambi all'ultima versione; il resto funziona e i dati sono al sicuro.»
- Perché: il nome del plugin compare quattro volte in due righe.

### Polls.php:331 · Alta · email e notifica al socio (apertura di sondaggio o votazione)
- Ora: «è aperta » + «un sondaggio» / «una votazione» + « per i soci: «» … «». Puoi rispondere fino al »
- Proposta: «è aperto un sondaggio» / «è aperta una votazione» (il verbo va dentro la scelta) + « per i soci: «» … «». Puoi rispondere fino al »
- Perché: oggi per i sondaggi esce «è aperta un sondaggio», errore di grammatica in un'email a tutti i soci.

### Privacy.php:118 · Media · bacheca (conferma di eliminazione o anonimizzazione)
- Ora: « Attenzione: i dati del libro soci (nome, codice fiscale, nascita, residenza, date) restano nei libri degli anni fiscali già chiusi (» … «), come dovere di conservazione: non si cancellano né si rendono anonimi.»
- Proposta: « Attenzione: i dati del libro soci (nome, codice fiscale, nascita, residenza, date) restano nei libri degli anni fiscali già chiusi (» … «), perché la legge chiede di conservarli: non si eliminano né si rendono anonimi.»
- Perché: «come dovere di conservazione» è burocratese e non italiano corrente.

### Privacy.php:128 · Media · bacheca (conferma di anonimizzazione)
- Ora: « una descrizione che non si modifica (il chiuso è chiuso): se vi è scritto a mano il nome della persona, resta. Riapri l'anno fiscale solo se devi toglierlo.»
- Proposta: « una descrizione che non si può più modificare: se contiene il nome della persona, resta. Per toglierlo devi riaprire l'anno fiscale.»
- Perché: «il chiuso è chiuso» è un modo di dire interno; «se vi è scritto» è burocratese.

### Privacy.php:173 · Bassa · bacheca (motivi per cui non si può rendere anonima una persona)
- Ora: «Già anonimizzata.» · riga 182: «Ha degli ospiti collegati: anonimizza o sposta prima loro.»
- Proposta: «È già stata resa anonima.» · «Ha degli ospiti collegati: rendi anonimi o sposta prima loro.»
- Perché: «anonimizzare» è gergo; l'informativa e il libro soci dicono «rendere anonimo».

### PrivacyNotice.php:173 · Bassa · pagina «Informativa privacy» e PDF
- Ora: «Per esercitare i propri diritti o per qualsiasi chiarimento: »
- Proposta: «Per esercitare i tuoi diritti o chiedere un chiarimento: »
- Perché: si dà del tu, come nel paragrafo sul referente (riga 179).

### PrivacyNotice.php:189 · Bassa · pagina «Informativa privacy» e PDF
- Ora: «dati dell'iscrizione: tipo di socio, numero e scadenza della tessera, data di ingresso e di cessazione, eventuali cariche;» · riga 241: «i dati dei soci sono conservati per tutta la durata dell'iscrizione;»
- Proposta: «dati dell'adesione: tipo di socio, numero e scadenza della tessera, data di ingresso e di cessazione, eventuali cariche;» · «i dati dei soci sono conservati finché si è soci;»
- Perché: per il glossario «iscrizione» è solo quella a un corso; due righe sotto «iscrizioni a corsi ed eventi» ha l'altro significato.

### PrivacyNotice.php:196 · Bassa · pagina «Informativa privacy» e PDF
- Ora: «Per le persone ospitate dai soci (non soci) sono trattati i dati necessari alla partecipazione: nome, cognome, recapito e partecipazioni a corsi ed eventi.»
- Proposta: «Per gli ospiti dei soci sono trattati i dati necessari alla partecipazione: nome, cognome, recapito e partecipazioni a corsi ed eventi.»
- Perché: il termine del glossario è «ospite».

### PrivacyNotice.php:225 · Media · pagina «Informativa privacy» e PDF
- Ora: «I dati possono essere comunicati a terzi esclusivamente per le finalità connesse agli eventi e alle attività cui si partecipa e nei limiti di quanto necessario: ad esempio a chi ospita o organizza l'evento insieme all'Associazione»
- Proposta: «I dati possono essere comunicati a terzi solo quando serve per gli eventi e le attività a cui partecipi, e solo i dati necessari: ad esempio a chi ospita o organizza l'evento insieme all'Associazione»
- Perché: frase di oltre 60 parole con apertura in burocratese («esclusivamente per le finalità connesse… nei limiti di quanto necessario»).

### PrivacyNotice.php:254 · Media · pagina «Informativa privacy» e PDF (titolo di sezione)
- Ora: «Natura del conferimento»
- Proposta: «Dati obbligatori e facoltativi»
- Perché: titolo incomprensibile per un socio; gli altri titoli sono già in chiaro («Quali dati trattiamo»).

### PrivacyNotice.php:256 · Media · pagina «Informativa privacy» e PDF
- Ora: «Il conferimento dei dati è necessario per iscriversi, partecipare alle attività e per gli adempimenti di legge: senza i dati richiesti non è possibile completare l'iscrizione o la prenotazione.»
- Proposta: «I dati richiesti servono per diventare socio, partecipare alle attività e rispettare gli obblighi di legge: senza non è possibile completare l'adesione o la prenotazione.»
- Perché: «il conferimento dei dati» è burocratese.

### PrivacyNotice.php:260 · Media · pagina «Informativa privacy» e PDF (titolo di sezione)
- Ora: «I diritti dell'interessato»
- Proposta: «I tuoi diritti»
- Perché: «interessato» è gergo legale; si dà del tu.

### PrivacyNotice.php:262 · Media · pagina «Informativa privacy» e PDF
- Ora: «Ogni persona può chiedere all'Associazione l'accesso ai propri dati, la rettifica, la cancellazione, la limitazione del trattamento, la portabilità e può opporsi al trattamento (artt. 15-22 GDPR); può inoltre revocare in ogni momento il consenso, senza pregiudicare la liceità del trattamento già avvenuto.»
- Proposta: «Puoi chiedere all'Associazione l'accesso ai tuoi dati, la rettifica, la cancellazione, la limitazione del trattamento e la portabilità, e puoi opporti al trattamento (artt. 15-22 GDPR). Puoi anche revocare il consenso in ogni momento: ciò che è stato fatto prima resta valido.»
- Perché: frase di 45 parole, impersonale, con chiusa in legalese.

### PrivacyNotice.php:263 · Bassa · pagina «Informativa privacy» e PDF
- Ora: «Chi ha l'accesso all'area riservata può scaricare i propri dati dalla sezione «Il mio profilo». Per le altre richieste basta scrivere ai recapiti indicati sopra: si risponde entro un mese.»
- Proposta: «Dall'area soci puoi scaricare i tuoi dati nella sezione «Il mio profilo». Per le altre richieste scrivi ai recapiti indicati sopra: rispondiamo entro un mese.»
- Perché: tu al posto dell'impersonale («si risponde»), e «area soci».

### PrivacyNotice.php:264 · Media · pagina «Informativa privacy» e PDF
- Ora: «Se ritiene che il trattamento violi la normativa, l'interessato può proporre reclamo al Garante per la protezione dei dati personali (www.garanteprivacy.it).»
- Proposta: «Se ritieni che il trattamento violi la normativa, puoi presentare reclamo al Garante per la protezione dei dati personali (www.garanteprivacy.it).»
- Perché: terza persona («ritiene», «l'interessato») in un testo che altrove dà del tu.

### PrivacyNotice.php:270 · Bassa · pagina «Informativa privacy» e PDF
- Ora: «La presente informativa è aggiornata al »
- Proposta: «Questa informativa è aggiornata al »
- Perché: «la presente» è burocratese.

### PrivacyNotice.php:363 · Media · PDF dell'informativa (presa visione da firmare)
- Ora: «Il/La sottoscritto/a dichiara di aver ricevuto e letto la presente informativa.»
- Proposta: «Dichiaro di aver ricevuto e letto questa informativa.»
- Perché: doppia forma con la barra (da evitare, vedi glossario) e burocratese; chi firma parla in prima persona.

### PrivateFiles.php:247 · Media · bacheca (file riservato che non si apre)
- Ora: «la chiave di questo sito non coincide con quella usata per salvarlo, oppure il file è stato modificato. Il sito non lo rifà in silenzio: rimetti la chiave di prima o ripristina una copia di sicurezza.»
- Proposta: «la chiave di questo sito non coincide con quella usata per salvarlo, oppure il file è stato modificato: rimetti la chiave di prima o ripristina una copia di sicurezza.»
- Perché: «Il sito non lo rifà in silenzio» non si capisce e non serve.

### PrivateFiles.php:249 · Bassa · bacheca (file riservato che non si apre)
- Ora: «l'impronta del contenuto non coincide con quella registrata: il file è stato modificato o sostituito. Va ripristinato da una copia di sicurezza.»
- Proposta: «il contenuto non è più quello salvato: il file è stato modificato o sostituito. Ripristinalo da una copia di sicurezza.»
- Perché: «impronta» è gergo; l'imperativo è più diretto del «va ripristinato» (uguale alle righe 251 e 253).

### PrivateFolder.php:570 · Bassa · bacheca (esito del controllo sulla cartella riservata)
- Ora: «tre file di prova, fatti come i file veri, messi nella cartella non si scaricano dall'indirizzo web del sito. Il controllo parte dal sito stesso: per la certezza chiedi conferma a chi gestisce il server.»
- Proposta: «tre file di prova messi nella cartella, simili a quelli veri, non si scaricano dall'indirizzo web del sito. Il controllo parte dal sito stesso: per esserne certo chiedi conferma a chi gestisce il server.»
- Perché: due incisi di fila rendono la frase faticosa; «per la certezza» non è italiano corrente.

### PrivateFolder.php:576 · Media · bacheca (esito del controllo sulla cartella riservata)
- Ora: «non verificabile: nemmeno un file messo in una cartella pubblica del sito si scarica dalla richiesta del sito a sé stesso (può dipendere da un firewall, da una rete di distribuzione dei contenuti o da un indirizzo interno).»
- Proposta: «non verificabile: il sito non riesce a scaricare da sé stesso nemmeno un file pubblico (può dipendere da un firewall, da una rete di distribuzione dei contenuti o da un indirizzo interno).»
- Perché: «si scarica dalla richiesta del sito a sé stesso» è incomprensibile.

### PrivateFolder.php:627 · Media · bacheca (allarme: cartella raggiungibile da internet)
- Ora: «Va chiesto a chi gestisce il server o l'hosting del sito di bloccare l'accesso diretto a quella cartella oppure di spostarla fuori dalla parte pubblica del sito.»
- Proposta: «Chiedi a chi gestisce il server o l'hosting di bloccare l'accesso diretto a quella cartella, oppure di spostarla fuori dalla parte pubblica del sito.»
- Perché: è l'unica azione richiesta in un allarme: va detta con il tu, non con un passivo.

### ProBoot.php:241 · Bassa · bacheca (funzione sospesa per licenza)
- Ora: «intanto il resto funziona e i dati restano tutti.»
- Proposta: «intanto il resto funziona e i dati sono al sicuro.»
- Perché: stessa rassicurazione di `Plugin.php:38`, detta nello stesso modo.

### ProBoot.php:289 · Bassa · bacheca (Impostazioni → Limiti e soglie)
- Ora: «Giorni senza controllo della licenza riuscito, prima di sospendere le funzioni Pro»
- Proposta: «Giorni senza un controllo riuscito della licenza prima di sospendere le funzioni Pro»
- Perché: l'ordine delle parole fa leggere «licenza riuscito».

### ProData.php:173 · Media · bacheca (salvataggio di un'attività con risorsa)
- Ora: « Non ho spostato nulla: » · riga 182: «Non posso salvare: » … « Il blocco è rimasto com'era (gli altri dati sono stati salvati).» · riga 184: «Non posso salvare: la risorsa risulterebbe occupata due volte.»
- Proposta: « Nulla è stato spostato: » · «Blocco non salvato: » … « È rimasto com'era (gli altri dati sono stati salvati).» · «Risorsa non salvata: risulterebbe occupata due volte.»
- Perché: prima persona del programma, da evitare; inoltre «Non posso salvare» contraddice «gli altri dati sono stati salvati».

### ProData.php:427 · Bassa · bacheca (fine della configurazione guidata)
- Ora: « Ora inserisci le chiavi del fornitore scelto.»
- Proposta: « Ora inserisci le chiavi del gestore scelto.»
- Perché: negli altri messaggi dei pagamenti Stripe e PayPal sono «il gestore».

### ProSettings.php:40 · Bassa · bacheca (interruttori delle funzioni)
- Ora: «Notifiche push ai dispositivi dei soci»
- Proposta: «Notifiche sul telefono dei soci»
- Perché: «push» e «dispositivi» sono gergo; la riga sopra dice già «sul telefono».

### ProWatch.php:44 · Media · bacheca (Impostazioni → Limiti e soglie, controllo di bilancio)
- Ora: riga 44 «o con un utile troppo alto» · riga 46 «Una perdita o un utile fuori misura si scoprirebbero solo a fine anno.» · riga 51 «si accetta di chiudere l'anno in perdita» · riga 55 «Utile eccessivo: soglia» · riga 56 «un utile molto più alto del necessario va destinato alle finalità dell'ente.» · riga 57 «un utile elevato accumulato negli anni è un rischio per gli enti non commerciali.»
- Proposta: «o con un avanzo troppo alto» · «Un disavanzo o un avanzo fuori misura si scoprirebbero solo a fine anno.» · «si accetta di chiudere l'anno in disavanzo» · «Avanzo eccessivo: soglia» · «un avanzo molto più alto del necessario va destinato alle finalità dell'ente.» · «un avanzo elevato accumulato negli anni è un rischio per gli enti non commerciali.»
- Perché: il glossario vieta «utile» e «perdita»; le righe 57 e 58 usano già «avanzo» per la stessa cosa.

### ProWatch.php:67 · Bassa · bacheca (Impostazioni → Limiti e soglie)
- Ora: «Spunta «attribuzione automatica» accesa di partenza nel modulo spese»
- Proposta: ««Attribuzione automatica» già spuntata nel modulo delle spese»
- Perché: «spunta accesa di partenza» è contorto.

### ProWatch.php:68 · Media · bacheca (Impostazioni → Limiti e soglie)
- Ora: «Spegnendo questo limite la spunta parte spenta e chi registra sceglie ogni volta chi ha anticipato e il fondo.»
- Proposta: «Se la spegni, la spunta parte spenta e chi registra sceglie ogni volta chi ha anticipato e il fondo.»
- Perché: è un interruttore, non un limite: «spegnere un limite» confonde.

### ProWatch.php:80 · Media · bacheca (Impostazioni → Limiti e soglie)
- Ora: «Aperti o già liquidati, quelli dell'attività della spesa prima, poi i più vecchi, finché i documenti coprono quanto accantonato.»
- Proposta: «Si parte dai fondi dell'attività della spesa, aperti o già liquidati, poi dai più vecchi, finché i documenti coprono quanto accantonato.»
- Perché: frase senza verbo e senza soggetto: non si capisce a cosa si riferisce.

### ProWatch.php:92 · Media · bacheca (Impostazioni → Limiti e soglie)
- Ora: «A fine anno chi ha avuto rimborsi dai fondi deve trovarsi con le spese sostenute.» · più avanti: «1 = chiude e basta (resta l'avviso in Bacheca)»
- Proposta: «A fine anno i rimborsi presi dai fondi devono corrispondere alle spese documentate.» · «1 = chiude comunque (resta l'avviso in Bacheca)»
- Perché: «deve trovarsi con le spese» non si capisce; «e basta» è troppo colloquiale.

### ProWatch.php:97 · Bassa · bacheca (Impostazioni → Limiti e soglie)
- Ora: «Tolleranza nella quadratura rimborsi e spese» · riga 121: «Avviso in Bacheca se rimborsi e spese non quadrano»
- Proposta: «Tolleranza nel confronto tra rimborsi e spese» · «Avviso in Bacheca se rimborsi e spese non corrispondono»
- Perché: «quadratura» è nella lista dei termini da non usare; la descrizione parla già di «differenza».

### ProWatch.php:104 · Media · bacheca (Impostazioni → Limiti e soglie)
- Ora: «Dal primo giorno del mese indicato prima dell'ultimo mese dell'anno fiscale, ai ruoli amministrativi compare in Bacheca e nelle pagine di Cassa, Contabilità, Report e Preventivo un avviso fisso (non chiudibile) con le discrepanze da verificare, e solo se ce ne sono.»
- Proposta: «Quanti mesi prima dell'ultimo mese dell'anno fiscale parte l'avviso fisso (non chiudibile) con le discrepanze da verificare. Compare ai ruoli amministrativi, solo se ce ne sono, in Bacheca e nelle pagine di Cassa, Contabilità, Report e Preventivo.»
- Perché: 45 parole con il soggetto in fondo; «del mese indicato prima dell'ultimo mese» va riletto tre volte.

### ProWatch.php:110 · Media · bacheca (Impostazioni → Limiti e soglie)
- Ora: «la chiusura vale fino a lunedì (è memorizzata per utente) e l'avviso ricompare se le discrepanze ci sono ancora.»
- Proposta: «chi lo chiude non lo rivede fino a lunedì; poi ricompare se le discrepanze ci sono ancora.»
- Perché: «la chiusura» qui si confonde con la chiusura dell'anno; «memorizzata per utente» è gergo.

### ProWatch.php:116 · Media · bacheca (Impostazioni → Limiti e soglie)
- Ora: «Quando parte l'avviso fisso, una email all'inizio del preavviso e una all'inizio della fase fissa ricordano ad amministratori, segreteria, tesorieri, presidente e vicepresidente le discrepanze da verificare (se ce ne sono).»
- Proposta: «Una email all'inizio del preavviso e una all'inizio dell'avviso fisso ricordano ad amministratori, segreteria, tesorieri, presidente e vicepresidente le discrepanze da verificare (se ce ne sono).»
- Perché: l'apertura contraddice il seguito (la prima email parte al preavviso, non all'avviso fisso).

### ProWatch.php:214 · Alta · bacheca e Area tesoriere (modulo di una nuova spesa)
- Ora: etichetta « la spesa è mia o si distribuisce da sola ai fondi giusti» · descrizione «Acceso: se hai un fondo con documentazione da coprire la spesa è tua e va ai tuoi fondi; altrimenti (ad esempio la registri per conto di un consigliere o di un socio) si distribuisce tra i fondi con più documentazione da coprire, e non serve indicare altro (un volontario resta sempre sui propri fondi). Spento: scegli tu chi ha anticipato (un volontario o anche un socio non volontario, ad esempio da rimborsare per un favore; di partenza tu, se hai un fondo da coprire, poi i volontari) e il fondo: automatico, uno in particolare o «Nessun fondo» (per un semplice rimborso senza fondi). Un beneficiario scelto qui vale sempre.»
- Proposta: etichetta « il gestionale sceglie da solo a quali fondi va la spesa» · descrizione «Acceso: se hai un fondo con documentazione da coprire, la spesa va ai tuoi fondi; altrimenti si distribuisce tra i fondi con più documentazione da coprire. Un volontario resta sempre sui propri fondi. Spento: scegli tu chi ha anticipato la spesa (anche un socio non volontario) e il fondo: automatico, uno in particolare o «Nessun fondo» per un semplice rimborso. Il beneficiario scelto qui vale sempre.»
- Perché: 110 parole, cinque parentesi, una dentro l'altra: chi registra una spesa dal telefono non la legge. Si perde solo il dettaglio sull'ordine dell'elenco, che si vede a schermo.

### ProWatch.php:215 · Media · bacheca e Area tesoriere (modulo di una nuova spesa)
- Ora: «Con una spesa documentata di norma si attribuisce da sola, anche ai fondi già liquidati. Qui si può scegliere a mano, o escludere la spesa.»
- Proposta: «Una spesa documentata di norma si attribuisce da sola, anche ai fondi già liquidati. Qui puoi scegliere il fondo a mano o escludere la spesa.»
- Perché: la prima frase non ha soggetto («Con una spesa… si attribuisce»); tu al posto dell'impersonale.

### PublicEvents.php:452 · Bassa · bacheca (pagina dell'evento, riquadro prenotazioni)
- Ora: «Posti al massimo per richiesta (0 = nessun limite)»
- Proposta: «Numero massimo di posti per richiesta (0 = nessun limite)»
- Perché: «posti al massimo» è sgrammaticato.

### PublicEvents.php:454 · Media · bacheca (pagina dell'evento, riquadro prenotazioni)
- Ora: «I soci prenotano sempre dal loro accesso.»
- Proposta: «I soci prenotano sempre dall'area soci.»
- Perché: «dal loro accesso» non indica un posto; il termine è «area soci».

### PublicEvents.php:507 · Bassa · bacheca (pagina dell'evento, riquadro dei dati)
- Ora: «<strong>Impaginazione tua:</strong> la pagina ha un'impaginazione automatica. Per comporla a piacere»
- Proposta: «<strong>Impaginazione personalizzata:</strong> la pagina si impagina da sola. Per comporla come vuoi»
- Perché: «Impaginazione tua» è goffo e «impaginazione» è ripetuta due volte in sei parole.

### PublicEvents.php:689 · Bassa · sito pubblico (pagina dell'evento)
- Ora: «« Tutti gli eventi» (anche alla riga 810)
- Proposta: «← Tutti gli eventi»
- Perché: «« » è la virgoletta che il plugin usa per le citazioni: come freccia sembra un refuso.

### PublicEvents.php:838 · Bassa · sito pubblico (pagina dell'evento, contatti)
- Ora: «💬 Scrivici su WhatsApp»
- Proposta: «Scrivici su WhatsApp»
- Perché: è l'unica emoji nei testi del plugin; il pulsante ha già il suo stile.

### PublicEvents.php:1113 · Alta · sito pubblico (elenco degli eventi, date ricorrenti)
- Ora: «Tutti i » + giorno della settimana + « · e così via»
- Proposta: «Ogni » + giorno della settimana
- Perché: con la domenica esce «Tutti i domenica» e con gli altri giorni resta il singolare («Tutti i lunedì» va bene, «Tutti i sabato» no); «e così via» è vago e colloquiale.

### Push.php:61 · Bassa · area soci (attivazione delle notifiche)
- Ora: «Questo dispositivo non può ricevere notifiche (servizio di push non riconosciuto).»
- Proposta: «Questo telefono o browser non può ricevere le notifiche.»
- Perché: la parentesi è un dettaglio tecnico che al socio non serve.

### Push.php:65 · Media · area soci (attivazione delle notifiche)
- Ora: «Sottoscrizione non valida.»
- Proposta: «Le notifiche non si sono attivate: riprova.»
- Perché: «sottoscrizione» è il termine tecnico del browser; il socio non sa cosa fare.

## Da verificare (sospetti errori di fatto, non riscritti)
- `Pages.php:36`: la pagina creata si chiama «Attività ed eventi», ma il suo suggerimento e il modulo corrispondente (`Modules.php:33`) dicono «Corsi ed eventi». Decidere quale nome vale (è un nome di pagina, non l'ho riscritto).
- `ProBoot.php:233` dice «inserisci la chiave di licenza in Impostazioni», mentre `ProBoot.php:255` dà il percorso «Impostazioni → Soci e quote → Licenza»: verificare quale percorso è giusto e usarlo in entrambi.
- `PeopleService.php:619`: «La password deve avere almeno 8 caratteri.» ha il numero scritto a mano; `MemberPassword.php:34` lo prende dalla costante (oggi 8). Se la costante cambia i due messaggi divergono.
- `Polls.php:349-350`: il promemoria ha oggetto «Ultimo giorno per votare» e testo «la votazione «…» si chiude il…» anche quando è un sondaggio (riga 331 distingue i due casi); verificare inoltre che parta davvero solo l'ultimo giorno.
- `PeopleCsv.php:491`: nell'anteprima dell'importazione la colonna degli anni è resa con «Iscritto: Anno 2025 → tessera 2025/2026». Per il glossario «iscritto» vale solo per i corsi: se la colonna indica gli anni di tessera, l'etichetta dovrebbe essere «Tessera:».
- `PeopleService.php:680`: «un dipendente del sito» indica chi ha un utente WordPress con un ruolo di gestione. Verificare che il termine sia quello usato anche nelle schermate degli utenti: un volontario di segreteria non è un «dipendente».
- `PublicEvents.php:454`: «I non soci pagano in anticipo con bonifico e inviano la ricevuta alla segreteria.» Qui «ricevuta» è quella del bonifico, non la ricevuta numerata dell'associazione: verificare se nelle schermate collegate si dice «contabile» o «ricevuta del bonifico» e allineare.
- `PaymentConfig.php:232`: per PayPal la modalità di prova è chiamata «sandbox», per Stripe «di prova (test)» (righe 196, 203): verificare come è scritta nell'elenco a schermo e usare lo stesso nome.
- `PrivacyNotice.php` (tutte le proposte): è un testo con valore legale. Le riscritture cambiano solo la forma, ma vanno fatte leggere a un consulente prima di applicarle.
