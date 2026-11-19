# Plugin · Bacheca (file M–Z)
File letti: 45 · Proposte: 127 (Alta 3, Media 69, Bassa 55)

Percorso di tutti i file: `wordpress/associazionesemplice/includes/Admin/`.
Convenzioni di citazione: «Ora» riporta il testo come lo legge l'utente (nel PHP gli apostrofi sono scritti `\'`); i punti in cui il codice inserisce un valore sono indicati con `[N]`, `[data]`, `[nome]` e simili e restano dove sono; `…` segna una parte della stessa stringa non citata e da lasciare com'è. Tag HTML e segnaposto `{…}` restano identici.
File letti senza una voce propria tra le proposte: `PersonSearch.php`, `ProDashboard.php`, `Ui.php`, `VatFields.php`, `WatchExports.php` (testi a posto); `ProMenu.php`, `ProPanels.php`, `RegistersActions.php`, `TechActions.php` (citati solo nei difetti ricorrenti, in voci di altri file o in «Da verificare»).

## Difetti ricorrenti

- **«Area riservata» e «area soci» per la stessa pagina.** Il glossario vuole «area soci» (e riserva «area riservata» all'account sul portale). Nello stesso file convivono le due forme (`PeoplePage.php` usa «area soci» alle righe 78, 428, 430, 459 e «area riservata» alle 237, 258, 356, 450, 479, 544, 558, 679, 682, 684). Regola: sempre «area soci». Altre righe: `MemberAccessChoice.php` 23; `PollsPage.php` 23; `RefundsPage.php` 37; `SettingsPage.php` 75, 77, 117.
- **Il voi al posto del tu.** `PeoplePage.php` 305 («vostra stessa sezione»), `WizardPage.php` 91 («Accettate ospiti»), `SettingsPage.php` 90 («su cui usate WhatsApp»). Regola: sempre tu, oppure soggetto «l'ente».
- **La segreteria in terza persona quando chi legge è la segreteria.** «La segreteria crea…», «La segreteria può registrare…» (`PollsPage.php` 23, `RefundsPage.php` 37, `ResourcesPage.php` 192, 331). Regola: tu («Crea…», «Da qui puoi…»); «la segreteria» solo quando si spiega a un amministratore che cosa fa quel ruolo.
- **Il programma che parla in prima persona.** «Non ho trovato», «Ti propongo», «Elimino il suo utente?», «Annullo questa prenotazione… Confermi?», «lo indichiamo noi» (`TribeImportPage.php` 18, 74; `PeoplePage.php` 441; `ResourcesPage.php` 257; `PaymentsPage.php` 87). Regola: le conferme sono una domanda all'infinito che dice cosa succede («Eliminare…?»), mai il solo «Confermi?» (`ResourcesPage.php` 256, 346).
- **Gergo tecnico evitabile.** «in background» (`MessagesPage.php` 53, `ProActions.php` 292, `ProPage.php` 20); «gateway» alternato a «gestore» nella stessa pagina (`PaymentsPage.php` 62, 168, 216; `ProIntegrations.php` 48): scegliere «gestore»; «database» (`PaymentsPage.php` 102-103, `ProWallet.php` 78, `SettingsPage.php` 135, `TribeImportPage.php` 16); «wp-admin» (`SettingsPage.php` 77, 101, 117): «bacheca di WordPress»; «token», «staging», «in produzione», «payout» (`PaymentsPage.php` 62, 103, 116, 216); «di default» (`SettingsPage.php` 63, 92; `TechPage.php` 38; `TextsPage.php` 105): «all'inizio», «parte spento», «originale».
- **Parole che il glossario esclude.** «iscritto / iscrizione» per la tessera (`PeoplePage.php` 104, 394, 396; `ResetPage.php` 21, 46; `WizardPage.php` 91; `MemberAccessChoice.php` 35); «non socio» per «ospite» (`PeoplePage.php` 513, `SettingsPage.php` 73); «gestori» per «segreteria» (`SettingsPage.php` 63); «liquidità» per «disponibilità reale» (`ReportsPage.php` 23, `ProPage.php` 29); «utile» per «avanzo» (`ProPage.php` 34); «quota» per una parte di un fondo o una percentuale (`ProActions.php` 354, `ResourcesPage.php` 280, `ResourcesReportPage.php` 50); «girare denaro» (`YearsPage.php` 45); «cancellato / cancellabile / cancellare» per disdire, annullare, eliminare (`PeoplePage.php` 730, `SettingsPage.php` 78-80, `ReceiptReplacePage.php` 60, `YearsPage.php` 93).
- **Lo stesso concetto con più nomi.** Fondo «estinto» / «liquidato» / «si salda» (`ProActions.php` 359; `WatchPage.php` 226, 241; `ResourcesPage.php` 280): proporre «chiuso» e «rimborsato». «Tipi di socio» per i livelli di quota (`ProWizard.php` 103, 253; `WizardPage.php` 116, 120) quando «Tipo» è già fondatore/ordinario/volontario: usare «livelli». «Import» / «importazione» (`WpAiPage.php` 24, 26, 51; `TribeImportPage.php` 16, 31; `TechPage.php` 49): usare «importazione». «Apri l'anno» / «Crea l'anno» (`YearsPage.php` 29-30 e 118-121). «categoria» / «voce» (`ProRegisters.php` 239, `RefundsPage.php` 152): usare «voce». «anagrafica» / «anagrafe» / «rubrica» (`PeoplePage.php` 259, `ResourcesPage.php` 337).
- **Virgolette dritte invece di «…».** `PaymentsPage.php` 62, 215 ("Stripe" o "PayPal"); `PeoplePage.php` 396; `ProWallet.php` 87; `SettingsPage.php` 73, 77, 117; `WpAiPage.php` 30.
- **Trattino lungo usato come inciso o come due punti.** `PollsPage.php` 76, 110; `PeoplePage.php` 499, 567; `ProIntegrations.php` 76; `ProRegisters.php` 306-309; `TechPage.php` 49-50; `RefundsPage.php` 111, 127; `WatchPage.php` 226. Regola: negli elenchi «voce: spiegazione»; negli incisi virgola o parentesi. (Restano i trattini dei segnaposto dei menu a tendina, «— scegli —».)
- **Messaggi di errore che non dicono cosa fare.** «Non autorizzato.» (`RefundsPage.php` 27, `RegistersActions.php` 27, `ResourcesActions.php` 30, `TechActions.php` 42, `PersonSearch.php` 42), «File non valido.», «Impossibile salvare l'icona.» (`ProActions.php` 246, 649).
- **Paragrafi-muro.** Descrizioni oltre le 80 parole con parentesi a catena: `PaymentsPage.php` 215-216 (circa 190 parole), `PeoplePage.php` 258, `TribeImportPage.php` 14-16, `WatchPage.php` 255, `MemberAccessChoice.php` 26, `ReceiptCheckPage.php` 83. Regola: una frase per idea, massimo 25 parole; dove il testo resta lungo, spezzarlo in elenco.

## Proposte

### MemberAccessChoice.php:23 (e righe elencate) · Media · bacheca, molte schermate
- Ora: «Come entrano i soci nell'area riservata?»
- Proposta: «Come entrano i soci nell'area soci?» Stessa sostituzione «area riservata» → «area soci» in: `PeoplePage.php` 237, 258, 356, 450, 479, 544, 558, 679, 682, 684; `PollsPage.php` 23; `RefundsPage.php` 37; `SettingsPage.php` 75 («Pagina area riservata» → «Pagina dell'area soci»), 77, 117.
- Perché: il glossario fissa «area soci»; oggi la stessa pagina ha due nomi anche nello stesso file.

### MemberAccessChoice.php:26 · Media · impostazioni e configurazione guidata
- Ora: «I soci entrano con email e password proprie, che scelgono alla prima entrata e restano nel plugin: non sono utenti del tuo sito, quindi non hanno una pagina di accesso di WordPress da attaccare, non compaiono nell'elenco utenti e non possono toccare niente fuori dall'area soci. Le password del sito restano solo di chi lo gestisce. Chi dimentica la password riceve per email un link che gli fa scegliere una nuova password, e chi preferisce può entrare con un link o un codice senza password.»
- Proposta: «I soci entrano con un'email e una password scelte al primo accesso, che restano nel plugin. Non sono utenti del tuo sito: non compaiono nell'elenco utenti, non possono toccare niente fuori dall'area soci e non usano la pagina di accesso di WordPress, che resta solo di chi gestisce il sito. Chi dimentica la password ne sceglie una nuova da un link ricevuto per email; chi preferisce entra con un link o un codice, senza password.»
- Perché: 80 parole in tre frasi, con «una pagina… da attaccare» da programmatore; la versione nuova dice le stesse cose in frasi brevi.

### MemberAccessChoice.php:35 · Bassa · impostazioni
- Ora: «…prezzi o sconti riservati agli iscritti…»
- Proposta: «…prezzi o sconti riservati ai soci…»
- Perché: «iscritto» vale solo per i corsi.

### MemberAccessChoice.php:38 · Bassa · impostazioni
- Ora: «Chi ha un potere nell'amministrazione (segreteria, presidente e vicepresidente, tesoriere, staff) ha sempre un utente WordPress, in entrambi i casi: l'utente nasce quando gli dai il ruolo.»
- Proposta: «Chi ha un incarico (segreteria, presidente e vicepresidente, tesoriere, staff) ha sempre un utente WordPress, in entrambi i casi: si crea quando gli dai il ruolo.»
- Perché: «ha un potere» e «l'utente nasce» suonano strani; più corto.

### MessagesPage.php:53 · Media · bacheca, Comunicazioni
- Ora: «Se i destinatari sono molti l'invio continua da solo in background.»
- Proposta: «Se i destinatari sono molti, l'invio continua da solo.»
- Perché: «in background» è gergo e non aggiunge nulla. Stessa correzione in `ProActions.php` 292 («…, gli altri continuano da soli in background.» → «…, gli altri partono da soli.») e `ProPage.php` 20 («…di un'attività, inviate in background.» → «…di un'attività.»).

### MessagesPage.php:58 · Bassa · bacheca, Comunicazioni
- Ora: «Gli SMS partono solo a chi ha un numero valido e ha dato il consenso agli SMS; massimo [N] caratteri.»
- Proposta: «Gli SMS arrivano solo a chi ha un numero valido e ha dato il consenso; massimo [N] caratteri.»
- Perché: «partono a» non è italiano corretto; «agli SMS» è ripetuto.

