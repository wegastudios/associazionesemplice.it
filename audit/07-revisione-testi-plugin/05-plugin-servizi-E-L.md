# Plugin · Servizi, email e messaggi (file E–L)
File letti: 53 · Proposte: 77 (Alta 1, Media 47, Bassa 29)

Percorso di tutti i file: `wordpress/associazionesemplice/includes/` (solo i file diretti, da `Edition.php` a `LoanRules.php`).
Senza testi per l'utente, o con testi già a posto e quindi senza proposte: Edition, EntityData, EntityRules, EventRequestRules, ExpiryRules, FileCrypt, FileCryptError, FileMigration, Fiscal, Flash, FundYears, Gatekeeper, GoogleWallet, Holds, Html, Iban, Icons, Ics, IncompleteCards, Install (solo nomi delle voci iniziali, vedi Labels.php:26), Insurance, KlarnaRules, Languages, LevelVoting, Levels, LicenseRules, LoanRules.

Nota sui testi spezzati: molte frasi sono costruite unendo più pezzi nel PHP. In «Ora» cito i pezzi alla lettera; dove serve, indico con `…` la parte variabile, che resta com'è.

## Difetti ricorrenti

- **Singolare e plurale con i numeri.** Molti testi attaccano un numero a una parola sempre al plurale: esce «1 posti liberi», «1 posti», «1 volte», «1 ore», «1 pezzi», «fino a 1 persone». Regola: usare la forma già presente in `EventRequests.php:400` e `:176` (singolare se il numero è 1). Righe: EventRequests 233, 252, 266, 419, 554, 741, 791, 824, 951, 1203; Holds 60; Inventory 76.
- **«quota» usata per i fondi.** Il glossario riserva «quota» alla quota associativa. Nei fondi usare «somma» o «accantonamento» (già usati in `FundService.php:150` e `:165`). Righe: FundService 87, 244; FundShare 14, 41, 43, 48. Stesso problema per «Quota attività / corso» (Labels 26, Install 1264): il glossario dice «contributo».
- **«area riservata» per l'area dei soci.** Il glossario vuole «area soci» (e «area riservata» solo per l'account sul portale). In questo lotto: Guide 28, Guardians 39, Limits 60, 62, 129, 218; «nella tua area» senza nome in EventRequests 671 e 1043. Attenzione: nel plugin intero «area riservata» compare circa 125 volte contro 82 di «area soci», quindi la scelta va fatta una volta per tutto il plugin. Nota collegata: l'account sul portale è chiamato «area riservata del portale» (LicenseClient 140) e «area clienti» (Limits 321).
- **Gergo tecnico nei messaggi e nei limiti.** «errore del database», «gateway», «webhook», «account compromesso», «in background», «pendenti», «sandbox», «User-Agent», «firma valida», «piano firmato», «libreria di cifratura (sodium)». Regola: dire cosa è successo e cosa fare, con parole comuni («servizio di pagamento», «accesso rubato», «in sospeso», «modalità di prova»).
- **Errori che non dicono come rimediare.** «Impossibile salvare (errore del database).», «risposta inattesa (…)», «Licenza sospesa dal fornitore.», «Licenza revocata.», «Non autorizzato.». Regola: una frase su cosa è successo e una su cosa fare (riprova, scrivi al supporto, chiedi il permesso).
- **Lo stesso esito con parole diverse.** Per le richieste: pulsante «Approva», ma email e messaggi dicono «accolta»; «lavorata»/«non le lavori» per «gestita»; «respinte», «scartate», «rifiutata» per esiti diversi ma vicini. Regola: «approvare / rifiutare» per la decisione della segreteria, «scartata» solo per ciò che il sistema non registra.
- **«rimandare» con due significati.** In Limits 43 vuol dire «rinviare a dopo», in GuardianConsent 116 e 119 e in Limits 125 vuol dire «inviare di nuovo». Regola: «inviare di nuovo» per il secondo caso.
- **«cancellare» al posto di «eliminare».** Il glossario non usa «cancellare». Righe: Limits 147, 248, 262, 281.
- **Acceso, attivo, spento, disattivi.** Nelle scelte sì/no di Limits lo stesso stato è detto «Acceso», «Attivo», «Accendilo», «Mantienilo attivo», «Se lo disattivi», «Spento». Regola: una sola coppia, «attivo / spento» (righe 94, 95, 166, 167, 172, 173, 178, 179, 288, 289, 294, 295, 321).
- **Virgolette dritte.** Nei messaggi dell'importazione si usano "…" invece di «…»: ImportService 52, 279, 366; LedgerImport 59, 321, 345, 350, 353, 380, 389, 396, 464.
- **«Scusaci:» seguito da un'istruzione.** Nelle email di rifiuto la scusa è legata con i due punti a un ordine («Scusaci: scrivi alla segreteria»). Regola: aprire con «Ci dispiace» e chiudere con l'istruzione. Righe: EventRequests 660, 710, 714, 758, 1031, 1050, 1060.

## Proposte

### wordpress/associazionesemplice/includes/EmployeeRules.php:45 · Bassa · bacheca, elenco dei permessi dei dipendenti (righe 45-47)
- Ora: «Registrazione delle spese» · «Emissione dei rimborsi» · «Visualizzazione di fondi, casse e conti»
- Proposta: «Registrare le spese» · «Emettere i rimborsi» · «Vedere fondi, casse e conti»
- Perché: le altre voci sono nomi semplici o verbi («Importa ed esporta dati»); i sostantivi in «-zione» suonano da modulo.

### wordpress/associazionesemplice/includes/Employees.php:406 · Media · bacheca, errore alla creazione di un dipendente
- Ora: «Con questa email esiste già un utente del sito. L'accesso da dipendente è separato da quello da socio: usa un'altra email per il dipendente (anche lo stesso socio ne può avere due).»
- Proposta: «Questa email è già usata da un utente del sito. Per l'accesso da dipendente serve un'altra email, anche se la persona è già socio.»
- Perché: più corto, dice subito cosa fare; la parentesi finale era poco chiara.

### wordpress/associazionesemplice/includes/Employees.php:421 · Bassa · bacheca, errore alla creazione di un dipendente
- Ora: «Impossibile creare l'utente: »
- Proposta: «Utente non creato: »
- Perché: «Impossibile…» è tono da macchina; il motivo segue già dopo i due punti.

### wordpress/associazionesemplice/includes/EventRequests.php:233 · Media · pagina pubblica dell'evento, email alla segreteria, bacheca
- Ora: «' · ' . $free . ' posti liberi'» (233) · «' posti).'» (554) · «" posti) con un codice fiscale…» (791) · «' posti) con il codice fiscale del socio '» (824) · «' posti, '» (1203) · «'Hai già partecipato ' . $limit . ' volte come ospite'» (741) · «'Scrivi (fino a ' . … . ' persone)'» (252)
- Proposta: «1 posto libero» / «N posti liberi»; «1 posto» / «N posti»; «1 volta» / «N volte»; «1 persona» / «N persone» (come già fatto alle righe 400 e 176). Lo stesso per «ore» (266, 419, 951 e Holds.php:60) e per «pezzi» (Inventory.php:76).
- Perché: con il numero 1 esce una frase sgrammaticata («1 posti liberi»); serve una piccola modifica al codice, non solo al testo.

### wordpress/associazionesemplice/includes/EventRequests.php:242 · Media · pagina pubblica dell'evento e del corso (righe 242 e 941)
- Ora: «I tuoi dati (chi prenota è una persona maggiorenne):» · «I tuoi dati (chi chiede l'iscrizione è una persona maggiorenne; per un minorenne scrivi alla segreteria):»
- Proposta: «I tuoi dati (per prenotare devi essere maggiorenne):» · «I tuoi dati (devi essere maggiorenne; per iscrivere un minorenne scrivi alla segreteria):»
- Perché: la forma in terza persona sembra una constatazione, non una condizione rivolta a chi compila.

### wordpress/associazionesemplice/includes/EventRequests.php:252 · Media · pagina pubblica dell'evento
- Ora: «Scrivi (fino a ' . (int) ( $ui - 1 ) . ' persone) i loro dati: per ognuna serve un posto. Se sono minorenni, ci pensi tu come genitore o tutore.»
- Proposta: «Scrivi i dati di ogni persona (al massimo ' . (int) ( $ui - 1 ) . '): ognuna occupa un posto. Dei minorenni rispondi tu, come genitore o tutore.»
- Perché: la parentesi spezzava la frase; «ci pensi tu» è colloquiale e non dice che si tratta di responsabilità.

### wordpress/associazionesemplice/includes/EventRequests.php:255 · Media · pagina pubblica dell'evento (righe 255 e 338)
- Ora: «Accompagnatore ' . (int) $i . '» · «'Accompagnatore ' . $i . ': servono nome, cognome, data di nascita e codice fiscale validi.'»
- Proposta: «Altra persona ' . (int) $i . '» · «'Altra persona ' . $i . ': servono nome, cognome, data di nascita e codice fiscale validi.'»
- Perché: il glossario esclude «accompagnatore»; la domanda sopra dice già «Prenoti anche per altre persone?».

### wordpress/associazionesemplice/includes/EventRequests.php:267 · Bassa · pagina pubblica dell'evento e del corso (righe 267 e 952)
- Ora: «La partecipazione è confermata dalla segreteria dopo la tua richiesta.» · «L'iscrizione parte dal mese corrente ed è confermata dalla segreteria dopo la tua richiesta.»
- Proposta: «La segreteria conferma la partecipazione dopo la tua richiesta.» · «L'iscrizione parte dal mese corrente e la conferma la segreteria dopo la tua richiesta.»
- Perché: passivo inutile; in forma attiva si capisce subito chi decide.

### wordpress/associazionesemplice/includes/EventRequests.php:269 · Media · pagina pubblica dell'evento, pulsante
- Ora: «Prenota senza iscriverti»
- Proposta: «Prenota senza diventare socio»
- Perché: «iscriversi» nel glossario vale solo per i corsi; qui si intende «senza essere socio» (il titolo sopra dice «Non sei socio?»).

### wordpress/associazionesemplice/includes/EventRequests.php:289 · Bassa · pagina pubblica dell'evento e del corso
- Ora: «Sessione scaduta: ricarica la pagina e riprova.»
- Proposta: «La pagina è scaduta: ricaricala e riprova.»
- Perché: «sessione» è gergo; alla riga 469 lo stesso caso è già detto «La pagina è scaduta».

### wordpress/associazionesemplice/includes/EventRequests.php:354 · Media · pagina pubblica dell'evento, errore
- Ora: «Ogni persona ha un codice fiscale diverso: controlla i dati.»
- Proposta: «Lo stesso codice fiscale compare due volte: controlla i dati.»
- Perché: l'errore enuncia una regola invece di dire cosa non va.

### wordpress/associazionesemplice/includes/EventRequests.php:402 · Bassa · email a chi chiede un posto o un'iscrizione (righe 402 e 1014)
- Ora: «Se non sei stato tu, ignora questo messaggio: la richiesta non avrà seguito.»
- Proposta: «Se non sei stato tu, ignora questo messaggio: non succederà nulla.»
- Perché: «non avrà seguito» è burocratese.

### wordpress/associazionesemplice/includes/EventRequests.php:660 · Media · email a chi ha fatto la richiesta (righe 660, 710, 714, 758, 1031, 1050, 1060)
- Ora: «La data richiesta non è più disponibile. Scusaci: controlla il calendario degli eventi sul sito.» (660, 710) · «I posti per questa data sono esauriti (o non bastano per tutte le persone indicate). Scusaci: puoi scrivere alla segreteria o scegliere un'altra data.» (714) · «Il corso richiesto non è più disponibile. Scusaci: controlla i corsi sul sito o scrivi alla segreteria.» (1031) · «La richiesta non è completa. Scusaci: invia di nuovo la richiesta dalla pagina del corso.» (1050) · «'). Scusaci: scrivi alla segreteria.'» (758, 1060)
- Proposta: «Ci dispiace, la data che hai chiesto non è più disponibile. Guarda il calendario degli eventi sul sito.» · «Ci dispiace, i posti per questa data sono esauriti o non bastano per tutte le persone indicate. Puoi scegliere un'altra data o scrivere alla segreteria.» · «Ci dispiace, il corso che hai chiesto non è più disponibile. Guarda i corsi sul sito o scrivi alla segreteria.» · «Ci dispiace, la richiesta non è completa. Inviala di nuovo dalla pagina del corso.» · «'). Ci dispiace: scrivi alla segreteria.'» (con «Ci dispiace, non è stato possibile…» in apertura, se si preferisce)
- Perché: «Scusaci:» seguito da un ordine suona brusco; la scusa va all'inizio, l'istruzione in una frase sua.