### MessagesPage.php:93 · Bassa · bacheca, anteprima dei destinatari
- Ora: «I crediti consumati sono quelli del fornitore.»
- Proposta: «Ogni SMS consuma crediti del tuo fornitore.»
- Perché: così com'è non si capisce di quali crediti si parla (nel plugin esistono anche i «crediti» dei soci).

### MoneyPage.php:43 · Bassa · bacheca, Cassa
- Ora: «Resta all'ente»
- Proposta: «Resta all'associazione»
- Perché: la stessa cifra in `ReportsPage.php` 129 si chiama «Resta all'associazione»; «associazione» inoltre si adatta da sola al tipo di ente.

### PaymentsPage.php:41 · Media · impostazioni, Pagamenti online
- Ora: «Ribalta la commissione su chi paga, in automatico»
- Proposta: «Fai pagare la commissione a chi paga»
- Perché: «ribaltare» è gergo contabile; «in automatico» è implicito.

### PaymentsPage.php:42 · Media · impostazioni, Pagamenti online
- Ora: «<strong>Attenzione:</strong> in Italia e nell'Unione europea è vietato far pagare un supplemento a chi paga con carta (e con alcuni bonifici/addebiti) se è un consumatore (direttiva PSD2, D.Lgs. 11/2010): la spunta è spenta di partenza e usarla è una scelta e una responsabilità dell'associazione. Se non sei sicuro lasciala spenta.»
- Proposta: «<strong>Attenzione:</strong> in Italia e nell'Unione europea è vietato chiedere un supplemento ai consumatori che pagano con carta, e con alcuni bonifici e addebiti (direttiva PSD2, D.Lgs. 11/2010). La spunta parte spenta: accenderla è una scelta e una responsabilità dell'associazione. Nel dubbio, lasciala spenta.»
- Perché: frase di 50 parole con tre parentesi e una barra; i fatti restano identici.

### PaymentsPage.php:62 · Media · impostazioni, Pagamenti online
- Ora: «Con Stripe o PayPal i soci pagano su una <strong>pagina ospitata dal gateway</strong> (i dati della carta non passano dal sito) e l'incasso entra da solo in prima nota sul conto "Stripe" o "PayPal". Prima di usarli in produzione verifica il funzionamento con le chiavi di prova.»
- Proposta: «Con Stripe o PayPal i soci pagano su una <strong>pagina di Stripe o di PayPal</strong> (i dati della carta non passano dal tuo sito) e l'incasso entra da solo in prima nota, sul conto «Stripe» o «PayPal». Prima di incassare davvero, fai una prova con le chiavi di prova.»
- Perché: «ospitata dal gateway» e «in produzione» sono gergo; virgolette dritte.

### PaymentsPage.php:74 · Media · impostazioni, Pagamenti online
- Ora: «<strong>Attenzione:</strong> con Klarna acceso il sito elenca a mano i metodi della pagina di pagamento (carta e Klarna), quindi gli altri metodi che hai attivato nel pannello di Stripe (ad esempio Link) non compaiono finché Klarna è acceso.Si offre solo per pagamenti in euro, …»
- Proposta: «<strong>Attenzione:</strong> con Klarna acceso la pagina di pagamento mostra solo carta e Klarna: gli altri metodi attivati nel pannello di Stripe (ad esempio Link) non compaiono. Klarna si offre solo per pagamenti in euro, …»
- Perché: manca lo spazio dopo il punto («acceso.Si»); «il sito elenca a mano i metodi» è una spiegazione da programmatore.

### PaymentsPage.php:87 · Bassa · impostazioni, Pagamenti online
- Ora: «Satispay avvisa questo indirizzo a pagamento concluso (lo indichiamo noi a ogni pagamento, non devi configurarlo). Il sito non crede all'avviso: rilegge lo stato del pagamento da Satispay con una richiesta firmata.»
- Proposta: «Satispay avvisa questo indirizzo a pagamento concluso (il sito glielo comunica da solo: non devi configurare nulla). Per sicurezza il sito non si fida dell'avviso e chiede a Satispay lo stato del pagamento.»
- Perché: toglie il «noi» del programma e la «richiesta firmata».

### PaymentsPage.php:102 · Media · impostazioni, Pagamenti online
- Ora: «Le chiavi segrete sono salvate <strong>cifrate</strong> nel database e non vengono mai mostrate né scritte nel registro azioni: si inseriscono qui e basta, senza toccare file. La cifratura è legata a questo sito: se copi il database su un altro sito (ad esempio lo staging) le chiavi non vi sono leggibili e vanno reinserite. Questo impedisce a un sito di prova di usare per errore le chiavi reali. Usa le chiavi di prova finché non hai verificato il funzionamento.»
- Proposta: «Le chiavi segrete si salvano <strong>cifrate</strong> e non vengono più mostrate, nemmeno nel registro azioni. La cifratura vale solo per questo sito: su una copia del sito (ad esempio un sito di prova) le chiavi non si leggono e vanno reinserite, così una copia non può usare per errore le chiavi reali. Usa le chiavi di prova finché non hai verificato che tutto funziona.»
- Perché: «database», «staging», «non vi sono leggibili»; più corto e senza perdere informazioni.

### PaymentsPage.php:116 · Media · impostazioni, Pagamenti online
- Ora: «Usa le chiavi già salvate (salva prima le impostazioni). Non muove denaro: Stripe legge il saldo, PayPal chiede un token di accesso, SumUp legge il profilo dell'account.»
- Proposta: «Usa le chiavi già salvate (salva prima le impostazioni). Non muove denaro: controlla solo che il gestore accetti le chiavi.»
- Perché: il dettaglio tecnico («token di accesso») non serve a chi prova; inoltre l'elenco dimentica Satispay, che ha il suo pulsante.