### wordpress/associazionesemplice/includes/EventRequests.php:671 · Bassa · email al socio e messaggio in bacheca (righe 671, 673, 1043, 1045, 1066)
- Ora: «Richiesta accolta: » · «è stata accolta: il posto è prenotato. Lo trovi nella tua area, tra le prenotazioni.» · «Richiesta accolta: il socio è prenotato.» · «Iscrizione accolta: » · «è stata accolta: sei iscritto dal mese corrente. Trovi il corso nella tua area.» · «Richiesta accolta: il socio è iscritto al corso.»
- Proposta: «Richiesta approvata: » · «è stata approvata: il posto è prenotato. Lo trovi nella tua area soci, tra le prenotazioni.» · «Richiesta approvata: il socio è prenotato.» · «Iscrizione approvata: » · «è stata approvata: sei iscritto dal mese corrente. Trovi il corso nella tua area soci.» · «Richiesta approvata: il socio è iscritto al corso.»
- Perché: il pulsante dice «Approva» e le email precedenti dicono «ti scrive appena è approvata»: una parola sola per lo stesso esito; «la tua area» senza nome è vaga.

### wordpress/associazionesemplice/includes/EventRequests.php:684 · Media · bacheca, scheda dell'attività (righe 684 e 1116)
- Ora: «Richiesta non trovata o già lavorata.»
- Proposta: «Richiesta non trovata o già gestita.»
- Perché: «lavorata» è gergo d'ufficio. Stesso caso in Limits.php:143 e :149 («non le lavori in tempo» → «non rispondi in tempo»).

### wordpress/associazionesemplice/includes/EventRequests.php:741 · Media · email all'ospite
- Ora: «' volte come ospite: per continuare serve iscriversi come soci.'»
- Proposta: «' volte come ospite: per continuare devi diventare socio.'»
- Perché: si dà del tu; «iscriversi come soci» è impersonale e al plurale.

### wordpress/associazionesemplice/includes/EventRequests.php:791 · Media · email alla segreteria
- Ora: «" posti) con un codice fiscale già presente in anagrafica ma con nome, cognome o data di nascita diversi dalla scheda esistente ("»
- Proposta: «" posti). Il codice fiscale è già in anagrafica, ma nome, cognome o data di nascita sono diversi da quelli della scheda ("»
- Perché: la frase intera supera le 35 parole; spezzata si legge al primo colpo.

### wordpress/associazionesemplice/includes/EventRequests.php:951 · Media · pagina pubblica del corso
- Ora: «Contributo gratuito. »
- Proposta: «Corso gratuito. »
- Perché: «contributo gratuito» è una contraddizione; per gli eventi (riga 266) si dice già «Partecipazione gratuita.».