### PaymentsPage.php:137 · Bassa · bacheca, Pagamenti ricevuti
- Ora: «Questo pagamento non è in prima nota: il rimborso non scrive nulla in prima nota.»
- Proposta: «Questo pagamento non è in prima nota: il rimborso non vi registra nulla.» (riga 138: «L'incasso è già stato annullato in prima nota: il rimborso non vi registra nulla.»)
- Perché: «in prima nota» ripetuto due volte nella stessa frase.

### PaymentsPage.php:141 · Bassa · bacheca, Pagamenti ricevuti
- Ora: «Quanto restituire al socio (massimo {importo})? I soldi tornano sul suo metodo di pagamento e non si può annullare.»
- Proposta: «Quanto restituire al socio (massimo {importo})? I soldi tornano sul suo metodo di pagamento e il rimborso non si può annullare.»
- Perché: «non si può annullare» non ha soggetto (sembra riferito ai soldi). Una parola in più, indispensabile.

### PaymentsPage.php:168 · Media · bacheca, Pagamenti ricevuti
- Ora: «Interroga il gateway sui pagamenti non ancora confermati (lo fa già da solo ogni ora).»
- Proposta: «Chiede al gestore lo stato dei pagamenti non ancora confermati (succede già da solo ogni ora).»
- Perché: «interroga il gateway» è gergo; nella stessa pagina si dice «gestore».

### PaymentsPage.php:199 · Media · bacheca, Pagamenti ricevuti
- Ora: «Rimborsato» (pulsante; riga 204: «Controllato»)
- Proposta: «Segna come rimborsato» e «Segna come controllato»
- Perché: un participio su un pulsante sembra uno stato, non un'azione. Se si cambia, aggiornare il richiamo alla riga 216 («segna il pagamento con «Rimborsato»»).

### PaymentsPage.php:215 · Media · bacheca, Pagamenti ricevuti
- Ora: «I pagamenti riusciti sono già in Prima nota sul conto "Stripe" o "PayPal". Le commissioni del gateway si registrano da sole come spesa stimata (se lo hai scelto più sopra); il trasferimento sul conto corrente (payout) si registra a mano con un giroconto. Un pagamento Stripe o PayPal si restituisce al socio da qui con «Rimborsa» (tutto o in parte, da 0,50 €): vale per i pagamenti mai entrati in prima nota e per quelli il cui incasso è già stato annullato; i soldi tornano sul metodo del socio, il rimborso non si può annullare e non scrive nulla in prima nota. Il rimborso restituisce solo i soldi: iscrizioni, prenotazioni e crediti collegati a questo pagamento restano come sono; se vanno tolti, sistemali a mano. Se hai già restituito i soldi dal pannello di Stripe o PayPal, non rimborsare di nuovo da qui: segna il pagamento con «Rimborsato». Se l'incasso è ancora in prima nota, annullalo prima da lì. Con gli altri metodi (SumUp, Satispay, negozio) il rimborso si fa dal pannello del gestore. I bonifici arrivati sul conto si registrano a mano da «Nuovo incasso».» (righe 215-216, il link su «Prima nota» resta)
- Proposta: «I pagamenti riusciti sono già in Prima nota, sul conto «Stripe» o «PayPal». Le commissioni si registrano da sole come spesa stimata, se lo hai scelto più sopra; l'accredito sul conto corrente si registra a mano con un giroconto. Un pagamento Stripe o PayPal si restituisce da qui con «Rimborsa» (tutto o in parte, da 0,50 €), ma solo se non è in prima nota: se l'incasso c'è ancora, annullalo prima da lì. I soldi tornano sul metodo del socio; il rimborso non si può annullare e non registra nulla in prima nota. Iscrizioni, prenotazioni e crediti collegati restano come sono: se vanno tolti, sistemali a mano. Se hai già rimborsato dal pannello di Stripe o PayPal, non farlo di nuovo da qui: segna il pagamento con «Rimborsato». Con SumUp, Satispay e negozio il rimborso si fa dal pannello del gestore. I bonifici arrivati sul conto si registrano a mano da «Nuovo incasso».»
- Perché: un solo paragrafo di circa 190 parole, con la stessa regola detta due volte («mai entrati in prima nota… / Se l'incasso è ancora in prima nota…»), «gateway», «payout» e virgolette dritte. Meglio ancora se spezzato in un elenco a tre punti (commissioni e accrediti, rimborsi, bonifici).

### PeoplePage.php:78 · Bassa · bacheca, elenco soci (filtro)
- Ora: «Tessera scaduta / senza tessera»
- Proposta: «Tessera scaduta o assente»
- Perché: la barra al posto di «o».

### PeoplePage.php:86 · Bassa · bacheca, elenco soci
- Ora: «Sospendi soci scaduti da oltre [N] mesi ([N])»
- Proposta: «Sospendi i soci con tessera scaduta da oltre [N] mesi ([N])»
- Perché: a scadere è la tessera, non il socio; la conferma accanto lo dice già bene.

### PeoplePage.php:104 · Media · bacheca, elenco soci (colonna Stato)
- Ora: «Non iscritto»
- Proposta: «Senza tessera»
- Perché: il glossario riserva «iscritto» ai corsi; il filtro della stessa pagina dice «senza tessera».

### PeoplePage.php:258 · Media · bacheca, scheda del socio (Tutore)
- Ora: «Per un minorenne: i due genitori oppure un tutore legale, già registrati (con email): compaiono tutti nel libro soci, nelle ricevute e nei consensi. Uno solo «opera sul sito» e si prende la responsabilità verso l'altro genitore di tutto ciò che riguarda il minore (anche liberatorie e consensi). Chi opera vede la tessera, le prenotazioni e i pagamenti del minore dalla sua area riservata, paga per lui e riceve le sue email; il minore può non avere né email né accesso. Il collegamento resta anche dopo i 18 anni finché non lo togli qui. Serve la data di nascita.»
- Proposta: «Per un minorenne indica i due genitori oppure il tutore legale, già registrati con un'email: compaiono nel libro soci, nelle ricevute e nei consensi. Uno solo «opera sul sito»: risponde anche per l'altro genitore (liberatorie e consensi compresi), vede tessera, prenotazioni e pagamenti del minore nella propria area soci, paga per lui e riceve le sue email. Il minore può non avere né email né accesso. Il collegamento resta anche dopo i 18 anni, finché non lo togli qui. Serve la data di nascita.»
- Perché: due «due punti» in fila e frasi da 35-40 parole; stesso contenuto, più corto.

### PeoplePage.php:259 · Bassa · bacheca, scheda del socio
- Ora: «Genitore non ancora in anagrafica?»
- Proposta: «Il genitore non è ancora registrato?»
- Perché: «in anagrafica» è gergo d'ufficio; altrove si dice «registrati».

### PeoplePage.php:305 · Media · bacheca, scheda del socio (socio che è un ente)
- Ora: «È iscritto al RUNTS nella vostra stessa sezione?»
- Proposta: «È iscritto al RUNTS nella tua stessa sezione?»
- Perché: è il voi; la descrizione subito sotto dà già del tu («la tua associazione»).

### PeoplePage.php:308 · Bassa · bacheca, scheda dell'ospite
- Ora: «Volte già venuto»
- Proposta: «Partecipazioni precedenti»
- Perché: etichetta sgrammaticata e solo al maschile.

### PeoplePage.php:375 · Media · bacheca, scheda del socio (Tessera)
- Ora: «La tessera arriva al saldo.»
- Proposta: «La tessera si attiva a quota saldata.»
- Perché: «arriva al saldo» si legge in due modi (arriva quando si salda / raggiunge il saldo).

### PeoplePage.php:394 · Media · bacheca, scheda del socio (Tessera)
- Ora: «Segna iscritto» · «Togli iscrizione» (pulsanti) e, alla riga 396, «L'incasso di una "Quota associativa" iscrive in automatico. Qui puoi iscrivere a mano chi ha già pagato.»
- Proposta: «Attiva la tessera» · «Togli la tessera»; «Incassando la quota associativa la tessera si attiva da sola. Qui la attivi a mano per chi ha già pagato.»
- Perché: accanto c'è «Anno della tessera»: è la tessera che si attiva; «iscritto» è riservato ai corsi; virgolette dritte.

### PeoplePage.php:441 · Media · bacheca, scheda del socio (Accesso)
- Ora: «Elimino il suo utente WordPress? Da ora entrerà con il link via email.»
- Proposta: «Eliminare il suo utente WordPress? Da ora entrerà con un link o un codice ricevuto per email.»
- Perché: il programma parla in prima persona; la descrizione sopra parla di «link o codice».

### PeoplePage.php:459 · Media · bacheca, scheda del socio (Accesso)
- Ora: «Il socio non ha ancora un'email collegata: con questo link sceglie email e password e attiva il proprio accesso (vale [durata], se scade se ne genera un altro aprendo questa scheda).»
- Proposta: «Il socio non ha ancora un'email collegata: con questo link sceglie email e password e attiva il proprio accesso. Il link vale [durata]; se scade, riapri questa scheda per averne uno nuovo.»
- Perché: 35 parole con una parentesi che contiene l'istruzione più utile.

### PeoplePage.php:466 · Media · bacheca, scheda del socio (Accesso)
- Ora: «Il link vale [durata]: dopo, si genera un altro da questa scheda.»
- Proposta: «Il link vale [durata]: dopo, se ne genera un altro da questa scheda.»
- Perché: errore di grammatica (manca «ne»).

### PeoplePage.php:479 · Bassa · bacheca, scheda del socio (Accesso)
- Ora: «Scollegare l'email? Il socio perde l'accesso all'area riservata e si riparte senza email; lo storico resta.»
- Proposta: «Scollegare l'email? Il socio perde l'accesso all'area soci finché non lo riattiva con un nuovo link; lo storico resta.»
- Perché: «si riparte senza email» non dice cosa succede dopo.

### PeoplePage.php:513 · Bassa · bacheca, scheda dell'ospite
- Ora: «Ha raggiunto la soglia di partecipazioni per i non soci…»
- Proposta: «Ha raggiunto la soglia di partecipazioni per gli ospiti…»
- Perché: glossario: «ospite», non «non socio».

### PeoplePage.php:536 · Media · messaggio WhatsApp all'ospite
- Ora: «Ciao [nome], grazie per essere venuto/a[ da associazione]! Se ti fa piacere continuare a partecipare puoi iscriverti come socio: ti spiego come?»
- Proposta: «Ciao [nome], grazie di aver partecipato[ da associazione]. Se ti fa piacere continuare, puoi diventare socio: ti spiego come?»
- Perché: doppia forma con la barra («venuto/a») e punto esclamativo. Da controllare che «da [associazione]» regga dopo «partecipato» (oppure «alle attività di [associazione]»).

### PeoplePage.php:537 · Bassa · bacheca, scheda dell'ospite
- Ora: «Scrivigli su WhatsApp»
- Proposta: «Scrivi su WhatsApp»
- Perché: «-gli» vale solo per il maschile; più corto.

### PeoplePage.php:576 · Media · bacheca, scheda del minore (Privacy)
- Ora: «<strong>In attesa della conferma del genitore</strong>[ (nome)]: Il genitore non ha ancora confermato: il collegamento si crea quando conferma; regolamento, privacy e immagini non sono ancora attribuiti a lui.»
- Proposta: «<strong>In attesa della conferma del genitore</strong>[ (nome)]: il collegamento si crea quando conferma; fino ad allora regolamento, privacy e immagini non risultano accettati da lui.»
- Perché: ripete due volte la stessa cosa, con maiuscola dopo i due punti e due «due punti» in fila.

### PeoplePage.php:684 · Media · bacheca, scheda del socio (Tesoriere)
- Ora: «Con il permesso, nell'area riservata compare la pagina «Area tesoriere»: incassa, scatta lo scontrino e registra la spesa. Vede solo gli incassi e le spese che ha registrato personalmente e non i saldi dei conti.»
- Proposta: «Nell'area soci gli compare la pagina «Area tesoriere», da cui incassa, fotografa lo scontrino e registra la spesa. Vede solo gli incassi e le spese che ha registrato lui, non i saldi dei conti.»
- Perché: dopo i due punti il soggetto cambia senza dirlo (sembra che a incassare sia la pagina); «scatta lo scontrino» non si dice.

### PeoplePage.php:694 · Media · bacheca, scheda del socio (Staff)
- Ora: «Verifica gli accessi a tutti gli eventi»
- Proposta: «Registra gli ingressi a tutti gli eventi»
- Perché: «accesso» nel plugin è l'entrata nell'area soci; la descrizione sotto dice «registrare gli ingressi».

### PeoplePage.php:699 · Media · bacheca, scheda del socio (Staff)
- Ora: «Lo staff dell'ente può registrare gli ingressi di tutti gli eventi. Può anche essere staff di un singolo evento (scheda dell'attività): solo lì si può abilitare l'incasso del contributo d'ingresso sul posto. Non gestisce soldi né comunicazioni.»
- Proposta: «Lo staff dell'ente registra gli ingressi a tutti gli eventi; non gestisce soldi né comunicazioni. Si può anche nominare lo staff di un singolo evento, dalla scheda dell'attività: solo lì si può abilitare l'incasso del contributo all'ingresso.»
- Perché: «Non gestisce soldi» subito dopo «incasso sul posto» sembra una contraddizione; riordinato si capisce.

### PeoplePage.php:730 · Media · bacheca, scheda del socio (Attività e pagamenti)
- Ora: «Cancellato (ultimo mese dovuto: [mese])»
- Proposta: «Disdetto (ultimo mese dovuto: [mese])»
- Perché: glossario: per un corso si dice «disdire», mai «cancellare».

### PollsPage.php:23 · Bassa · bacheca, Votazioni e sondaggi
- Ora: «La segreteria crea una votazione o un sondaggio per tutti i soci in regola o per gli iscritti di un'attività. I soci votano dall'area riservata, una volta sola. I genitori e i tutori che non sono soci non votano.»
- Proposta: «Crea una votazione o un sondaggio per tutti i soci in regola o per gli iscritti a un'attività. I soci votano una volta sola, dall'area soci. Genitori e tutori che non sono soci non votano.»
- Perché: chi legge è la segreteria: si dà del tu; «iscritti di» → «iscritti a».

### PollsPage.php:76 · Bassa · bacheca, Votazioni
- Ora: «— al massimo [campo] (0 = senza limite)»
- Proposta: «Al massimo [campo] risposte (0 = senza limite)»
- Perché: trattino usato come legame; manca cosa si conta. Stesso difetto alla riga 110: «— più votata» → «(più votata)».

### PrivateFolderPanel.php:38 · Bassa · bacheca, Copia di sicurezza
- Ora: «…la nuova cartella non è ancora in uso: ci passa il sito quando tutti i file della cartella predefinita sono stati copiati con «Sposta i file».»
- Proposta: «…la nuova cartella non è ancora in uso: il sito ci passa quando hai copiato tutti i file con «Sposta i file».»
- Perché: ordine delle parole innaturale e passivo inutile.

### PrivateFolderPanel.php:54 · Media · bacheca, Copia di sicurezza
- Ora: «Il controllo crea tre piccoli file di prova, senza dati, fatti come quelli veri (e un quarto in una cartella pubblica, per capire se la prova può funzionare), prova a scaricarli dall'indirizzo web del sito e li cancella subito. Si ripete da solo circa una volta a settimana.»
- Proposta: «Il controllo crea alcuni piccoli file di prova, senza dati, tenta di scaricarli da internet e li cancella subito. Si ripete da solo circa una volta a settimana.»
- Perché: il conteggio dei file e il «quarto» sono dettagli di funzionamento che il volontario non usa; «prova… prova… prova» ripetuto.

### PrivateFolderPanel.php:94 · Media · bacheca, Copia di sicurezza
- Ora: «Nella cartella predefinita ci sono ancora {n} file da portare nella nuova cartella. Si procede a lotti di {lotto} file: prima ogni file viene copiato e controllato con la sua impronta, senza toccare l'originale; quando sono stati copiati tutti, la nuova cartella entra in uso e i file vecchi vengono cancellati uno alla volta, solo se la copia è identica. Se ne restano, premi di nuovo.»
- Proposta: «Nella cartella predefinita restano {n} file da spostare, {lotto} alla volta. Ogni file viene prima copiato e controllato, senza toccare l'originale; quando sono stati copiati tutti, la nuova cartella entra in uso e gli originali vengono cancellati, solo se la copia è identica. Se ne restano, premi di nuovo.»
- Perché: 70 parole; «lotti» e «impronta» si possono evitare senza perdere la garanzia (copia identica).

### ProActions.php:157 · Media · bacheca, messaggio di errore
- Ora: «Questa edizione ha un'unica quota associativa.»
- Proposta: «Qui la quota associativa è una sola: i livelli di socio sono in Associazione Semplice Pro.»
- Perché: «questa edizione» non dice all'utente perché il salvataggio è stato rifiutato né come averlo.

### ProActions.php:246 · Media · bacheca, Testi (errore di importazione)
- Ora: «File non valido.»
- Proposta: «File non valido: usa il file esportato da questa pagina (CSV o Excel).»
- Perché: l'errore non dice come rimediare.

### ProActions.php:248 · Bassa · bacheca, Testi (esito dell'importazione)
- Ora: «Importazione completata: [N] testi impostati, [N] ripristinati, [N] aggiunte a mano…»
- Proposta: «Importazione completata: [N] testi impostati, [N] ripristinati, [N] sostituzioni aggiunte…»
- Perché: «aggiunte a mano» non ha un sostantivo e non si capisce che cosa è stato aggiunto.

### ProActions.php:297 · Media · bacheca, Comunicazioni (errore)
- Ora: «Invio della prova non riuscito: controlla la posta in uscita del sito.»
- Proposta: «Invio della prova non riuscito: controlla «Posta in uscita» in Strumenti → Integrazioni.»
- Perché: dice dove andare. Da confermare che il riquadro «Posta in uscita» (`TechPage.php` 15) sia mostrato nella pagina Integrazioni.

### ProActions.php:354 · Media · bacheca, Conti e fondi (esito)
- Ora: «Quota liberata: è tornata nella disponibilità reale.»
- Proposta: «Somma liberata: è tornata nella disponibilità reale.»
- Perché: glossario: «quota» è solo la quota associativa; il messaggio gemello alla riga 345 dice già «Somma accantonata».

### ProActions.php:359 · Media · bacheca, Conti e fondi (esito)
- Ora: «Rimborso registrato in prima nota e fondo estinto.» / «Fondo a zero: estinto.»
- Proposta: «Rimborso registrato in prima nota e fondo chiuso.» / «Fondo a zero: chiuso.»
- Perché: «estinto» è burocratese e lo stesso stato altrove si chiama «liquidato» (`WatchPage.php` 226, 241): scegliere una parola sola, «chiuso».

### ProActions.php:616 · Media · bacheca, App e notifiche (errore)
- Ora: «Questo server non ha le funzioni di cifratura necessarie per le notifiche (estensione openssl di PHP).»
- Proposta: «Le notifiche non si possono attivare: sul server manca l'estensione openssl di PHP. Chiedi a chi gestisce il sito di attivarla.»
- Perché: l'errore descrive il problema ma non dice a chi rivolgersi. Poche parole in più, indispensabili.

### ProActions.php:649 · Bassa · bacheca, App e notifiche (errore)
- Ora: «Impossibile salvare l'icona.»
- Proposta: «L'icona non è stata salvata: riprova.»
- Perché: tono da macchina, nessuna indicazione.

### ProActions.php:665 · Bassa · bacheca, App e notifiche (esito)
- Ora: «Chiavi rigenerate: i dispositivi devono riattivare le notifiche.»
- Proposta: «Chiavi rigenerate: i soci devono riattivare le notifiche sul telefono.»
- Perché: a riattivare sono le persone, non i dispositivi.

### ProIntegrations.php:43 · Media · bacheca, Integrazioni (WooCommerce)
- Ora: «I soci pagano quote e contributi dal carrello e dal checkout del negozio. Ogni quota (per livello di socio) e ogni corso o evento si collega a un prodotto semplice, preferibilmente virtuale: nel carrello il prezzo è quello calcolato da Associazione Semplice (quota del livello, sconto familiare, mensilità dovute) e quando l'ordine risulta pagato l'incasso entra in prima nota con la sua ricevuta. Gli ordini senza voci Associazione Semplice non vengono toccati.» (righe 43-45)
- Proposta: «I soci pagano quote e contributi dal carrello del negozio. Collega ogni quota (una per livello di socio) e ogni corso o evento a un prodotto semplice, meglio se virtuale. Nel carrello l'importo lo calcola Associazione Semplice (quota del livello, sconto familiare, mensilità dovute); quando l'ordine è pagato, l'incasso entra in prima nota con la sua ricevuta. Gli altri ordini del negozio non vengono toccati.»
- Perché: seconda frase di 45 parole; «prezzo» è escluso dal glossario per quote e contributi.

### ProIntegrations.php:48 · Media · bacheca, Integrazioni (WooCommerce)
- Ora: «Al posto di Stripe e PayPal (il gateway scelto cambia).»
- Proposta: «Sostituisce il metodo scelto in Pagamenti online.»
- Perché: «il gateway scelto cambia» è gergo e non dice dove; più corto.

### ProPage.php:24 · Bassa · bacheca, pagina «Pro»
- Ora: «Ogni frase che i soci vedono, nelle email e nei PDF si può cambiare; …»
- Proposta: «Ogni frase che i soci vedono sul sito, nelle email e nei PDF si può cambiare; …»
- Perché: la virgola spezza la frase e manca il primo termine dell'elenco.

### ProPage.php:29 · Media · bacheca, pagina «Pro»
- Ora: «Saldi e liquidità reale, risultato per attività e anno delle attività.»
- Proposta: «Saldi e disponibilità reale, risultato per attività e per anno delle attività.»
- Perché: glossario: «disponibilità reale», non «liquidità». Stessa correzione per la scheda «Conti e liquidità» in `ReportsPage.php` 23 → «Conti e disponibilità».

### ProPage.php:34 · Media · bacheca, pagina «Pro»
- Ora: «Bilancio provvisorio sempre aggiornato al mese concluso, avvisi su spese non coperte e utile eccessivo, storico mensile, spese documentate attribuite ai fondi dei volontari e inizio dell'anno fiscale a scelta.»
- Proposta: «Bilancio provvisorio aggiornato al mese concluso, avvisi su spese non coperte e avanzo eccessivo, storico mensile, spese documentate attribuite ai fondi dei volontari, inizio dell'anno fiscale a scelta.»
- Perché: per un'associazione si dice «avanzo», non «utile» (glossario).

### ProPage.php:53 · Bassa · bacheca, pagina «Pro»
- Ora: «…aggiunge le funzioni qui sotto, in due gruppi di funzioni, a seconda del piano scelto.»
- Proposta: «…aggiunge le funzioni qui sotto, divise in due gruppi a seconda del piano scelto.»
- Perché: «funzioni» ripetuto.

### ProRegisters.php:45 · Bassa · bacheca, Assicurazioni
- Ora: «…premio e massimale: viene segnalata in Bacheca se la responsabilità civile è scoperta o in scadenza entro [N] giorni.»
- Proposta: «…premio e massimale: la Bacheca ti avvisa se la responsabilità civile è scoperta o scade entro [N] giorni.»
- Perché: passivo senza soggetto chiaro.

### ProRegisters.php:144 · Media · bacheca, Registro presenze
- Ora: «Le lezioni mai registrate senza nessuna spunta restano da compilare.»
- Proposta: «Una lezione mai registrata e lasciata senza spunte resta da compilare.»
- Perché: «mai registrate senza nessuna spunta» si legge come doppia negazione.

### ProRegisters.php:306 · Bassa · bacheca, Opzioni contabili
- Ora: «Soci e quote — quota associativa, livelli di socio, sconto del nucleo familiare.» (e righe 307-309)
- Proposta: «Soci e quote: quota associativa, livelli di socio, sconto del nucleo familiare.» (due punti al posto del trattino anche nelle tre righe seguenti)
- Perché: trattino lungo usato come due punti. Stesso difetto in `TechPage.php` 49-50 e `ProIntegrations.php` 76.

### ProWallet.php:28 · Bassa · impostazioni, Tessera, QR e Wallet
- Ora: «Richiede le credenziali di Apple e/o Google, da inserire qui sotto dopo averlo attivato.»
- Proposta: «Servono le credenziali di Apple, di Google o di entrambi: le inserisci qui sotto dopo averlo attivato.»
- Perché: «e/o» è burocratese.

### ProWallet.php:78 · Bassa · impostazioni, Tessera, QR e Wallet
- Ora: «La chiave privata viene cifrata nel database e non si legge più.»
- Proposta: «La chiave privata si salva cifrata e non viene più mostrata.»
- Perché: «database»; «non si legge più» sembra un guasto.

### ProWizard.php:103 · Media · configurazione guidata
- Ora: «Altri tipi di socio» (etichetta) · «Una riga per tipo: «Nome; quota».» · riga 253: «[N] tipi di socio aggiunti.»
- Proposta: «Altri livelli di socio» · «Una riga per livello: «Nome; quota».» · «[N] livelli di socio aggiunti.» Stessa correzione in `WizardPage.php` 116 («Tipi di socio diversi e sconti…» → «Livelli di socio diversi e sconti…») e 120 («Più dettagli: altri tipi di socio e sconti» → «Più dettagli: altri livelli di socio e sconti»); riga 103 precedente «Tipi già presenti» → «Livelli già presenti».
- Perché: nel plugin «Tipo» è fondatore/ordinario/volontario/ospite, mentre questi sono i «livelli» con la loro quota (la stessa descrizione finisce con «Altri livelli e quote si gestiscono in…»).

### ProWizard.php:121 · Bassa · configurazione guidata
- Ora: «WooCommerce non è stato rilevato su questo sito: installalo e attivalo per usare questa opzione.»
- Proposta: «WooCommerce non è attivo su questo sito: installalo e attivalo per usare questa opzione.»
- Perché: «non è stato rilevato» è lingua da macchina; l'errore alla riga 226 dice già «non è attivo».

### ReceiptCheckPage.php:62 · Bassa · bacheca, Verifica delle ricevute
- Ora: «…il PDF si disegna dai dati già salvati…» (anche righe 82 e 152)
- Proposta: «…il PDF si crea dai dati già salvati…»
- Perché: un PDF «si crea», non «si disegna»: è una parola da programmatore.

### ReceiptCheckPage.php:83 · Media · bacheca, Verifica delle ricevute
- Ora: «Le ricevute emesse prima di questo aggiornamento si fissano in automatico dopo l'aggiornamento: per il loro PDF valgono però i testi di oggi, cioè i tuoi testi personalizzati, le parole scelte per il tipo di ente (socio/iscritto, associazione/comitato) e la grafica del plugin di quel momento. Le ricevute emesse da ora in poi restano identiche per sempre.»
- Proposta: «Le ricevute più vecchie, emesse prima che i PDF si salvassero, vengono fissate in automatico: il loro PDF usa i testi e la grafica del giorno in cui viene fissato (testi personalizzati, tipo di ente e termini scelti). Le ricevute emesse da allora in poi restano identiche per sempre.»
- Perché: «questo aggiornamento» e «da ora in poi» non hanno più un riferimento per chi legge la pagina mesi dopo; «i testi di oggi… di quel momento» si contraddice.

### ReceiptCheckPage.php:140 · Bassa · bacheca, Verifica delle ricevute
- Ora: «Aprire o scaricare la ricevuta una volta salva i suoi dati di quel momento e ne fissa il PDF; le ricevute già annullate in questo stato non si possono più aprire.»
- Proposta: «Basta aprire o scaricare la ricevuta una volta: i suoi dati di quel momento si salvano e il PDF viene fissato. Le ricevute già annullate che sono in questo stato non si possono più aprire.»
- Perché: «una volta salva» si legge male (sembra «una volta salvata»).

### ReceiptCheckPage.php:145 · Bassa · bacheca, Verifica delle ricevute (conferma)
- Ora: «Fissare queste ricevute con i dati di oggi? I dati sono quelli di oggi, non quelli dell'emissione. Dopo il PDF non cambia più e l'operazione viene scritta nel registro azioni.»
- Proposta: «Fissare queste ricevute con i dati di oggi, non con quelli dell'emissione? Dopo, il PDF non cambia più; l'operazione viene scritta nel registro azioni.»
- Perché: «i dati di oggi» detto due volte; senza virgola «Dopo il PDF» si legge male.

### ReceiptCheckPage.php:152 · Bassa · bacheca, Verifica delle ricevute
- Ora: «Fissa i PDF ancora da fissare»
- Proposta: «Fissa i PDF in sospeso»
- Perché: ripetizione nel titolo.

### ReceiptCheckPage.php:168 · Media · bacheca, Verifica delle ricevute (conferma)
- Ora: «Rifare questo PDF dai dati salvati all'emissione? Il nuovo file si salva cifrato; un file diverso resta messo da parte e l'operazione viene scritta nel registro azioni.»
- Proposta: «Rifare questo PDF dai dati salvati all'emissione? Il nuovo file si salva cifrato; quello attuale, se è diverso, viene messo da parte. L'operazione viene scritta nel registro azioni.»
- Perché: «un file diverso resta messo da parte» non fa capire di quale file si parla.

### ReceiptReplacePage.php:29 · Alta · bacheca, Ricevuta sostitutiva
- Ora: «…: una ricevuta annullata vale per intero, anche per le voci che non sono state annullate. Il suo numero resta suo e non torna libero; la ricevuta resta consultabile, con la scritta «RICEVUTA ANNULLATA».»
- Proposta: «…: l'annullamento riguarda tutta la ricevuta, anche le voci che non sono state annullate. Il numero non torna libero e la ricevuta resta consultabile, con la scritta «RICEVUTA ANNULLATA».»
- Perché: «una ricevuta annullata vale per intero» si legge al contrario (una ricevuta annullata che «vale»).

### ReceiptReplacePage.php:60 · Media · bacheca, Ricevuta sostitutiva (conferma)
- Ora: «Emettere la ricevuta sostitutiva? Prende un numero nuovo e non si può cancellare. Se serve, annulli l'incasso e la ricevuta risulta annullata.»
- Proposta: «Emettere la ricevuta sostitutiva? Prende un numero nuovo e non si può eliminare. Per toglierle valore dovrai annullare l'incasso: la ricevuta risulterà annullata.»
- Perché: «Se serve» non dice a che cosa; glossario: «eliminare», non «cancellare».

### RefundsPage.php:27 (e righe elencate) · Media · bacheca, pagina di errore
- Ora: «Non autorizzato.»
- Proposta: «Non hai il permesso per questa operazione. Se ti serve, chiedilo a un amministratore.» Stessa frase in `RegistersActions.php` 27, `ResourcesActions.php` 30, `TechActions.php` 42 (`PersonSearch.php` 42 è una risposta interna della ricerca: basta «Non hai il permesso.»).
- Perché: messaggio freddo che non dice cosa fare.

### RefundsPage.php:37 · Alta · bacheca, Rimborsi ai volontari
- Ora: «I volontari chiedono il rimborso dall'area riservata (o la segreteria lo registra per loro). Chi approva e paga lo decide qui: il pagamento registra l'uscita in prima nota e la collega alla richiesta. Per le spese documentate serve lo scontrino o la fattura; l'autocertificazione vale fino a 10 € al giorno e 150 € al mese per volontario, solo se l'assemblea ha deliberato i tipi di spesa.»
- Proposta: «I volontari chiedono il rimborso dall'area soci, oppure lo registri tu per loro. Qui approvi e paghi: il pagamento registra la spesa in prima nota e la collega alla richiesta. Per le spese documentate serve lo scontrino o la fattura; l'autocertificazione vale fino a 10 € al giorno e 150 € al mese per volontario, solo se l'assemblea ha deliberato i tipi di spesa.»
- Perché: «Chi approva e paga lo decide qui» si legge in due modi (qui si sceglie chi approva / qui chi approva decide). I limiti di legge restano identici.

### RegistersPage.php:31 · Media · bacheca, Libro soci
- Ora: «Il libro soci è dell'area «Anagrafica soci», che non hai. Gli altri registri sono nelle schede qui sopra.»
- Proposta: «Per il libro soci serve il permesso «Anagrafica soci», che non hai. Gli altri registri sono nelle schede qui sopra.»
- Perché: «è dell'area… che non hai» non fa capire che si parla di permessi.

### ReportsPage.php:79 · Bassa · bacheca, Report
- Ora: «Attenzione per il commercialista: nel rendiconto per cassa (Mod. D)…» (anche riga 111)
- Proposta: «Nota per il commercialista: nel rendiconto per cassa (Mod. D)…»
- Perché: «Attenzione per» non è italiano corrente.

### ResetPage.php:20 · Bassa · impostazioni, Azzeramento dati
- Ora: «[N] attività ed eventi, con prenotazioni, iscrizioni e presenze» (riga 46: «le attività e gli eventi con prenotazioni…»)
- Proposta: «[N] corsi ed eventi, con prenotazioni, iscrizioni e presenze» (riga 46: «i corsi e gli eventi con prenotazioni…»)
- Perché: gli eventi sono già attività: così sembrano due cose diverse.

### ResetPage.php:21 · Media · impostazioni, Azzeramento dati
- Ora: «[N] iscrizioni all'anno» (riga 46: «le iscrizioni all'anno»)
- Proposta: «[N] tessere annuali» (riga 46: «le tessere annuali»)
- Perché: «iscrizioni all'anno» non si capisce (sembra una media annua); sono le tessere dei soci.

### ResetPage.php:37 · Bassa · impostazioni, Azzeramento dati
- Ora: «…pagine create dalla procedura…» (anche riga 48: «l'elenco delle pagine create dalla procedura»)
- Proposta: «…pagine create dalla configurazione guidata…»
- Perché: «la procedura» non ha un nome; ovunque si chiama «configurazione guidata».

### ResourcesActions.php:126 · Media · bacheca, Risorse (esito)
- Ora: «Gli eventuali incassi restano in prima nota: l'eventuale rimborso è a mano.» (anche riga 129)
- Proposta: «Gli incassi già registrati restano in prima nota: se devi rimborsare, fallo a mano.»
- Perché: «eventuali… eventuale» è burocratese e non dice chi fa che cosa.

### ResourcesPage.php:81 · Media · bacheca, scheda dell'attività (Risorsa occupata)
- Ora: «Scegli quale risorsa occupa (campo, sala…), anche se ce n'è una sola: così i calendari si incrociano e la risorsa non risulta libera quando c'è già un corso o un evento. Se si tiene fuori sede, scegli «Nessuna risorsa». Allestimenti compresi.» (il link su «risorsa» resta)
- Proposta: «Scegli la risorsa che l'attività occupa (campo, sala…), anche se ce n'è una sola: così non risulta libera quando c'è un corso o un evento, allestimenti compresi. Se l'attività è fuori sede, scegli «Nessuna risorsa».»
- Perché: manca il soggetto («occupa» chi?) e «Allestimenti compresi.» resta appeso in fondo.

### ResourcesPage.php:82 · Media · bacheca, scheda dell'attività (Risorsa occupata)
- Ora: «Sposta sulla risorsa di prima ciò che si sovrappone» · riga 83: «Se cambi risorsa e quella nuova è già occupata negli stessi orari, il cambio non viene applicato e vedi cosa si sovrappone. Spuntando questa casella, le prenotazioni e le altre attività che si sovrappongono passano alla risorsa che si libera (le attività per intero); se anche lì non entrano, non si sposta niente.»
- Proposta: «Sposta sulla risorsa che si libera ciò che si sovrappone» · «Se la nuova risorsa è già occupata negli stessi orari, il cambio non avviene e vedi che cosa si sovrappone. Con questa spunta, prenotazioni e attività che si sovrappongono passano alla risorsa che si libera (le attività per intero); se non ci stanno nemmeno lì, non si sposta niente.»
- Perché: «la risorsa di prima» e «la risorsa che si libera» sono la stessa cosa detta in due modi; frasi accorciate.

### ResourcesPage.php:119 · Media · bacheca, Risorse
- Ora: «Il titolare non ha più la tessera valida: rinnovala oppure rilascia la risorsa.»
- Proposta: «Chi ha la risorsa non ha più la tessera valida: rinnova la tessera oppure rilascia la risorsa.»
- Perché: «il titolare» non è mai stato nominato (per i volontari «il titolare» è altro).

### ResourcesPage.php:192 · Bassa · bacheca, scheda della risorsa
- Ora: «La segreteria può registrare anche nel passato e oltre il limite di anticipo.»
- Proposta: «Da qui puoi prenotare anche date passate e oltre il limite di anticipo.»
- Perché: chi legge è la segreteria: si dà del tu.

### ResourcesPage.php:256 · Media · bacheca, scheda della risorsa (conferme)
- Ora: «Confermi?» (conferma unica per «Rilascia», «Togli blocco» e «Annulla») · riga 257: «Annullo questa prenotazione e tutte le successive della serie. Confermi?» · riga 346: «Con prenotazioni future la risorsa viene solo disattivata. Confermi?»
- Proposta: «Rilasciare la risorsa?» / «Togliere il blocco?» / «Annullare la prenotazione?» · «Annullare questa prenotazione e tutte le successive della serie?» · «Eliminare la risorsa? Se ha prenotazioni future viene solo disattivata.»
- Perché: «Confermi?» da solo non dice che cosa si sta confermando; alla 257 il programma parla in prima persona. Alla 256 servono tre testi al posto di uno.

### ResourcesPage.php:280 · Media · bacheca, scheda della risorsa (Fondo)
- Ora: «Utile per le risorse date in gestione a terzi (la quota è del gestore) o i cui incassi servono a pagare altro. Una parte di ogni pagamento ricevuto (anche in crediti) va nel fondo scelto: l'incasso resta in cassa o sul conto usato, la quota è accantonata e si sottrae dalla disponibilità reale finché il fondo non si salda o non si libera dalla pagina <em>Conti e fondi</em>. Annullando un incasso sparisce anche la sua quota.»
- Proposta: «Utile per le risorse date in gestione a terzi (la parte accantonata spetta al gestore) o i cui incassi servono a pagare altro. Una parte di ogni pagamento ricevuto, anche in crediti, va nel fondo scelto: l'incasso resta sul conto usato, ma quella parte è accantonata e non conta nella disponibilità reale finché il fondo non viene rimborsato o liberato da <em>Conti e fondi</em>. Se annulli un incasso, si annulla anche la sua parte.»
- Perché: «quota» qui non è la quota associativa (glossario); «in cassa o sul conto usato» confonde cassa e conto; «si salda» è un terzo nome per la chiusura del fondo.

### ResourcesPage.php:331 · Media · bacheca, scheda della risorsa (Pagamento)
- Ora: «Non ci sono tolleranze di giorni. La segreteria registra sempre le prenotazioni e gli incassi a mano.»
- Proposta: «Non ci sono giorni di tolleranza. La segreteria può sempre registrare prenotazioni e incassi a mano.»
- Perché: «registra sempre… a mano» sembra un obbligo (come se l'online non registrasse nulla), mentre è una possibilità.

### ResourcesPage.php:337 · Bassa · bacheca, scheda della risorsa (Ospiti)
- Ora: «Gli ospiti sono solo un nome: non entrano in anagrafe.»
- Proposta: «Gli ospiti sono solo un nome: non vengono registrati tra le persone.»
- Perché: «anagrafe» è l'ufficio del Comune; nel plugin l'elenco si chiama «Rubrica soci».

### ResourcesReportPage.php:50 · Bassa · bacheca, Occupazione delle risorse
- Ora: «L'occupazione è la quota di ore disponibili (tra apertura e chiusura) prenotate dai soci o occupate da corsi ed eventi; …»
- Proposta: «L'occupazione è la percentuale di ore disponibili (tra apertura e chiusura) prenotate dai soci o occupate da corsi ed eventi; …»
- Perché: «quota» è riservata alla quota associativa; la colonna è in percentuale.

### RuntsPage.php:46 · Bassa · bacheca, Dati per il RUNTS
- Ora: «…, ne servono almeno [N] persone oppure [N] enti.» (anche riga 120: «(ne servono almeno [N] persone oppure [N] enti)»)
- Proposta: «…, servono almeno [N] persone oppure [N] enti.»
- Perché: «ne» è di troppo quando il nome segue.

### RuntsPage.php:94 · Bassa · bacheca, Dati per il RUNTS
- Ora: «Il programma non tiene il registro dei volontari: scrivi tu il numero in…» (riga 99: «Il programma non li conta ancora: scrivi tu il numero nel portale del RUNTS.»)
- Proposta: «Qui non c'è il registro dei volontari: scrivi tu il numero in…» («Qui non vengono ancora contati: scrivi tu il numero nel portale del RUNTS.»)
- Perché: nella stessa pagina la voce è «noi» («Contiamo…», «Per quanto ci risulta…»): «Il programma» in terza persona stona.

### SettingsPage.php:63 · Media · impostazioni, Soci e quote
- Ora: «Tutte le funzioni facoltative sono spente di default: accendi solo quelle che ti servono, ciò che è spento resta invisibile a soci e gestori.»
- Proposta: «Le funzioni facoltative partono spente: accendi solo quelle che ti servono. Ciò che è spento non lo vedono né i soci né la segreteria.»
- Perché: «di default» è gergo; «gestori» è escluso dal glossario (si dice «segreteria»).

### SettingsPage.php:71 · Media · impostazioni, Soci e quote
- Ora: «Spento (consigliato): eliminando il plugin soci, prima nota, allegati e impostazioni restano e tornano disponibili se lo reinstalli. Acceso: vengono cancellati in modo definitivo, ruoli compresi; fai prima una copia di sicurezza.»
- Proposta: «Spento (consigliato): se elimini il plugin, soci, prima nota, allegati e impostazioni restano e li ritrovi quando lo reinstalli. Acceso: vengono cancellati per sempre, ruoli compresi; fai prima una copia di sicurezza.»
- Perché: senza virgola si legge «eliminando il plugin soci».

### SettingsPage.php:73 · Alta · impostazioni, Soci e quote
- Ora: «Dopo quante partecipazioni a eventi e corsi un non socio viene segnalato come "da invitare a iscriversi" (di solito 1 o 2: oltre, anche per ragioni assicurative, dovrebbe iscriversi). <strong>Non blocca nulla</strong>: evidenzia chi gestisce gli ospiti negli elenchi, nella scheda e all'ingresso. Le partecipazioni di chi si registra più volte (stesso cellulare, email o nome) si sommano. 0 = nessuna segnalazione.»
- Proposta: «Dopo quante partecipazioni a corsi ed eventi un ospite viene segnalato come «da invitare a iscriversi». Di solito 1 o 2: oltre, anche per ragioni assicurative, dovrebbe iscriversi. <strong>Non blocca nulla</strong>: l'ospite viene evidenziato negli elenchi, nella scheda e all'ingresso. Le partecipazioni di chi si registra più volte (stesso cellulare, email o nome) si sommano. 0 = nessuna segnalazione.»
- Perché: «evidenzia chi gestisce gli ospiti» dice il contrario di ciò che succede (è l'ospite a essere evidenziato); «non socio» e virgolette dritte.

### SettingsPage.php:77 · Media · impostazioni, Soci e quote
- Ora: «Chi ha solo il ruolo "Socio" viene indirizzato qui al posto di wp-admin.»
- Proposta: «Chi ha solo il ruolo «Socio» viene portato qui invece che nella bacheca di WordPress.»
- Perché: «wp-admin» è gergo tecnico; virgolette dritte. Stessa sostituzione alla riga 101 («Soci e volontari usano il sito, non wp-admin.» → «Soci e volontari usano le pagine del sito, non la bacheca di WordPress.»).

### SettingsPage.php:78 · Media · impostazioni, Soci e quote
- Ora: «Eventi: cancellazioni» · riga 80: «Vale per gli eventi creati come «cancellabili» senza un termine proprio.»
- Proposta: «Eventi: annullamento delle prenotazioni» · «Vale per gli eventi creati come «annullabili» senza un termine proprio.»
- Perché: glossario: «annullare», mai «cancellare / cancellabile»; i campi accanto dicono già «Termine predefinito per annullare». La parola «cancellabili» cita un'opzione della scheda dell'attività (fuori da questo lotto): vanno cambiate insieme.

### SettingsPage.php:90 · Media · impostazioni, Domanda di adesione online
- Ora: «…accendilo solo se è un cellulare su cui usate WhatsApp.»
- Proposta: «…accendilo solo se è un cellulare su cui la segreteria usa WhatsApp.»
- Perché: è il voi.

### SettingsPage.php:101 · Bassa · impostazioni, Pagine del sito e shortcode
- Ora: «Le viste si inseriscono con Gutenberg (…) … Blocchi e widget portano solo i dati e le viste (prossimi eventi, calendario, schede, pezzi della pagina evento): l'impaginazione è del costruttore.»
- Proposta: «Le parti del plugin si inseriscono con Gutenberg (…) … Blocchi e widget portano solo i contenuti (prossimi eventi, calendario, schede, parti della pagina evento): l'impaginazione la decidi tu nel costruttore.»
- Perché: «viste» è una parola da programmatore; «è del costruttore» è oscuro.

### SettingsPage.php:135 · Bassa · impostazioni, campo di una chiave
- Ora: «salvata ma non leggibile su questo sito (database copiato da un altro sito?): reinseriscila»
- Proposta: «salvata ma non leggibile su questo sito (succede se il sito è stato copiato): reinseriscila»
- Perché: «database»; la domanda tra parentesi è una nota da tecnico.

### TechPage.php:15 · Media · bacheca, Integrazioni (Posta in uscita)
- Ora: «Le email del plugin (primo accesso, ricevute, promemoria, avvisi) partono dalla posta del sito. Se non arrivano, qui vedi l'ultimo esito e l'errore segnalato dal server. Se i messaggi risultano inviati ma non arrivano, di solito è un problema di consegna: la soluzione affidabile è collegare il sito a un servizio di posta (un plugin SMTP) con un indirizzo del tuo dominio, e controllare che il dominio abbia i record SPF e DKIM.» (righe 15-16)
- Proposta: «Le email del plugin (primo accesso, ricevute, promemoria, avvisi) partono dalla posta del sito. Qui vedi l'esito degli ultimi invii e l'errore segnalato dal server. Se risultano partite ma non arrivano, il problema è la consegna: chiedi a chi gestisce il sito di collegarlo a un servizio di posta (plugin SMTP) con un indirizzo del tuo dominio e di controllare i record SPF e DKIM.»
- Perché: «Se non arrivano» detto due volte; SPF e DKIM non sono cose che un volontario sistema da solo: meglio dirgli a chi chiedere.

### TextsPage.php:22 · Bassa · bacheca, Testi e lingua
- Ora: «…scrivi la traduzione nella colonna «Personalizzato» rinominandola «Traduzione» (oppure lascia il nome com'è) e caricalo con il codice della lingua.»
- Proposta: «…scrivi la traduzione nella colonna «Personalizzato» (puoi anche rinominarla «Traduzione») e caricalo con il codice della lingua.»
- Perché: prima dice di rinominare, poi che non serve.

### TextsPage.php:155 · Bassa · bacheca, Testi e lingua (conferma)
- Ora: «Togliere TUTTE le sostituzioni e tornare ai testi originali?»
- Proposta: «Togliere tutte le sostituzioni e tornare ai testi originali?»
- Perché: le maiuscole gridano; la domanda è già chiara.

### ToolsPage.php:26 · Bassa · bacheca, Strumenti
- Ora: «…(con le funzioni avanzate anche rendiconto e report).»
- Proposta: «…(con Associazione Semplice Pro anche rendiconto e report).»
- Perché: «funzioni avanzate» non è il nome di niente: altrove si chiama Associazione Semplice Pro.

### TransferPage.php:16 · Bassa · bacheca, Giroconto
- Ora: «Giroconto tra conti»
- Proposta: «Giroconto»
- Perché: un giroconto è sempre tra conti; la voce di menu è «Giroconto».

### TribeImportPage.php:14 · Media · bacheca, Importa da The Events Calendar
- Ora: «Porta qui gli eventi di The Events Calendar (passati e futuri) come eventi una tantum (o come corsi, per le categorie che indichi tu), con titolo, data, orario, luogo e descrizione. Ogni evento di The Events Calendar diventa una sola attività: niente viene raggruppato, nemmeno gli eventi con lo stesso titolo. Così un evento già fatto si può <strong>riproporre</strong> in una nuova data dalla sua scheda, senza rifare tutto. Per ogni evento si compila anche la <strong>pagina pubblica</strong> (Eventi sul sito): descrizione, immagine in evidenza, categorie, organizzatore, sito dell'evento e giorni; di norma resta in bozza, così la controlli prima di pubblicarla. Non si importano costi e prenotazioni. The Events Calendar può anche essere disattivato: gli eventi si leggono dal database del sito. Se ci ripensi, ogni import si può <strong>annullare</strong> da «Import già fatti» in fondo a questa pagina: eventi e corsi creati senza prenotazioni né iscritti vengono tolti (con la loro pagina pubblica), gli altri restano.» (righe 14-16)
- Proposta: «Porta qui gli eventi di The Events Calendar, passati e futuri, con titolo, data, orario, luogo e descrizione. Diventano eventi singoli, oppure corsi per le categorie che indichi tu. Ogni evento diventa una sola attività, anche se altri hanno lo stesso titolo: così un evento già fatto si può <strong>riproporre</strong> in una nuova data dalla sua scheda. Per ogni evento si compila anche la <strong>pagina pubblica</strong> (Eventi sul sito), con descrizione, immagine in evidenza, categorie, organizzatore, sito dell'evento e giorni: di norma resta in bozza, così la controlli prima di pubblicarla. Costi e prenotazioni non si importano. The Events Calendar può anche essere disattivato: i suoi eventi restano leggibili. Se ci ripensi, puoi <strong>annullare</strong> ogni importazione da «Import già fatti», in fondo alla pagina: eventi e corsi senza prenotazioni né iscritti vengono tolti con la loro pagina pubblica, gli altri restano.»
- Perché: 150 parole con sei parentesi; «una tantum» e «dal database del sito» sono gergo.

### TribeImportPage.php:18 · Media · bacheca, Importa da The Events Calendar
- Ora: «Non ho trovato eventi di The Events Calendar su questo sito.»
- Proposta: «Su questo sito non ci sono eventi di The Events Calendar.»
- Perché: il programma parla in prima persona.

### TribeImportPage.php:31 · Bassa · bacheca, Importa da The Events Calendar
- Ora: «Correggili in The Events Calendar e rilancia l'import:» (riga 107: «Si può rilanciare senza creare doppioni.»)
- Proposta: «Correggili in The Events Calendar e ripeti l'importazione:» («Puoi ripeterla senza creare doppioni.»)
- Perché: «rilanciare l'import» è gergo; nel resto del plugin è «importazione».

### TribeImportPage.php:74 · Media · bacheca, Importa da The Events Calendar
- Ora: «Ti propongo «Corso» solo per le categorie che nel nome dicono «corso» o «lezione»; tutte le altre sono «Evento». Gli eventi senza categoria sono eventi. Un evento è un corso solo se nessuna delle sue categorie è «Evento» e almeno una è «Corso».» (righe 74-75)
- Proposta: «Trovi già scelto «Corso» per le categorie che nel nome hanno «corso» o «lezione»; tutte le altre sono «Evento». Gli eventi senza categoria restano eventi. Un evento diventa un corso solo se almeno una sua categoria è «Corso» e nessuna è «Evento».»
- Perché: prima persona del programma; «Un evento è un corso» confonde.

### TribeImportPage.php:105 · Media · bacheca, Importa da The Events Calendar
- Ora: «Spenta: le pagine restano in bozza. Acceso: solo gli eventi con una data futura vengono pubblicati.»
- Proposta: «Senza spunta: le pagine restano in bozza. Con la spunta: si pubblicano solo gli eventi con una data futura.»
- Perché: «Spenta» e «Acceso» non concordano tra loro.

### UpdateNotice.php:36 · Bassa · bacheca, avviso di aggiornamento
- Ora: «Installalo da Bacheca → Aggiornamenti: i dati restano.»
- Proposta: «Installala da Bacheca → Aggiornamenti: i dati restano.»
- Perché: il soggetto è «la versione» (la frase gemella alla riga 39 dice «Scaricala»).

### WatchActions.php:51 · Bassa · bacheca, Bilancio provvisorio
- Ora: «Le foto dei mesi conclusi ci sono già tutte.» / «[N] foto salvate.»
- Proposta: «Le situazioni di fine mese ci sono già tutte.» / «[N] situazioni di fine mese salvate.» Stessa parola in `WatchPage.php` 166 («viene salvata una foto del consuntivo» → «viene salvata la situazione del consuntivo»; «Le foto «ricostruite»» → «Quelle «ricostruite»»), 177 («Nessuna foto salvata: la prima arriva a fine mese.» → «Nessuna situazione salvata: la prima arriva a fine mese.») e 185 («Salva ora le foto dei mesi conclusi mancanti» → «Salva ora le situazioni dei mesi conclusi mancanti»).
- Perché: per gli anni fiscali si è scelto di non dire «fotografia»; «foto» in una pagina di conti fa pensare alle foto degli scontrini. Da valutare: se «foto» qui piace, lasciare tutto com'è.

### WatchPage.php:147 · Media · bacheca, Bilancio provvisorio
- Ora: «Chi ha avuto rimborsi dai fondi deve trovarsi con le spese sostenute: a fine mese è bene, a fine anno è necessario. Differenza = rimborsato − documentato: se è positiva i rimborsi non hanno documenti a copertura (da regolarizzare), se è negativa ci sono spese documentate non ancora rimborsate.»
- Proposta: «Chi ha ricevuto rimborsi dai fondi deve averli coperti con spese documentate: conviene controllarlo ogni mese, a fine anno è necessario. Differenza = rimborsato − documentato: se è positiva mancano documenti (da regolarizzare); se è negativa ci sono spese documentate non ancora rimborsate.»
- Perché: «deve trovarsi con le spese» è parlato e poco chiaro.

### WatchPage.php:255 · Media · bacheca, Conti e fondi (Volontari in credito)
- Ora: «Questi volontari hanno anticipato spese dell'associazione, ad esempio per eventi che incasseranno più avanti, oltre quanto accantonato nei loro fondi: la spesa resta attribuita a loro ed essi restano creditori per un periodo, il che è normale. Si sana da solo con i prossimi incassi dei loro corsi, oppure versando una somma nel loro fondo da un conto o da un altro fondo («Accantona» nella scheda del fondo). Per distribuire la spesa su altri fondi: annulla l'attribuzione qui sotto e attribuiscila a un altro fondo.»
- Proposta: «Questi volontari hanno anticipato spese dell'associazione oltre quanto accantonato nei loro fondi, ad esempio per eventi che incasseranno più avanti. È normale: la spesa resta attribuita a loro e per un periodo restano in credito. Il credito si chiude da solo con i prossimi incassi dei loro corsi, oppure versando una somma nel loro fondo da un conto o da un altro fondo («Accantona» nella scheda del fondo). Per dividere la spesa su altri fondi, annulla l'attribuzione qui sotto e attribuiscila a un altro fondo.»
- Perché: «ed essi restano creditori… il che è normale. Si sana da solo» è burocratese con una frase senza soggetto.

### WizardPage.php:75 · Bassa · configurazione guidata
- Ora: «Descrivi il tuo ente e scegli in 4 sezioni veloci i servizi che vuoi gestire.» · «…riaprendo questa procedura dagli Strumenti.»
- Proposta: «Descrivi il tuo ente e scegli, in 4 brevi sezioni, che cosa vuoi gestire.» · «…riaprendo la configurazione guidata dagli Strumenti.»
- Perché: «i servizi» non è una parola del plugin; «questa procedura» ha già un nome.

### WizardPage.php:91 · Media · configurazione guidata
- Ora: «Accettate ospiti, cioè persone che partecipano senza essere iscritte?»
- Proposta: «L'ente accetta ospiti, cioè persone che partecipano senza essere socie?»
- Perché: è il voi; «iscritte» al posto di «socie» (le Impostazioni, `SettingsPage.php` 66, dicono già «L'ente accetta ospiti (persone che partecipano senza essere soci)»).

### WizardPage.php:94 · Media · configurazione guidata
- Ora: «Solo su presentazione» «(le iscrizioni le fa la segreteria)»
- Proposta: «Solo tramite la segreteria» «(i nuovi soci li registra la segreteria)»
- Perché: «su presentazione» non si capisce senza leggere la parentesi.

### WpAiPage.php:24 · Bassa · bacheca, WP All Import
- Ora: «Import con WP All Import» · «← Import da Excel/CSV» · riga 26: «…l'import da Excel/CSV fa già tutto.» · riga 51: «Impostazioni dell'import»
- Proposta: «Importazione con WP All Import» · «← Importazione da Excel/CSV» · «…l'importazione da Excel/CSV fa già tutto.» · «Impostazioni dell'importazione» (anche `TechPage.php` 49)
- Perché: nella stessa pagina (righe 29-34) si dice «importazione».

### WpAiPage.php:57 · Bassa · bacheca, WP All Import
- Ora: «Segna i soci importati come iscritti all'anno [anno]»
- Proposta: «Attiva la tessera [anno] ai soci importati»
- Perché: stessa regola della scheda del socio: è la tessera che si attiva (vedi `PeoplePage.php` 394).

### WpDashboard.php:24 · Bassa · bacheca di WordPress (riquadro)
- Ora: «Associazione Semplice — Da lavorare» · riga 80: «Niente da lavorare: è tutto in ordine.»
- Proposta: «Associazione Semplice — Da fare» · «Niente da fare: è tutto in ordine.»
- Perché: «da lavorare» è gergo d'ufficio (le «pratiche da lavorare»). Il nome è citato anche in `SettingsPage.php` 85 («nel riquadro «Da lavorare»») e forse nella Bacheca del plugin (altro lotto): cambiare ovunque o lasciare.

### WpDashboard.php:58 · Bassa · bacheca di WordPress (riquadro)
- Ora: «socio da rinnovare» / «soci da rinnovare»
- Proposta: «tessera da rinnovare» / «tessere da rinnovare»
- Perché: si rinnova la tessera, non il socio.

### YearsPage.php:45 · Media · bacheca, Anni fiscali
- Ora: «Si può incassare, spendere e girare denaro solo negli anni fiscali <strong>aperti</strong>. Un anno fiscale chiuso non si modifica più: nemmeno allegati, saldi iniziali o cessazioni dei soci. Si chiude in ordine, dal più vecchio, e si può riaprire solo l'ultimo anno fiscale chiuso, indicando il motivo (resta scritto per sempre). L'anno in corso non si chiude (e il primo giorno di ogni anno il nuovo anno si apre da solo). Un anno finito non si chiude se ha fondi accantonati non ancora rimborsati. La tessera dei soci segue l'anno fiscale.» (righe 45-46)
- Proposta: «Incassi, spese e giroconti si registrano solo negli anni fiscali <strong>aperti</strong>. Un anno fiscale chiuso non si modifica più: nemmeno allegati, saldi iniziali o cessazioni dei soci. Gli anni si chiudono in ordine, dal più vecchio; si può riaprire solo l'ultimo chiuso, indicando il motivo, che resta scritto per sempre. L'anno in corso non si chiude; il nuovo anno si apre da solo il primo giorno. Un anno finito non si chiude finché ha fondi accantonati non ancora rimborsati. La tessera dei soci segue l'anno fiscale.»
- Perché: «girare denaro» è escluso dal glossario (si dice «giroconto»); due parentesi che contengono regole importanti.

### YearsPage.php:93 · Bassa · bacheca, Anni fiscali
- Ora: «…(chi è già stato cancellato non compare, nome e dati anagrafici sono quelli attuali).»
- Proposta: «…(chi è già stato eliminato non compare, nome e dati anagrafici sono quelli attuali).»
- Perché: glossario: «eliminare», non «cancellare».

### YearsPage.php:118 · Media · bacheca, Anni fiscali
- Ora: «Crea anno fiscale» · riga 120: «Creare questo anno? Da adesso si possono registrare incassi e spese di quell'anno.» · «Crea l'anno» · riga 121: «Crea in anticipo l'anno che inizia (ad esempio a novembre l'anno dopo), se devi registrare incassi o spese in anticipo.»
- Proposta: «Apri un nuovo anno fiscale» · «Aprire questo anno fiscale? Da adesso potrai registrarvi incassi e spese.» · «Apri l'anno» · «Apri in anticipo il prossimo anno (ad esempio a novembre) se devi già registrare incassi o spese che lo riguardano.»
- Perché: l'avviso in cima alla stessa pagina dice «Apri l'anno fiscale…» (righe 29-30): la stessa azione ha due verbi. «l'anno che inizia (ad esempio a novembre l'anno dopo)» è contorto e ripete «in anticipo».

## Da verificare (sospetti errori di fatto, non riscritti)

- `ProPanels.php:64` — «Le ricevute dei singoli incassi sono in Contabilità → Prima nota.» Nel menu (`ProMenu.php` 19) «Prima nota» è sotto «Cassa», non sotto «Contabilità».
- `ReceiptCheckPage.php:135` e `WizardPage.php:171` — «Impostazioni → Copia di sicurezza»: nel menu «Copia di sicurezza» è sotto «Strumenti» (`ProMenu.php` 18).
- `WizardPage.php:133` — «Gli IBAN si inseriscono subito dopo, in Pagamenti online.» A fine procedura si viene portati alla pagina «Bonifico» (`TechActions.php` 240, 346), che nel menu è una voce a parte.
- `RuntsPage.php:99` — «si gestiscono da Rubrica soci → Dipendenti»: nelle voci di «Rubrica soci» elencate in `ProMenu.php` 14 «Dipendenti» non c'è (potrebbe essere aggiunta altrove: controllare il percorso).
- `PollsPage.php:83` — «Oppure usa il pulsante «Avvisa i soci ora» qui sopra.» Nel codice il pulsante è stampato dopo il modulo (righe 103 e 125), quindi probabilmente sta sotto. Inoltre «Impostazioni → Privacy e regolamento»: con il Pro la voce si chiama «Privacy, regolamento e ricevute».
- `SettingsPage.php:63` — «nelle pagine «Tessera e QR» e «Integrazioni»»: con il Pro la pagina si chiama «Tessera, QR e Wallet»; e `SettingsPage.php:85` cita la sezione «Pagine del sito», che si intitola «Pagine del sito e shortcode».
- `SettingsPage.php:80` — «Gli eventi gratuiti si annullano sempre; quelli a pagamento mai, ma si può cedere il posto…»: sembra contraddire il campo stesso («Termine predefinito per annullare», che riguarda gli eventi a pagamento annullabili). Forse va inteso «quelli a pagamento non annullabili mai».
- `SettingsPage.php:116-117` — «L'amministrazione è riservata agli utenti con ruolo Amministratore (capability asem_manage). I soci sono utenti WordPress con ruolo "Socio"…»: non torna con il resto del plugin (la segreteria e i dipendenti entrano nell'amministrazione, `PeoplePage.php` 669; con l'«accesso proprio» i soci non sono utenti WordPress, `MemberAccessChoice.php` 24-26). Il paragrafo è anche tutto gergo (capability, wp-admin, API REST): da riscrivere dopo aver stabilito i fatti, o da togliere.
- `PaymentsPage.php:62, 163, 215` — parlano solo di Stripe e PayPal («scegli Stripe, PayPal o entrambi», «sul conto "Stripe" o "PayPal"»), ma la pagina gestisce anche SumUp, Satispay e WooCommerce. Idem `ProPage.php:18` («con carta (Stripe), PayPal o il checkout di WooCommerce»), `ProIntegrations.php:48` e `ResetPage.php:37` («Le chiavi dei pagamenti online (Stripe, PayPal) restano»).
- `MessagesPage.php:109` — la conferma dice «Inviare il messaggio a [N] persone?» con un numero calcolato diversamente da quello del pulsante «Invia a [N] persone»: i due numeri possono non coincidere.
- `ResourcesPage.php:290` — «Le voci si creano in Conti e fondi/Prima nota.»: non è chiaro in quale delle due pagine; indicare quella giusta.
- `UpdateNotice.php:39` — «Plugin → Aggiungi nuovo»: nelle versioni recenti di WordPress la voce si chiama «Aggiungi plugin» (prima «Aggiungi nuovo plugin»).
- `ProMenu.php` 53, 59, 47 e titoli di pagina — il menu dice «Testi e lingua», «Preventivo», «Presenze», ma le pagine si intitolano «Testi personalizzati», «Bilancio preventivo», «Registro presenze». Non è un errore di fatto, ma chi segue una guida può non ritrovarsi; inoltre «Bilancio preventivo / Bilancio provvisorio» usano «bilancio», che il glossario sconsiglia per il rendiconto: decisione del titolare, non riscritto.