### wordpress/associazionesemplice/includes/EventRequests.php:1205 · Bassa · bacheca, scheda dell'attività
- Ora: «Rifiuta la richiesta (chi l'ha inviata riceve il solito messaggio) o contatta il socio.»
- Proposta: «Rifiuta la richiesta (chi l'ha inviata riceve il normale messaggio di rifiuto) o contatta il socio.»
- Perché: «il solito messaggio» è colloquiale e non dice quale.

### wordpress/associazionesemplice/includes/ExpenseFunds.php:377 · Media · bacheca, nota sotto una spesa
- Ora: « Non è ancora attribuita a nessun fondo: succede da sola quando la spesa ha un documento allegato e un fondo con documentazione da coprire.»
- Proposta: « Non è ancora attribuita a un fondo: succede in automatico quando alleghi un documento e c'è un fondo ancora da documentare.»
- Perché: «un fondo con documentazione da coprire» non si capisce; «succede da sola» è sgrammaticato.

### wordpress/associazionesemplice/includes/FileKey.php:635 · Media · bacheca, cifratura dei file riservati (righe 635, 705, 761)
- Ora: «Questo server non ha la libreria di cifratura (sodium).»
- Proposta: «Su questo server manca la libreria di cifratura (sodium): chiedi al tuo fornitore di hosting di attivarla.»
- Perché: l'errore non dice cosa fare; chi legge non è un tecnico (più lungo perché manca l'informazione indispensabile).

### wordpress/associazionesemplice/includes/FiscalYears.php:269 · Bassa · bacheca, errore registrando un movimento
- Ora: «crealo dalla scheda «Anni fiscali» prima di registrare movimenti in quella data.»
- Proposta: «crealo dalla pagina «Anni fiscali» prima di registrare movimenti in quella data.»
- Perché: alla riga 51 lo stesso posto è chiamato «pagina «Anni fiscali»» (vedi anche «Da verificare»).

### wordpress/associazionesemplice/includes/FiscalYears.php:280 · Alta · bacheca, errore alla chiusura dell'anno fiscale
- Ora: «'l\'anno ' . ( $year === self::current() ? 'in corso' : 'non è ancora iniziato' ) . ' non si chiude: si chiude dopo la fine dell\'anno'»
- Proposta: «( $year === self::current() ? 'è ancora in corso' : 'non è ancora iniziato' ) . ' (si chiude dopo la sua fine)'»
- Perché: oggi, nel secondo caso, esce «L'anno 2027 non si può chiudere: l'anno non è ancora iniziato non si chiude: si chiude dopo la fine dell'anno.», frase rotta, con due volte i due punti e tre volte «chiude». Con la proposta: «L'anno 2026 non si può chiudere: è ancora in corso (si chiude dopo la sua fine).».

### wordpress/associazionesemplice/includes/FivePerMille.php:62 · Media · bacheca, errori di salvataggio (anche ImportJob.php:510, Insurance.php:63, Inventory.php:111, LedgerService.php:267)
- Ora: «Impossibile salvare (errore del database).» · «Impossibile salvare lo stato dell'importazione (errore del database).» · «Impossibile salvare la polizza (errore del database).» · «Impossibile salvare il bene (errore del database).» · «Errore del database nel salvare il movimento.»
- Proposta: «Salvataggio non riuscito: riprova. Se succede ancora, scrivi al supporto.» (con l'oggetto dove serve: «La polizza non è stata salvata: riprova. Se succede ancora, scrivi al supporto.», e così per il bene, il movimento e l'importazione)
- Perché: «errore del database» è gergo e non dice cosa fare; cinque forme diverse per lo stesso caso.

### wordpress/associazionesemplice/includes/FivePerMille.php:73 · Media · bacheca, 5 per mille (righe 73, 81, 89)
- Ora: «Contributo non trovato.» · «Racconta come è stato utilizzato il contributo.»
- Proposta: «5 per mille non trovato.» · «Racconta come è stato usato il 5 per mille.»
- Perché: nel glossario «contributo» è quanto si paga per un corso o un evento; qui si parla della somma del 5 per mille.

### wordpress/associazionesemplice/includes/FundReconciliation.php:116 · Media · bacheca, avviso
- Ora: «<strong>Rimborsi e spese non quadrano</strong> per » … «A fine anno chi ha avuto rimborsi deve trovarsi con le spese sostenute.»
- Proposta: «<strong>Rimborsi e spese non coincidono</strong> per » … «A fine anno ogni rimborso deve corrispondere a spese documentate.»
- Perché: «deve trovarsi con le spese sostenute» non si capisce; il glossario evita «quadrare».

### wordpress/associazionesemplice/includes/FundService.php:87 · Media · bacheca, movimenti dei fondi e scheda dell'attività (FundService 87, 244; FundShare.php 14, 41, 43, 48)
- Ora: «Quota del pagamento» · «Quota liberata» · «Nessuna quota» · «Quota per il rimborso non valida.» · «Indica la quota per il rimborso (importo o percentuale maggiori di zero).» · «Per accantonare una quota di rimborso indica il referente (il volontario da rimborsare).»
- Proposta: «Parte del pagamento» · «Somma liberata» · «Nessun accantonamento» · «Valore per il rimborso non valido.» · «Indica quanto accantonare per il rimborso (importo o percentuale maggiori di zero).» · «Per accantonare il rimborso indica il referente (il volontario da rimborsare).»
- Perché: il glossario riserva «quota» alla quota associativa; nello stesso file si usa già «Somma accantonata» e «Somma iniziale».

### wordpress/associazionesemplice/includes/Gateways.php:72 · Media · bacheca, prova dei pagamenti online (righe 72 e 118)
- Ora: «'SumUp: risposta inattesa (' . (int) $r['code'] . ').'» · «'PayPal: risposta inattesa (' . (int) $r['code'] . ').'»
- Proposta: «'SumUp non ha risposto come previsto (codice ' . (int) $r['code'] . '): riprova tra poco.'» · «'PayPal non ha risposto come previsto (codice ' . (int) $r['code'] . '): riprova tra poco.'»
- Perché: non si capisce cosa sia il numero né cosa fare.

### wordpress/associazionesemplice/includes/Gateways.php:86 · Bassa · bacheca, prova di Stripe
- Ora: «'REALE (live)'» · «'di PROVA (test)'»
- Proposta: «'reale (live)'» · «'di prova (test)'»
- Perché: le maiuscole gridano; il messaggio è già chiaro senza.

### wordpress/associazionesemplice/includes/Gateways.php:113 · Bassa · bacheca, prova di PayPal (righe 113 e 116)
- Ora: «'sandbox'»
- Proposta: «'modalità di prova (sandbox)'» alla 113 e «'di prova (sandbox)'» alla 116
- Perché: per Stripe si dice «modalità di prova»; «sandbox» da solo è gergo.

### wordpress/associazionesemplice/includes/GroupCash.php:87 · Bassa · area soci, incasso sul posto dello staff (righe 87 e 139)
- Ora: «Sul posto lo staff incassa solo i biglietti dell'evento.» · «Niente da incassare: i biglietti risultano già versati.»
- Proposta: «Sul posto lo staff incassa solo i contributi dell'evento.» · «Niente da incassare: i contributi risultano già pagati.»
- Perché: il glossario vuole «contributo» e non «biglietto»; «biglietti versati» non è italiano corrente. Da lasciare se «biglietto» qui indica il QR d'ingresso (vedi «Da verificare»).

### wordpress/associazionesemplice/includes/GroupCash.php:122 · Bassa · bacheca, incasso di gruppo
- Ora: «' è sospeso (inattivo): riattivalo a mano prima di iscriverlo a un corso.'»
- Proposta: «' è sospeso (inattivo): riattivalo dalla sua scheda prima di iscriverlo a un corso.'»
- Perché: «a mano» non dice dove; LedgerService.php:395 indica già «dalla sua scheda».

### wordpress/associazionesemplice/includes/GuardianConsent.php:96 · Media · email al genitore o tutore (righe 96-97)
- Ora: «Per registrare che sei stato tu ad accettare, a nome suo, ' . $what . ', apri questo link entro ' . self::DAYS . " giorni e tocca «Confermo»:» … «finché non confermi, queste accettazioni non vengono attribuite a te.»
- Proposta: «Ci serve la tua conferma che hai accettato tu, a nome suo, ' . $what . '. Apri questo link entro ' . self::DAYS . " giorni e tocca «Confermo»:» … «finché non confermi, non risultano accettati da te.»
- Perché: la frase supera le 30 parole e parte dallo scopo dell'ufficio; «accettazioni attribuite a te» è burocratese.

### wordpress/associazionesemplice/includes/GuardianConsent.php:116 · Media · bacheca, scheda del minore (righe 116 e 119)
- Ora: «Hai già rimandato il messaggio più volte: riprova tra un'ora.» · «Email di conferma rimandata al genitore.»
- Proposta: «Hai già inviato di nuovo il messaggio più volte: riprova tra un'ora.» · «Email di conferma inviata di nuovo al genitore.»
- Perché: «rimandare» vuol dire anche «rinviare a dopo» (così è usato in Limits.php:43): qui è ambiguo.

### wordpress/associazionesemplice/includes/Guardians.php:39 · Media · bacheca, scheda del minore
- Ora: «Il tutore deve poter entrare nell'area riservata: un socio o una persona registrata come genitore o tutore (un ospite non può).»
- Proposta: «Il tutore deve poter entrare nell'area soci: scegli un socio o una persona registrata come «genitore o tutore» (un ospite non può).»
- Perché: dopo i due punti mancava il verbo, quindi non si capiva cosa fare; «area soci» come da glossario.

### wordpress/associazionesemplice/includes/Guide.php:28 · Media · bacheca, primi passi (anche Limits.php 60, 62, 129, 218)
- Ora: «Crea l'area riservata sul sito»
- Proposta: «Crea l'area soci sul sito»
- Perché: glossario: «area soci» per la pagina dei soci, «area riservata» solo per l'account sul portale. Nei testi di Limits: «bacheca dell'area riservata» → «bacheca dell'area soci», «nell'area riservata» → «nell'area soci». Da decidere una volta per tutto il plugin (vedi «Difetti ricorrenti»).

### wordpress/associazionesemplice/includes/ImportJob.php:216 · Bassa · bacheca, importazione
- Ora: «L'anteprima è scaduta: ricarica il file.»
- Proposta: «L'anteprima è scaduta: carica di nuovo il file.»
- Perché: «ricarica» si legge anche come «aggiorna la pagina».

### wordpress/associazionesemplice/includes/ImportService.php:52 · Bassa · bacheca, importazione (ImportService 52, 279, 366; LedgerImport.php 59, 321, 345, 350, 353, 380, 389, 396, 464)
- Ora: «Per i soci servono le colonne "Nome" e "Cognome"; per la prima nota "Data" e "Importo" (oppure "Entrata" e "Uscita").»
- Proposta: «Per i soci servono le colonne «Nome» e «Cognome»; per la prima nota «Data» e «Importo» (oppure «Entrata» e «Uscita»).»
- Perché: lo standard è «…»; lo stesso cambio vale per tutte le righe elencate (nomi di colonne, valori del file, «Altro», «Giroconto»).

### wordpress/associazionesemplice/includes/ImportService.php:56 · Media · bacheca, importazione
- Ora: «' righe per tipo: dividilo in più file.'»
- Proposta: «' righe di soci o di movimenti: dividilo in più file.'»
- Perché: «per tipo» non si capisce senza conoscere il programma.

### wordpress/associazionesemplice/includes/ImportService.php:589 · Bassa · bacheca, annullamento di un'importazione (righe 589, 592, 602)
- Ora: «Import non trovato.» · «Questo import è già stato annullato.» · «Annullamento import n. »
- Proposta: «Importazione non trovata.» · «Questa importazione è già stata annullata.» · «Annullamento importazione n. »
- Perché: altrove (ImportJob.php 46, 204) si dice sempre «importazione»; «import» è gergo.

### wordpress/associazionesemplice/includes/Inventory.php:28 · Media · bacheca, apertura della foto di un bene
- Ora: «Non autorizzato.»
- Proposta: «Non hai il permesso di aprire questo file.»
- Perché: messaggio da macchina, senza soggetto; più lungo perché manca il «cosa».

### wordpress/associazionesemplice/includes/Labels.php:26 · Media · bacheca, voci della prima nota e degli incassi (anche Install.php:1264, per le installazioni nuove)
- Ora: «Quota attività / corso»
- Proposta: «Contributo attività / corso»
- Perché: il glossario vieta «quota» per corsi ed eventi: è «contributo». Nelle installazioni esistenti il nome è già salvato tra le voci: va deciso se rinominarlo anche lì.

### wordpress/associazionesemplice/includes/LedgerImport.php:380 · Bassa · bacheca, anteprima dell'importazione della prima nota
- Ora: «( $m ? 'Più persone chiamate' : 'Persona non trovata:' ) . ' "' . $r['person'] . '": importato senza persona'»
- Proposta: «( $m ? 'Più persone chiamate' : 'Persona non trovata' ) . ' «' . $r['person'] . '»: importato senza persona'»
- Perché: oggi esce «Persona non trovata: "Rossi": importato…», con due volte i due punti (alla riga 389 il caso gemello è già senza).

### wordpress/associazionesemplice/includes/LedgerService.php:44 · Bassa · bacheca, conti
- Ora: «Il nome del conto è obbligatorio.»
- Proposta: «Dai un nome al conto.»
- Perché: tono da modulo; per i fondi si dice già «Dai un nome al fondo.» (FundService.php:138).

### wordpress/associazionesemplice/includes/LedgerService.php:368 · Media · bacheca, registrazione di un incasso
- Ora: «Voce {n}: la data dell'evento richiede l'attività.»
- Proposta: «Voce {n}: hai scelto una data ma non l'evento. Indica l'evento.»
- Perché: «la data richiede l'attività» è una frase da programmatore: non dice cosa manca.

### wordpress/associazionesemplice/includes/LedgerService.php:380 · Bassa · bacheca, registrazione di un incasso (righe 380 e 386)
- Ora: «Voce {n}: i corsi non hanno date: indica il mese di competenza.» · «Voce {n}: la quota associativa richiede di indicare il socio.»
- Proposta: «Voce {n}: un corso non ha date, indica il mese di competenza.» · «Voce {n}: indica di quale socio è la quota associativa.»
- Perché: due volte i due punti nella prima; «richiede di indicare» è burocratese.

### wordpress/associazionesemplice/includes/LessonExceptions.php:64 · Media · bacheca, calendario dei corsi (righe 64 e 121)
- Ora: «Le lezioni si annullano o spostano per un corso.»
- Proposta: «Solo le lezioni di un corso si possono annullare o spostare.»
- Perché: così com'è non si capisce che è un errore né perché l'azione è stata rifiutata.

### wordpress/associazionesemplice/includes/License.php:98 · Media · bacheca, avviso sulla licenza
- Ora: «La chiave di licenza salvata non si legge più su questo sito (succede se cambiano i codici di sicurezza di WordPress o se il sito è stato spostato): reinseriscila in Impostazioni → Licenza.»
- Proposta: «La chiave di licenza salvata non si legge più su questo sito (può succedere dopo uno spostamento del sito o un cambio dei codici di sicurezza di WordPress). Inseriscila di nuovo in Impostazioni → Licenza.»
- Perché: oltre 30 parole in una frase sola, con l'istruzione in coda dopo la parentesi; spezzata, l'azione da fare risalta.

### wordpress/associazionesemplice/includes/License.php:270 · Bassa · bacheca, Impostazioni → Licenza
- Ora: «Nessuna chiave inserita: funzionano le funzioni di base.»
- Proposta: «Nessuna chiave inserita: sono attive le funzioni di base.»
- Perché: «funzionano le funzioni» è una ripetizione.

### wordpress/associazionesemplice/includes/License.php:296 · Media · bacheca, Impostazioni → Licenza (righe 296, 301, 319)
- Ora: «La risposta del server delle licenze non ha una firma valida: la licenza non è stata aggiornata.»
- Proposta: «Non è stato possibile verificare la risposta del server delle licenze: la licenza resta com'era. Riprova più tardi.»
- Perché: «firma valida» è gergo; manca cosa fare.

### wordpress/associazionesemplice/includes/License.php:331 · Media · bacheca, Impostazioni → Licenza
- Ora: «La risposta del portale non porta il piano firmato: la licenza non è stata aggiornata.»
- Proposta: «Il portale non ha confermato il tuo piano: la licenza resta com'era. Riprova più tardi.»
- Perché: «non porta il piano firmato» è incomprensibile per chi non conosce il meccanismo.

### wordpress/associazionesemplice/includes/LicenseClient.php:137 · Media · bacheca, Impostazioni → Licenza (righe 137 e 138)
- Ora: «Licenza sospesa dal fornitore.» · «Licenza revocata.»
- Proposta: «Licenza sospesa: scrivi al supporto di Associazione Semplice.» · «Licenza revocata: scrivi al supporto di Associazione Semplice.»
- Perché: «dal fornitore» è freddo e vago; in nessuno dei due casi si dice cosa fare.

### wordpress/associazionesemplice/includes/LicenseClient.php:140 · Bassa · bacheca, Impostazioni → Licenza (e Limits.php:321)
- Ora: «cambialo dall'area riservata del portale.» · «gli aggiornamenti vanno scaricati dall'area clienti e caricati a mano.»
- Proposta: «cambialo dall'area riservata del portale.» (resta) · «gli aggiornamenti vanno scaricati dall'area riservata del portale e caricati a mano.»
- Perché: lo stesso posto ha due nomi; il glossario indica «area riservata» per l'account sul portale.

### wordpress/associazionesemplice/includes/LicenseClient.php:166 · Bassa · bacheca, Impostazioni → Licenza
- Ora: «'Il server delle licenze non è disponibile adesso (codice ' . $code . ').'»
- Proposta: «'Il server delle licenze non risponde (codice ' . $code . '): riprova più tardi.'»
- Perché: aggiunge cosa fare senza allungare.

### wordpress/associazionesemplice/includes/LicensePolicy.php:64 · Bassa · bacheca, popup sulla licenza
- Ora: «Non è stato possibile verificare la licenza di Associazione Semplice da troppo tempo: controlla che il sito raggiunga associazionesemplice.it, poi premi «Controlla adesso» in Impostazioni → Licenza.»
- Proposta: «La licenza di Associazione Semplice non si riesce a verificare da troppo tempo: controlla che il sito raggiunga associazionesemplice.it, poi premi «Controlla adesso» in Impostazioni → Licenza.»
- Perché: «Non è stato possibile… da troppo tempo» non regge grammaticalmente (un fatto concluso con una durata ancora in corso).

### wordpress/associazionesemplice/includes/LicensePolicy.php:67 · Media · bacheca, popup sulla licenza (righe 67 e 69)
- Ora: «Questo sito non risulta più associato a una licenza valida di Associazione Semplice.» · «Il pagamento della licenza di Associazione Semplice non risulta effettuato.»
- Proposta: «Questo sito non ha più una licenza valida di Associazione Semplice: controlla in Impostazioni → Licenza.» · «Non ci risulta il pagamento della licenza di Associazione Semplice: controlla in Impostazioni → Licenza.»
- Perché: «non risulta associato», «non risulta effettuato» sono burocratese; nessuno dei due dice dove andare.

### wordpress/associazionesemplice/includes/Limits.php:48 · Bassa · bacheca, Limiti e soglie
- Ora: «Le comunicazioni partono a gruppi: il primo subito, gli altri in background. Questo numero è la grandezza di ogni gruppo.»
- Proposta: «Le comunicazioni partono a gruppi: il primo subito, gli altri poco dopo, da soli. Questo numero è la grandezza di ogni gruppo.»
- Perché: «in background» è gergo.

### wordpress/associazionesemplice/includes/Limits.php:55 · Media · bacheca, Limiti e soglie (righe 55, 67, 79)
- Ora: «permette a un account compromesso di inviarne molti prima di essere fermato.» · «Consentendoli, un account compromesso potrebbe indirizzare i pagamenti dei soci su un conto che non è quello dell'associazione.» · «Un account compromesso o un programma automatico potrebbe registrare moltissimi dispositivi e rallentare gli invii.»
- Proposta: «permette a chi ruba un accesso di inviarne molti prima di essere fermato.» · «Se li consenti, chi ruba un accesso potrebbe far pagare i soci su un conto che non è quello dell'associazione.» · «Chi ruba un accesso, o un programma automatico, potrebbe registrare moltissimi dispositivi e rallentare gli invii.»
- Perché: «account compromesso» è gergo di sicurezza informatica; «accesso rubato» lo capisce chiunque.

### wordpress/associazionesemplice/includes/Limits.php:66 · Media · bacheca, Limiti e soglie
- Ora: «Per impostazione predefinita gli avvisi inviati dai volontari non possono contenere indirizzi web né IBAN: è il modo più comune per far arrivare ai soci un falso pagamento usando l'account di un volontario compromesso. Gli amministratori e la segreteria non hanno questo limite.»
- Proposta: «Di norma gli avvisi dei volontari non possono contenere indirizzi web né IBAN: sono il mezzo più usato per chiedere ai soci un pagamento falso con l'accesso rubato a un volontario. Amministratori e segreteria non hanno questo limite.»
- Perché: 35 parole nella prima frase; «volontario compromesso» si legge come un giudizio sulla persona; «è il modo» non aveva un soggetto chiaro.

### wordpress/associazionesemplice/includes/Limits.php:94 · Bassa · bacheca, Limiti e soglie (righe 94, 95, 166, 167, 172, 173, 178, 179, 288, 289, 294, 295, 321)
- Ora: «Accendilo solo se…» · «Spento (consigliato…» · «Se lo disattivi…» · «Mantienilo attivo…» · «Acceso, il sito usa…» · «Attivo: un socio…» · «Acceso: è il comportamento consigliato…»
- Proposta: una sola coppia, «attivo / spento»: «Attivalo solo se…», «Se lo spegni…», «Attivo, il sito usa…», «Attivo: è il comportamento consigliato…»
- Perché: lo stesso interruttore è descritto con quattro parole diverse nella stessa pagina.

### wordpress/associazionesemplice/includes/Limits.php:124 · Bassa · bacheca, Limiti e soglie
- Ora: «Un link che resta valido a lungo (anche i 30 giorni di prima) può essere usato da altri se viene inoltrato o se il telefono è condiviso.»
- Proposta: «Un link che resta valido a lungo può essere usato da altri se viene inoltrato o se il telefono è condiviso.»
- Perché: «i 30 giorni di prima» rimanda a una versione passata che chi installa oggi non conosce.

### wordpress/associazionesemplice/includes/Limits.php:129 · Media · bacheca, Limiti e soglie
- Ora: «Per quante ore, senza usare l'area riservata, resta aperto l'accesso di un socio che è entrato con un link, con un codice o con la password senza spuntare «Ricordami». Chi spunta «Ricordami» (solo con la password) resta entrato 30 giorni.»
- Proposta: «Dopo quante ore di inattività si chiude l'accesso di un socio entrato con un link, con un codice o con la password senza «Ricordami». Chi spunta «Ricordami» (solo con la password) resta entrato 30 giorni.»
- Perché: la prima frase ha 30 parole e due incisi prima del verbo.

### wordpress/associazionesemplice/includes/Limits.php:147 · Media · bacheca, Limiti e soglie
- Ora: «Quante richieste di posto o di iscrizione dei non soci possono aspettare insieme: quelle da confermare via email (contano solo nelle 24 ore in cui il link vale, poi si cancellano) e quelle che aspettano la segreteria. Oltre questo numero le nuove richieste non vengono registrate e in Bacheca compare un avviso. Non si somma alle domande di adesione, che hanno il loro limite.»
- Proposta: «Quante richieste di posto o di iscrizione dei non soci possono restare in attesa insieme: quelle ancora da confermare via email (per le 24 ore in cui il link vale, poi si eliminano) e quelle che aspettano la segreteria. Oltre questo numero le nuove non vengono registrate e in Bacheca compare un avviso. Le domande di adesione hanno un limite a parte.»
- Perché: prima frase di 36 parole; «si cancellano» contro il glossario; l'ultima frase non aveva soggetto.

### wordpress/associazionesemplice/includes/Limits.php:152 · Bassa · bacheca, Limiti e soglie, etichette (righe 152 e 158)
- Ora: «Posti chiedibili con lo stesso indirizzo email, in 24 ore» · «Posti chiedibili dalla stessa connessione, in 24 ore»
- Proposta: «Posti per lo stesso indirizzo email in 24 ore, al massimo» · «Posti per la stessa connessione in 24 ore, al massimo»
- Perché: «chiedibili» non è una parola d'uso; le altre etichette usano già «…, al massimo».

### wordpress/associazionesemplice/includes/Limits.php:155 · Media · bacheca, Limiti e soglie
- Ora: «Chi organizza una gita per più gruppi con la stessa email può doversi rivolgere alla segreteria. Non può scendere sotto i 10 posti di una singola richiesta.»
- Proposta: «Chi organizza una gita per più gruppi con la stessa email potrebbe doversi rivolgere alla segreteria. Il limite non può scendere sotto 10, cioè i posti di una singola richiesta.»
- Perché: nella seconda frase il soggetto sembra «chi organizza», non il limite.

### wordpress/associazionesemplice/includes/Limits.php:193 · Media · bacheca, Limiti e soglie (righe 193, 199, 203, 209, 210)
- Ora: «i gateway rifiuterebbero il pagamento.» · «Più controlli verso il gateway: aumentano le chiamate…» · «Se il gateway lo conferma comunque più tardi…» · «creare migliaia di pagamenti pendenti sul gateway.» · «consumano chiamate al gateway.»
- Proposta: «i servizi di pagamento rifiuterebbero il pagamento.» · «Più controlli verso il servizio di pagamento: aumentano le richieste…» · «Se il servizio di pagamento lo conferma comunque più tardi…» · «creare migliaia di pagamenti in sospeso sul servizio di pagamento.» · «consumano richieste al servizio di pagamento.»
- Perché: «gateway», «pendenti» e «chiamate» sono gergo; l'etichetta della riga 196 dice già «pagamento in sospeso».

### wordpress/associazionesemplice/includes/Limits.php:197 · Media · bacheca, Limiti e soglie
- Ora: «Dopo quanti minuti il sito interroga il gateway sui pagamenti non ancora confermati (oltre al webhook e al ritorno del socio).»
- Proposta: «Dopo quanti minuti il sito chiede al servizio di pagamento l'esito dei pagamenti non ancora confermati.»
- Perché: «interroga il gateway» e «webhook» sono incomprensibili per un volontario; la parentesi non aiuta a scegliere il valore.

### wordpress/associazionesemplice/includes/Limits.php:238 · Media · bacheca, Limiti e soglie
- Ora: «Più movimenti con lo stesso scontrino da dividere.»
- Proposta: «Chi ha molti documenti per una spesa deve dividerla in più movimenti.»
- Perché: la frase senza verbo non fa capire qual è la conseguenza.

### wordpress/associazionesemplice/includes/Limits.php:249 · Media · bacheca, Limiti e soglie (righe 249 e 250)
- Ora: «Si può risalire a più indietro per capire chi ha fatto cosa, ma la tabella cresce e occupa più spazio nel database del sito.» · «Il database resta più leggero, ma non si potrà più controllare chi ha fatto cosa prima di quel limite.»
- Proposta: «Puoi risalire più indietro per capire chi ha fatto cosa, ma il registro cresce e occupa più spazio sul sito.» · «Il sito resta più leggero, ma non potrai più controllare chi ha fatto cosa prima di quel limite.»
- Perché: «tabella» e «database» sono gergo; «risalire a più indietro» è sgrammaticato.

### wordpress/associazionesemplice/includes/Limits.php:264 · Bassa · bacheca, Limiti e soglie
- Ora: «Passato il tempo non si potrà più tornare indietro dal sito: se vuoi un ricordo più duraturo scarica la copia e conservala tu.»
- Proposta: «Passato il tempo non potrai più tornare indietro dal sito: se vuoi tenerla più a lungo, scarica la copia e conservala tu.»
- Perché: «un ricordo più duraturo» è fuori registro per una copia di sicurezza.

### wordpress/associazionesemplice/includes/Limits.php:271 · Media · bacheca, Limiti e soglie
- Ora: «più facile creare doppioni, e il contatore delle volte già venuto può dividersi su più schede.»
- Proposta: «è più facile creare doppioni, e il conteggio delle sue presenze può dividersi su più schede.»
- Perché: «il contatore delle volte già venuto» è sgrammaticato.

### wordpress/associazionesemplice/includes/Limits.php:281 · Media · bacheca, Limiti e soglie (anche 248 e 262)
- Ora: «altrimenti il posto viene cancellato.» · «vengono cancellate da sole, ogni giorno.» · «vengono cancellate da sole.»
- Proposta: «altrimenti il posto torna libero.» · «vengono eliminate in automatico, ogni giorno.» · «vengono eliminate in automatico.»
- Perché: un posto non «si cancella»: nelle email al pubblico si dice sempre «torna libero»; il glossario usa «eliminare».

### wordpress/associazionesemplice/includes/Limits.php:289 · Bassa · bacheca, Limiti e soglie
- Ora: «e il residuo non viene seguito.»
- Proposta: «e il programma non tiene conto di quanto manca.»
- Perché: «il residuo non viene seguito» è gergo contabile e passivo.

### wordpress/associazionesemplice/includes/Limits.php:293 · Media · bacheca, Limiti e soglie
- Ora: «Il collegamento resta registrato e il ragazzo si invita a creare il suo accesso.»
- Proposta: «Il collegamento resta registrato e puoi invitare il ragazzo a creare il suo accesso.»
- Perché: «il ragazzo si invita» si legge come se si invitasse da solo.

### wordpress/associazionesemplice/includes/Limits.php:295 · Media · bacheca, Limiti e soglie
- Ora: «Spento: il tutore continua a gestire il ragazzo anche dopo i 18 anni, finché un amministratore non toglie il collegamento (è il comportamento delle versioni precedenti): il tutore vede e può esportare i dati di una persona adulta senza il suo consenso.»
- Proposta: «Spento: il tutore continua a gestire il ragazzo anche dopo i 18 anni, finché un amministratore non toglie il collegamento (come nelle versioni precedenti). Così il tutore vede e può esportare i dati di un adulto senza il suo consenso.»
- Perché: oltre 40 parole con tre volte i due punti; il rischio vero finiva in coda.

### wordpress/associazionesemplice/includes/Limits.php:306 · Bassa · bacheca, Limiti e soglie
- Ora: «Messaggi di sollecito più prolungati: utili ma possono dare fastidio.»
- Proposta: «Solleciti per più giorni: utili, ma possono dare fastidio.»
- Perché: «più prolungati» è ridondante e vago.

### wordpress/associazionesemplice/includes/Limits.php:319 · Media · bacheca, Limiti e soglie
- Ora: «Il gratuito manda solo prodotto e versione (nessun dominio né dato dei soci; il portale vede l'indirizzo IP del server e l'User-Agent di WordPress, che contiene l'indirizzo del sito); il Pro anche la chiave di licenza e il dominio.»
- Proposta: «La versione gratuita invia solo prodotto e versione: nessun dominio né dato dei soci. Il portale vede comunque l'indirizzo IP del server e l'indirizzo del sito, che WordPress aggiunge a ogni richiesta. Il Pro invia anche la chiave di licenza e il dominio.»
- Perché: frase di 40 parole con parentesi e punto e virgola annidati; «User-Agent» è gergo. I fatti restano gli stessi: è un testo informativo sui dati inviati, da rileggere prima di applicarlo.

## Da verificare (sospetti errori di fatto, non riscritti)

- `FiscalYears.php:51` dice «dalla pagina «Anni fiscali»», `FiscalYears.php:269` dice «dalla scheda «Anni fiscali»»: verificare se a schermo è una pagina o una scheda e usare lo stesso nome nei due punti.
- `Limits.php:319`: la frase dice «nessun dominio» e subito dopo che l'User-Agent di WordPress «contiene l'indirizzo del sito». Le due affermazioni sembrano contraddirsi: verificare cosa riceve davvero il portale dalla versione gratuita.
- `Limits.php:20`: il gruppo «Bilancio, avvisi e fondi» usa «bilancio», che il glossario sconsiglia al posto di «rendiconto». I limiti di quel gruppo arrivano da un'estensione e non sono in questo lotto: verificare se «Rendiconto, avvisi e fondi» è più corretto.
- `Limits.php:59-60`: «bacheca dell'area riservata» è la bacheca degli avvisi per i soci, mentre «Bacheca» (maiuscola, righe 142, 147, 318-319) è la pagina iniziale del plugin. Stessa parola per due cose: verificare come si chiama a schermo la bacheca dei soci.
- `GroupCash.php:87` e `:139`: «biglietti dell'evento». Verificare se a schermo lo staff vede «biglietti» (il QR d'ingresso, come in `Guide.php:37`) o «contributi»: nel primo caso il testo va lasciato.
- `Guide.php:28`: il suggerimento dice «La sezione «Pagine del sito e shortcode» crea le pagine per soci e volontari.» Verificare che la sezione si chiami ancora così nelle Impostazioni.
- `Limits.php:190`: «Importo minimo per pagare online» ha come unità «centesimi di euro» (valore 50): verificare che a schermo sia chiaro che 50 vuol dire 0,50 €.
- `EventRequests.php:266` e `:419`: si chiede di mandare alla segreteria «la ricevuta del bonifico». Nel glossario «ricevuta» è il documento numerato emesso dall'associazione: verificare se qui va bene così (è il nome d'uso del documento della banca) o se serve «la conferma del bonifico».
- `LicenseClient.php:137-138` e le proposte con «scrivi al supporto»: verificare qual è il canale giusto da indicare a chi ha la licenza sospesa o revocata.
