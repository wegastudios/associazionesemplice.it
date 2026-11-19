# Plugin · Area soci, moduli pubblici e schede di presentazione
File letti: 40 · Proposte: 81 (Alta 5, Media 43, Bassa 33)

Note di lettura
- Percorsi relativi a `wordpress/associazionesemplice/` salvo dove indicato. Nei testi «Ora» l'apostrofo del sorgente (`\'`) è scritto `'`; `[…]` indica un pezzo calcolato dal codice (nome, numero, importo), che resta com'è.
- I testi italiani sono anche le chiavi di `includes/languages/en.json`: ogni frase cambiata va aggiornata anche lì, altrimenti la versione inglese perde la traduzione.
- File senza testi per l'utente (nessuna proposta): `Frontend/Assets.php`, `Blocks.php`, `Front.php`, `ElementorSupport.php`, `CardPdf.php`, `CertificatesFront.php`, `ConsentFront.php`, `AssembliesFront.php`, `BlockBViews.php`, `WhatsappFloat.php`, `Elementor/ReservedWidget.php`, `Rest/Api.php` (messaggi per chi sviluppa), `assets/frontend.js` e `assets/pwa.js` (testi già chiari, salvo «Confermi?»), le due intestazioni «Description».

## Difetti ricorrenti

- **«Area riservata» al posto di «area soci».** Il glossario vuole «area soci»; «area riservata» è l'account sul portale. Righe: `Frontend/Views.php` 143, 151 (titolo «Area riservata ai soci»), 702; `Frontend/Activation.php` 45, 161 (due volte); `Frontend/MemberLoginPage.php` 301 («Vai alla tua area»); `assets/admin.js` 143, 144, 145. Regola: sempre «area soci».
- **«L'associazione» come contatto, invece di «la segreteria».** `Frontend/Views.php` 187 («Contatta l'associazione.»), 193, 715 («scrivi all'associazione»). Regola: chi risponde è sempre «la segreteria»; verbo «scrivi alla segreteria».
- **Singolare e plurale non gestiti.** Con 1 esce «1 prenotati», «1 crediti», «1 persone». Righe: `Frontend/Views.php` 786 («prenotati · presenti»), 872 («prenotazioni annullate non sono in elenco»), 918 («ora N persone»), 1034, 1043 («iscritti»), 1392 («posti liberi»); `Frontend/CreditsFront.php` 100, 135, 138, 153, 161, 166, 196, 206; `Frontend/ResourcesFront.php` 104; `Frontend/BlockBViews.php` 63 («Hanno votato N soci su M»). Regola: forma singolare quando il numero è 1, come già fanno `Actions.php` 414 e 635.
- **«Versare» e «pagare» per la stessa cosa.** «Da versare / Versato» in `Frontend/Views.php` 262, 353, 840, 842, 864, `Frontend/TicketVerify.php` 116, 117, `Frontend/Actions.php` 440; «da pagare / pagato» in `Frontend/Views.php` 272, 276, 404, `Frontend/ResourcesFront.php` 186, `Frontend/ResourcesGate.php` 90. Regola: una sola coppia. Consiglio «da pagare / pagato», che è quella della sezione «Pagamenti» e del pulsante «Paga ora».
- **Tessera «non valida», «scaduta», «non in regola».** Tre modi per lo stesso stato: `Frontend/Views.php` 213, 228 («Tessera scaduta»); `Frontend/Actions.php` 181, 531, 538 («non è in regola»); `Frontend/BlockFViews.php` 126 («soci in regola con la tessera»); `Frontend/ProViews.php` 221 («tessera scaduta», «in regola»); `assets/admin.js` 407 («Tessera non in regola») contro 734 («tessera non valida»). Regola: «tessera valida / non valida» ovunque, come già dicono la verifica con il QR e `Actions::card_invalid()`.
- **«Accedi» ed «Entra» per lo stesso pulsante.** «Accedi»: `Frontend/Views.php` 149, `Frontend/MemberLoginPage.php` 260, 266, `Frontend/Restrict.php` 187. «Entra»: `Frontend/MemberLoginPage.php` 317, 356, 323 («Entra con il codice»), e tutti i messaggi («entra con la tua email»). Regola: verbo «Entra» su pulsanti e frasi, «accesso» come nome (pagina «Accesso soci», «Primo accesso»).
- **Trattino lungo usato come inciso o separatore.** `Frontend/Views.php` 891, 1033, 1437, 1474, 1484; `Frontend/Actions.php` 440; `Frontend/FirstAccess.php` 82, 173 (oggetto delle email); `assets/blocks.js` 103. Regola: due punti nelle frasi, « · » (già usato in tutta l'area) tra un'etichetta e un valore.
- **Virgolette diritte invece di «…».** `Frontend/Activation.php` 71 ("Password dimenticata"); `Frontend/FirstAccess.php` 81 (\"Primo accesso\"); `Frontend/ProActions.php` 144 ("Cassa contanti").
- **Punti esclamativi.** `Frontend/Actions.php` 624 («Il tuo voto è stato registrato. Grazie!» → «Voto registrato. Grazie.»); `Frontend/Activation.php` 161 («Ciao [nome]!» → «Ciao [nome],»); `Frontend/Application.php` 216 («Hai già pagato la quota: grazie!» → «Hai già pagato la quota, grazie.»).
- **Apostrofo tipografico e diritto mescolati.** Tipografico (’) solo in `Frontend/Views.php` 808, 1392 («lista d’attesa») e `Frontend/CreditsFront.php` 211 («un’attività», «l’importo»); diritto in tutto il resto. Regola: uno solo (il diritto, che è quello delle altre 7.000 righe).
- **Emoji al posto delle icone.** 📎 📷 🔒 💬 ✔ ✓ in `Frontend/Views.php` 595, 606, 846, 856, 1403, 1563, 1617; `Frontend/Restrict.php` 184, 454; `Frontend/WhatsappFloat.php` 47. Cambiano aspetto da telefono a telefono; il plugin ha già le icone Lucide (`Icons::svg`), usate nella barra dell'area soci. Rifinitura, non urgente.

## Proposte

### includes/Frontend/Actions.php:72 · Media · area soci, messaggio di errore (anche riga 104; `ResourcesFront.php` 45; `CreditsFront.php` 41; `ResourcesGate.php` 133)
- Ora: «Operazione non riuscita. Riprova più tardi.»
- Proposta: «Qualcosa non ha funzionato. Riprova tra poco; se succede ancora, scrivi alla segreteria.»
- Perché: tono da macchina e nessuna via d'uscita se l'errore si ripete (più lunga, ma manca il «cosa fare»).

### includes/Frontend/Actions.php:94 · Bassa · pagina di errore del profilo
- Ora: «Metodo non consentito.»
- Proposta: «Questa pagina si apre solo salvando il profilo: torna all'area soci.»
- Perché: gergo tecnico; chi lo legge non sa cosa fare.

### includes/Frontend/Actions.php:127 · Media · area soci, messaggio di errore (anche `ResourcesFront.php` 63; `CreditsFront.php` 71)
- Ora: «Serve un accesso come socio.»
- Proposta: «Per farlo devi entrare come socio.»
- Perché: frase fredda e impersonale; si dà del tu.

### includes/Frontend/Actions.php:161 · Media · area soci e pagine degli eventi, errore alla prenotazione
- Ora: «La tua tessera non è valida: per [prenotare] rinnova la quota associativa. » seguito da «Rinnova online dalla tua area soci (sezione «Pagamenti»), oppure rivolgiti alla segreteria.»
- Proposta: «La tua tessera non è valida: non puoi [prenotare]. » seguito dalla stessa frase di `renew_where()`.
- Perché: «rinnova… Rinnova» due volte di fila; così la prima frase dice il problema e la seconda il rimedio. Funziona con tutti i verbi passati («partecipare», «iscriverti», «gestire le prenotazioni»).

### includes/Frontend/Actions.php:199 · Media · area soci, esito della richiesta (identica alla riga 213)
- Ora: «Richiesta inviata: la segreteria la esamina e ti scrive. Le richieste si esaminano nell'ordine di arrivo.»
- Proposta: «Richiesta inviata. La segreteria esamina le richieste in ordine di arrivo e ti scrive.»
- Perché: «esamina» ripetuto due volte e seconda frase impersonale.

### includes/Frontend/Actions.php:232 · Bassa · area soci, messaggi «non trovato» (anche 318, 502, 592, 613; `ProActions.php` 94, 128)
- Ora: «Evento non trovato.» · «Data non trovata.» · «Prenotazione non trovata.»
- Proposta: «Questo evento non c'è più: ricarica la pagina.» · «Questa data non c'è più: ricarica la pagina.» · «Questa prenotazione non c'è più: ricarica la pagina.»
- Perché: l'errore non dice come rimediare.

### includes/Frontend/Actions.php:240 · Media · pagine degli eventi, scelta del contributo (anche etichette `Views.php` 1435 e 1482)
- Ora: «Scegli la tariffa: per questa attività bisogna sceglierne una.» · etichetta «Tariffa»
- Proposta: «Scegli uno dei contributi proposti per questa attività.» · etichetta «Contributo»
- Perché: il glossario esclude «tariffa»; la frase ripete «scegli… sceglierne».

### includes/Frontend/Actions.php:284 · Bassa · area soci
- Ora: «Grazie, il [regolamento] è stato accettato.»
- Proposta: «Grazie: hai accettato il [regolamento].»
- Perché: passivo inutile.

### includes/Frontend/Actions.php:353 · Media · area soci, cessione del posto (righe 353, 358, 361)
- Ora: «Scegli a chi intestare la prenotazione, oppure indica nome e cognome di un nuovo ospite.» · «Nominativo cambiato.» · « Da integrare: [importo] (si paga in sede).»
- Proposta: «Scegli a chi cedere il posto, oppure scrivi nome e cognome di un nuovo ospite.» · «Posto ceduto.» · « Resta da pagare: [importo] (in sede).»
- Perché: il pulsante dice «Cedi il posto», i messaggi parlano di «intestare» e «nominativo» (burocratese): stessa azione, tre nomi.

### includes/Frontend/Actions.php:640 · Media · area soci
- Ora: «L'ente non accetta ospiti.»
- Proposta: «L'associazione non prevede ospiti.»
- Perché: «ente» è burocratese; al socio si parla di «associazione».

### includes/Frontend/Actions.php:648 · Bassa · area soci
- Ora: «Non è possibile aggiungere questo numero come tuo ospite: scrivi alla segreteria.»
- Proposta: «Non puoi aggiungere questo numero come ospite: scrivi alla segreteria.»
- Perché: impersonale; più corta.

### includes/Frontend/Activation.php:19 · Alta · attivazione, primo accesso, accesso soci, domanda di adesione (anche `MemberLoginPage.php` 29 e 156; `Application.php` 82)
- Ora: «Il modulo deve partire dalla sua pagina, oppure è scaduto: ricarica la pagina e riprova.» · «Il modulo deve partire dalla pagina di accesso, oppure è scaduto: ricarica la pagina e riprova.» · «Sessione scaduta: ricarica la pagina e riprova.»
- Proposta (una sola per tutti): «La pagina è rimasta aperta troppo a lungo: ricaricala e riprova.»
- Perché: «il modulo deve partire dalla sua pagina» è incomprensibile per un socio; «sessione» è gergo. Tre frasi per lo stesso caso.

### includes/Frontend/Activation.php:70 · Media · attivazione dell'accesso (righe 70 e 161)
- Ora: «Il tuo accesso è già attivo: entra con la tua email e la tua password; se l'hai dimenticata, nella pagina di accesso puoi riceverne una nuova.» · «Se la dimentichi, potrai riceverne una nuova per email.»
- Proposta: «Il tuo accesso è già attivo: entra con email e password. Se hai dimenticato la password, premi «Password dimenticata?» nella pagina di accesso.» · «Se la dimentichi, potrai sceglierne una nuova con un link via email.»
- Perché: per email non arriva una password ma un link per sceglierla; la frase lunga si spezza e nomina il pulsante da premere.

### includes/Frontend/Application.php:29 · Media · domanda di adesione, conferma
- Ora: «Domanda ricevuta. Ti abbiamo scritto all'email indicata (controlla anche la posta indesiderata): la segreteria la esamina e ti risponde.»
- Proposta: «Domanda ricevuta: la segreteria la esamina e ti risponde. Intanto ti abbiamo scritto all'email indicata (controlla anche la posta indesiderata).»
- Perché: «la esamina» arriva dopo l'email e la parentesi, e sembra riferirsi all'email invece che alla domanda.

### includes/Frontend/Application.php:126 · Bassa · domanda di adesione
- Ora: « Nell'email trovi anche il link per pagare subito la quota online; se la domanda non viene accettata ti viene restituita.»
- Proposta: « Nell'email trovi anche il link per pagare subito la quota online; se la domanda non è accettata, te la rimborsiamo.»
- Perché: due passivi di fila («viene… viene»).

### includes/Frontend/Application.php:156 · Media · domanda di adesione, parte del genitore (righe 156-157)
- Ora: «Hai meno di 18 anni? Indica un genitore o tutore» · «Se sei minorenne l'iscrizione la fa un genitore o tutore: compila questi dati. Se hai 18 anni o più, ignora questa parte.»
- Proposta: «Per chi ha meno di 18 anni: genitore o tutore» · «La domanda di un minorenne la presenta un genitore o tutore: qui vanno i suoi dati. Per i maggiorenni questa parte resta vuota.»
- Perché: il «tu» è il minore, ma chi compila è il genitore: non si capisce a chi parla la frase né di chi sono «questi dati».

### includes/Frontend/Application.php:173 · Bassa · domanda di adesione, consenso alle immagini
- Ora: «Acconsento alla pubblicazione di foto e video dell'attività (facoltativo; per un minore lo dichiara il genitore o tutore, che se ne assume la responsabilità anche verso l'altro genitore).»
- Proposta: «Acconsento alla pubblicazione di foto e video dell'attività (facoltativo). Per un minore lo dichiara il genitore o tutore, che ne risponde anche verso l'altro genitore.»
- Perché: oltre 25 parole con una parentesi lunga; due frasi si leggono meglio dal telefono.

### includes/Frontend/Application.php:208 · Bassa · pagina «Pagamento della quota»
- Ora: «Il link non è valido.»
- Proposta: «Il link non è valido: riaprilo dall'email o scrivi alla segreteria.»
- Perché: l'errore non dice come rimediare (le altre due frasi della stessa riga lo fanno).

### includes/Frontend/BlockFViews.php:116 · Bassa · area soci, convenzioni
- Ora: «Per farti riconoscere mostra all'esercente questa riga (o la tua tessera digitale): »
- Proposta: «Per farti riconoscere mostra questa riga (o la tessera digitale): »
- Perché: «esercente» è burocratese e non serve.

### includes/Frontend/CreditsFront.php:129 · Media · pagina che si apre inquadrando il QR dei crediti
- Ora: «Questo codice non corrisponde a nessun addebito.»
- Proposta: «Questo QR non corrisponde a nessun pagamento in crediti.»
- Perché: «addebito» è gergo bancario; il titolo parla di «QR», la frase di «codice».

### includes/Frontend/CreditsFront.php:151 · Bassa · pagina del QR dei crediti
- Ora: «Crediti insufficienti. <a …>Ricarica dalla tua area</a> e inquadra di nuovo il QR.»
- Proposta: «Non hai abbastanza crediti. <a …>Ricarica dalla tua area soci</a> e inquadra di nuovo il QR.»
- Perché: tono da sportello; «area soci» come nel resto.

### includes/Frontend/CreditsFront.php:172 · Media · area soci, «I miei crediti»
- Ora: «Spendi i crediti nelle attività e nelle risorse che li accettano. Dal vivo basta inquadrare il QR che ti mostra lo staff con la fotocamera del telefono e confermare.»
- Proposta: «Spendi i crediti nelle attività e nelle risorse che li accettano. Dal vivo inquadra con il telefono il QR che ti mostra lo staff, poi conferma.»
- Perché: così com'è sembra che sia lo staff a mostrare il QR «con la fotocamera».

### includes/Frontend/FirstAccess.php:81 · Bassa · email «Primo accesso» al socio
- Ora: «Apri il link, scegli la tua password e poi entra con questa email. Il link vale 24 ore: se scade, ripeti il \"Primo accesso\" dal sito.»
- Proposta: «Apri il link, scegli la tua password e poi entra con questo indirizzo email. Il link vale 24 ore: se scade, ripeti il «Primo accesso» dal sito.»
- Perché: «questa email» si legge come «questo messaggio»; virgolette standard.

### includes/Frontend/FirstAccess.php:174 · Media · email al vecchio indirizzo dopo il cambio
- Ora: «su richiesta, l'email del tuo accesso[ a …] è stata cambiata: da questo indirizzo non entrerai più.\n\nSe non l'hai chiesta tu, scrivi subito alla segreteria.»
- Proposta: «la segreteria ha cambiato, su tua richiesta, l'email del tuo accesso[ a …]: con questo indirizzo non potrai più entrare.\n\nSe non l'hai chiesto tu, scrivi subito alla segreteria.»
- Perché: non si capisce chi ha chiesto né chi ha cambiato; «da questo indirizzo non entrerai più» è ambiguo.

### includes/Frontend/FirstAccess.php:187 · Media · pagina «Primo accesso» (iscrizione su presentazione)
- Ora: «L'accesso è riservato a chi è già iscritto: l'iscrizione si fa su presentazione, rivolgendosi alla segreteria.»
- Proposta: «L'accesso è riservato a chi è già socio: si diventa soci su presentazione, rivolgendosi alla segreteria.»
- Perché: per il glossario «iscritto» è solo «iscritto a un corso»; qui si parla di soci.

### includes/Frontend/MemberLoginPage.php:179 · Bassa · pagina «Il tuo accesso»
- Ora: «Password salvata. Gli altri dispositivi dovranno rientrare.»
- Proposta: «Password salvata. Sugli altri dispositivi dovrai entrare di nuovo.»
- Perché: non sono i dispositivi a «rientrare», è il socio.

### includes/Frontend/MemberLoginPage.php:277 · Media · scelta della password dopo un ripristino
- Ora: «Il sito è stato ripristinato da una copia di sicurezza: per la tua sicurezza scegli una nuova password prima di continuare.»
- Proposta: «Dopo un intervento tecnico sul sito ti chiediamo, per sicurezza, di scegliere una nuova password.»
- Perché: «copia di sicurezza… per la tua sicurezza» nella stessa frase; al socio il dettaglio tecnico non serve e può allarmare.

### includes/Frontend/MemberLoginPage.php:282 · Bassa · scelta della prima password
- Ora: «Prima di continuare scegli la tua password: la userai per entrare ogni volta (potrai sempre usare anche il link che ti arriva per email).»
- Proposta: «Prima di continuare scegli la tua password: la userai per entrare. Se preferisci, potrai sempre entrare anche con un link via email.»
- Perché: «il link che ti arriva» fa pensare a un invio automatico, mentre va chiesto con «Mandami un link».

### includes/Frontend/MemberLoginPage.php:300 · Bassa · pagina «Il tuo accesso»
- Ora: «Sei dentro»
- Proposta: «Accesso riuscito»
- Perché: troppo colloquiale per un titolo; la proposta resta senza maschile/femminile.

### includes/Frontend/MemberLoginPage.php:326 · Media · schermata «Password dimenticata?» (righe 326-327, titolo alla 330, frase alla 281)
- Ora: «Reimposta la password» · «Scrivi la tua email: ti mandiamo un link che ti fa scegliere una nuova password.» · «Mandami il link per reimpostarla» · (281) «Hai chiesto di reimpostare la password: scegline una nuova per continuare.»
- Proposta: «Password dimenticata» · «Scrivi la tua email: ti mandiamo un link per sceglierne una nuova.» · «Mandami il link» · (281) «Hai chiesto una nuova password: sceglila qui per continuare.»
- Perché: «reimpostare» è gergo informatico; il link che porta qui si chiama «Password dimenticata?», la pagina deve avere lo stesso nome.

### includes/Frontend/MemberLoginPage.php:358 · Media · pagina «Accesso soci»
- Ora: «Con «Mandami un link» entri senza password: riceverai in email un link valido [N] minuti, con quello potrai entrare.»
- Proposta: «Con «Mandami un link» entri senza password: ti arriva per email un link che vale [N] minuti.»
- Perché: «entri… potrai entrare» dice due volte la stessa cosa.

### includes/Frontend/PayReturn.php:34 · Media · ritorno dal pagamento online (identica alla riga 49)
- Ora: «Non è stato possibile verificare il pagamento. Se hai pagato, lo registreremo appena Stripe o PayPal lo confermano.»
- Proposta: «Non siamo riusciti a verificare il pagamento. Se hai pagato non rifarlo: lo registriamo appena arriva la conferma.»
- Perché: impersonale, e non dice la cosa che conta (non pagare due volte). Sui nomi dei circuiti vedi «Da verificare».

### includes/Frontend/ProViews.php:220 · Media · area segreteria, incasso per più persone (righe 220-221)
- Ora: «Cassa per più persone» · «Una persona paga per sé e per altri: un solo incasso. Ogni riga è una persona (o un nuovo ospite) con una voce; la stessa persona può comparire in più righe. Gli ospiti nuovi sono ospiti di chi paga. Un evento si può pagare anche per un socio con la tessera scaduta; per un corso la tessera deve essere in regola (o rinnovata nello stesso incasso).»
- Proposta: «Incasso per più persone» · «Una persona paga per sé e per altri, in un solo incasso. Ogni riga è una persona (o un nuovo ospite, che diventa ospite di chi paga) con una voce; la stessa persona può stare in più righe. Se lasci vuoto l'importo vale quello previsto. Un evento si può pagare anche con la tessera non valida; per un corso serve la tessera valida o la quota nello stesso incasso.»
- Perché: per il glossario «cassa» sono i contanti, qui è un incasso (il pulsante dice «Registra l'incasso»); manca la spiegazione dell'importo vuoto, che oggi è affidata al segnaposto «standard» (riga 217).

### includes/Frontend/ProViews.php:231 · Bassa · ingressi agli eventi
- Ora: «Posti esauriti: nessun ingresso sul posto possibile.»
- Proposta: «Posti esauriti: non puoi aggiungere ingressi sul posto.»
- Perché: frase nominale, da modulo.

### includes/Frontend/ProViews.php:356 · Bassa · ingressi agli eventi, un socio paga per più soci
- Ora: «Scegli i soci per cui paga (può includere anche sé stesso): ognuno viene prenotato, l'importo del contributo d'ingresso è calcolato automaticamente e l'incasso è unico.»
- Proposta: «Scegli i soci per cui paga, anche sé stesso. Ognuno viene prenotato; il contributo si calcola da solo e l'incasso è unico.»
- Perché: oltre 25 parole con parentesi; si spezza e si accorcia.

### includes/Frontend/ResourcesFront.php:158 · Bassa · area soci, risorse
- Ora: « per turno»
- Proposta: « per fascia»
- Perché: nella stessa sezione l'unità si chiama «fascia» (righe 238, 323, 325): due parole per la stessa cosa. In alternativa «turno» ovunque, che è più parlato.

### includes/Frontend/ResourcesFront.php:176 · Bassa · area soci, titolo della sezione
- Ora: «Risorse»
- Proposta: «Spazi da prenotare»
- Perché: «risorse» è una parola da gestionale; il socio cerca il campo, la sala, il posto. Da valutare: il titolo vale per ogni associazione (in `ProViews.php` 91 la descrizione è «campi, sale, posti»).

### includes/Frontend/ResourcesFront.php:318 · Bassa · area soci, risorse
- Ora: «Pagamento online non attivo: prenota in segreteria»
- Proposta: «Pagamento online non attivo: prenota in segreteria.»
- Perché: manca il punto finale.

### includes/Frontend/ResourcesFront.php:333 · Media · area soci, pulsanti di prenotazione
- Ora: «Prenota e pago in segreteria» · «Prenota e paga ora» · «Paga con i crediti»
- Proposta: «Prenota e paga in segreteria» · «Prenota e paga ora» · «Prenota e paga con i crediti»
- Perché: «prenota e pago» mescola due persone del verbo; il terzo pulsante prenota anche lui ma non lo dice.

### includes/Frontend/ResourcesGate.php:79 · Bassa · pagina del QR di ingresso
- Ora: «Non valida»
- Proposta: «QR non valido»
- Perché: il titolo da solo non dice cosa non è valido; le altre pagine di verifica usano «QR non valido».

### includes/Frontend/Restrict.php:416 · Bassa · editor di WordPress, riquadro «Accesso»
- Ora: «Vedono il contenuto gli iscritti (o prenotati) a una qualsiasi delle attività scelte e chi le tiene.»
- Proposta: «Lo vede chi è iscritto o prenotato ad almeno una delle attività scelte, e chi ne è referente.»
- Perché: «chi le tiene» è vago; nell'area soci la stessa figura si chiama «referente».

### includes/Frontend/Shortcodes.php:29 · Bassa · scelta della vista (editor, blocchi, widget)
- Ora: «Area tesoriere (incassi, spese, iscrizioni, vendita eventi)»
- Proposta: «Area tesoriere (incassi, spese, nuovi soci, ingressi sul posto)»
- Perché: «vendita» è un termine commerciale che il glossario evita; «iscrizioni» qui sono i nuovi soci.

### includes/Frontend/Views.php:97 · Media · schede di corsi ed eventi
- Ora: « · ospiti: uguale»
- Proposta: « · ospiti: stesso contributo»
- Perché: «Soci 10,00 € · ospiti: uguale» non si capisce a colpo d'occhio.

### includes/Frontend/Views.php:117 · Media · finestra di conferma, tutta l'area soci (anche `ResourcesFront.php` 145; `CreditsFront.php` 222; `assets/frontend.js` 6 e 14)
- Ora: «Confermi?»
- Proposta: una domanda per azione, passata dal modulo: «Annullare la prenotazione?» (`Views.php` 365, 1405; `ResourcesFront.php` 190) · «Annullare l'ingresso?» (`Views.php` 846) · «Inviare l'avviso a tutti?» (`Views.php` 919) · «Uscire dalla lista d'attesa?» (`Views.php` 1417; `ResourcesFront.php` 253) · «Confermare il voto?» (`BlockBViews.php` 53) · «Delegare questo socio?» / «Revocare la delega?» (`AssembliesFront.php` 42, 40) · «Ritirare la richiesta?» (`RefundsFront.php` 43) · «Annullare l'addebito?» (`CreditsFront.php` 200) · «Registrare l'incasso e l'ingresso?» (`ProViews.php` 253, 358).
- Perché: «Confermi?» da solo non dice cosa succede premendo OK; da telefono, con più pulsanti vicini, è facile sbagliare.

### includes/Frontend/Views.php:179 · Media · area soci, prima della scelta della password
- Ora: «<h3>Scegli la tua password</h3><p>Per continuare devi scegliere la tua password.</p>» + pulsante «Scegli la password»
- Proposta: «<h3>Scegli la tua password</h3><p>La userai per entrare le prossime volte.</p>» + pulsante «Scegli la password»
- Perché: la stessa frase tre volte in tre righe.

### includes/Frontend/Views.php:187 · Media · area soci
- Ora: «Servizio temporaneamente sospeso. Contatta l'associazione.»
- Proposta: «L'area soci è temporaneamente sospesa. Per informazioni scrivi alla segreteria.»
- Perché: «servizio» è generico e freddo; non dice cosa è sospeso né a chi rivolgersi.

### includes/Frontend/Views.php:193 · Media · area soci
- Ora: «Il tuo account non è collegato a una scheda socio. Contatta l'associazione.»
- Proposta: «Il tuo accesso non è collegato a nessun socio. Scrivi alla segreteria.»
- Perché: «account» e «scheda socio» sono parole della bacheca, non del socio.

### includes/Frontend/Views.php:213 · Alta · tessera nell'area soci (righe 213 e 228)
- Ora: «Tessera scaduta»
- Proposta: «Tessera non valida»
- Perché: l'etichetta compare anche per un socio sospeso o uscito, la cui tessera non è scaduta; la verifica con il QR (`CardVerify.php` 75) dice già «Tessera non valida».

### includes/Frontend/Views.php:264 · Media · area soci, «Le mie attività»
- Ora: «(credito [importo])»
- Proposta: «(hai versato [importo] in più)»
- Perché: nel Pro «crediti» è un'altra cosa (il saldo da spendere): stessa parola per due concetti.

### includes/Frontend/Views.php:319 · Media · area soci, «Le mie attività»
- Ora: «Non sei ancora iscritto a nessuna attività.»
- Proposta: «Non hai ancora corsi né prenotazioni.»
- Perché: la sezione mostra corsi ed eventi; per il glossario «iscritto» vale solo per i corsi. Più corta.

### includes/Frontend/Views.php:353 · Bassa · area soci, biglietto QR
- Ora: «Mostralo all'ingresso: si vede se la prenotazione è valida e se il contributo è versato.»
- Proposta: «Mostralo all'ingresso: conferma la prenotazione e il pagamento.»
- Perché: impersonale e lungo.

### includes/Frontend/Views.php:382 · Bassa · area soci, «Cedi il posto»
- Ora: «Se il nuovo partecipante ha un contributo diverso (ad esempio un ospite) la differenza va integrata.»
- Proposta: «Se chi prende il posto ha un contributo più alto (per esempio un ospite), paga la differenza.»
- Perché: «va integrata» è burocratese; solo la differenza in più si paga (vedi `Actions.php` 361).

### includes/Frontend/Views.php:391 · Bassa · area soci, «Ultimi pagamenti online»
- Ora: «in registrazione»
- Proposta: «in corso»
- Perché: stato poco chiaro per chi ha appena pagato.

### includes/Frontend/Views.php:469 · Bassa · area segreteria (righe 469 e 471)
- Ora: « da approvare: si approvano dalla <a …>Bacheca</a>.» · «Da qui si arriva alle funzioni di gestione.»
- Proposta: « da approvare nella <a …>Bacheca</a>.» · «Da qui apri le pagine di gestione.»
- Perché: «approvare… si approvano» ripetuto; impersonale.

### includes/Frontend/Views.php:495 · Media · area segreteria, nuovo socio (righe 495-496)
- Ora: «Nuova iscrizione» · «Registra un nuovo socio: bastano nome e cognome, i dati restanti si completano dopo. La quota si incassa qui sotto.» · pulsante «Iscrivi»
- Proposta: «Nuovo socio» · «Bastano nome e cognome: gli altri dati li completi dopo. La quota associativa la incassi qui sotto.» · pulsante «Aggiungi il socio»
- Perché: «iscrizione/iscrivi» per il glossario riguarda i corsi; la bacheca chiama la stessa cosa «Nuovo socio» (riga 465).

### includes/Frontend/Views.php:537 · Bassa · area segreteria, «Incassa»
- Ora: «Per le quote l'anno viene calcolato automaticamente.»
- Proposta: «Per la quota associativa l'anno fiscale si calcola da solo.»
- Perché: «quote» da solo è ambiguo e non dice quale anno; passivo.

### includes/Frontend/Views.php:662 · Media · area soci, «I miei figli»
- Ora: «Sei il tutore di queste persone: vedi la loro tessera e le attività, le prenoti agli eventi e paghi per loro. Le email che li riguardano arrivano anche a te.»
- Proposta: «Sei il genitore o tutore di queste persone: vedi tessera e attività, le prenoti agli eventi e paghi per loro. Le email che le riguardano arrivano anche a te.»
- Perché: «le prenoti» e poi «li riguardano» nella stessa frase; il titolo dice «figli», il testo solo «tutore».

### includes/Frontend/Views.php:667 · Bassa · area soci, figlio diventato maggiorenne
- Ora: «Chiedi alla segreteria di invitarlo; finché il collegamento resta, continui a vedere i suoi dati.»
- Proposta: «Chiedi alla segreteria di invitarlo; finché resta collegato a te, continui a vedere i suoi dati.»
- Perché: «il collegamento» qui non è un link (il glossario usa «link» per quello) e non si capisce tra chi.

### includes/Frontend/Views.php:702 · Media · area soci, «Completa i tuoi dati» (righe 702-703)
- Ora: «Per usare l'area riservata mancano ancora: <strong>[…]</strong>.» … « Li trovi nel riquadro «Il mio profilo» qui sotto.»
- Proposta: «Mancano ancora: <strong>[…]</strong>.» … « Inseriscili in «Il mio profilo», qui sotto.»
- Perché: «Li trovi» è sbagliato (i dati mancano, vanno inseriti); via «area riservata».

### includes/Frontend/Views.php:707 · Bassa · area soci, «Il mio profilo»
- Ora: «Telefono»
- Proposta: «Cellulare»
- Perché: lo stesso campo si chiama «Cellulare» in attivazione, primo accesso, adesione e ospiti.

### includes/Frontend/Views.php:846 · Media · ingressi agli eventi (anche `TicketVerify.php` 89; `ResourcesGate.php` 102 e 125; `Actions.php` 450)
- Ora: «Annulla» · «Annulla la registrazione» · «Togli l'ingresso» · «Ingresso tolto.» · «Registrazione annullata: »
- Proposta: «Annulla l'ingresso» sui tre pulsanti · «Ingresso annullato.» · «Ingresso annullato: »
- Perché: la stessa azione ha tre nomi; «Annulla» da solo, accanto a una prenotazione, sembra annullare la prenotazione.

### includes/Frontend/Views.php:864 · Alta · ingressi agli eventi, riepilogo e filtri (righe 864 e 870)
- Ora: «Contributo da versare» · «Da registrare»
- Proposta: «Devono ancora pagare» · «Non entrati»
- Perché: sotto «Contributo da versare» c'è un numero di persone, non un importo: si legge come «3 euro». «Da registrare» non dice che si tratta di chi non è ancora entrato.

### includes/Frontend/Views.php:891 · Bassa · ingressi agli eventi, nota sull'ospite
- Ora: « — da invitare a iscriversi»
- Proposta: «: invitalo a diventare socio»
- Perché: trattino come inciso; «iscriversi» qui vuol dire diventare socio.

### includes/Frontend/Views.php:918 · Media · «Invia un avviso agli iscritti»
- Ora: «Arriva per email a chi è iscritto[ a questa data] (ora [N] persone; gli ospiti senza email lo ricevono tramite il socio che li ospita) e resta nella loro bacheca. Usalo per cambi dell'ultimo momento.»
- Proposta: «Arriva per email a [N] persone[ di questa data] e resta tra gli «Avvisi» della loro area soci. Per gli ospiti senza email lo riceve il socio che li ospita. Usalo per i cambi dell'ultimo momento.»
- Perché: frase di oltre 30 parole con parentesi; «bacheca» è il nome della pagina della segreteria, mentre per il socio la sezione si chiama «Avvisi».

### includes/Frontend/Views.php:1052 · Media · area soci, «Le attività che gestisci»
- Ora: «Non sei referente di nessuna attività dell'anno delle attività in corso.»
- Proposta: «Non sei referente di nessuna attività di quest'anno.»
- Perché: «attività dell'anno delle attività» è un inciampo.

### includes/Frontend/Views.php:1406 · Media · elenco delle date di un evento
- Ora: «non annullabile (gestiscila nella tua area)»
- Proposta: «non si può più annullare: vedi la tua area soci»
- Perché: se non si può annullare non è chiaro cosa «gestire»; nell'area soci il socio trova il motivo e, se previsto, «Cedi il posto».

### includes/Frontend/Views.php:1441 · Media · elenco delle date di un evento
- Ora: «Lista d'attesa»
- Proposta: «Metti in lista d'attesa»
- Perché: un pulsante con solo un nome non dice cosa fa; fa coppia con «Esci dalla lista».

### includes/Frontend/Views.php:1488 · Bassa · elenco delle date di un evento
- Ora: «Prenotati»
- Proposta: «Prenota»
- Perché: lo stesso pulsante prenota anche un ospite o un figlio (menu «Chi prenoti»).

### includes/Frontend/Views.php:1491 · Alta · pagine pubbliche degli eventi e area soci
- Ora: «La tessera non è valida: per prenotarti rinnova la quota associativa (» + «apri «Pagamenti» qui sopra o » + «rivolgiti alla segreteria).»
- Proposta: «La tessera non è valida: per prenotarti rinnova la quota associativa » + «dalla tua area soci (sezione «Pagamenti») o » + «in segreteria.»
- Perché: sulla pagina di un evento «Pagamenti» non è «qui sopra»: è nell'area soci. Meglio ancora riusare `Actions::renew_where()`, nata per dire la stessa frase ovunque.

### includes/Frontend/Views.php:1612 · Bassa · pagina pubblica di un corso
- Ora: «Come iscriversi»
- Proposta: «Come iscriverti»
- Perché: si dà del tu, come nel resto della pagina.

### includes/Frontend/Views.php:1635 · Media · pagina pubblica di un corso
- Ora: «La segreteria conferma la richiesta; l'iscrizione parte dal mese corrente e il contributo segue le regole del corso.»
- Proposta: «Dopo la conferma della segreteria sei iscritto da questo mese; il contributo è quello indicato per il corso.»
- Perché: «segue le regole del corso» non dice niente a chi legge.

### includes/Frontend/Elementor/ViewWidget.php:38 · Media · impostazioni del widget e del blocco (anche `assets/blocks.js` 36)
- Ora: «Anno sociale (es. 2025/2026; vuoto = in corso)» · in `blocks.js`: «Anno sociale (es. 2025/2026, vuoto = in corso)»
- Proposta: «Anno delle attività (es. 2025/2026; vuoto = in corso)» in entrambi
- Perché: dalla 1.3.30 corsi ed eventi seguono l'«anno delle attività»; in più le due etichette differiscono per la punteggiatura.

### includes/Frontend/Elementor/ViewWidget.php:52 · Media · impostazioni del widget e del blocco (anche riga 56; `assets/blocks.js` 46 e 52)
- Ora: «Solo questa categoria (indirizzo, facoltativo)»
- Proposta: «Solo questa categoria (il nome che ha nel suo indirizzo web; facoltativo)»
- Perché: «indirizzo» da solo fa pensare a via e numero; più lunga, ma senza la precisazione non si sa cosa scrivere.

### includes/Frontend/Elementor/ViewWidget.php:59 · Bassa · impostazioni del widget e del blocco (anche `assets/blocks.js` 61 e 69)
- Ora: «Titolo sopra la vista (es. I nostri prossimi eventi)» · «Titolo sopra il pezzo (facoltativo)»
- Proposta: «Titolo (es. I nostri prossimi eventi)» · «Titolo (facoltativo)»
- Perché: «vista» e «pezzo» sono parole da programmatore; il campo si capisce senza.

### assets/admin.js:144 · Alta · bacheca, scheda «Nuovo socio» (nota sotto il tipo di socio)
- Ora: «Socio ordinario: la tessera dura un anno (come l'anno sociale), si rinnova con la quota associativa. Email facoltativa: con l'email ha subito l'accesso all'area riservata.»
- Proposta: «Socio ordinario: la tessera vale per l'anno fiscale e si rinnova con la quota associativa. Email facoltativa: con l'email ha subito l'accesso all'area soci.»
- Perché: dalla 1.3.30 la tessera segue l'anno fiscale, non l'anno sociale: la nota dice il contrario della regola.

### assets/admin.js:193 · Media · bacheca, nuova attività (nota sotto il tipo)
- Ora: «Corso: i soci si iscrivono per mesi e pagano una quota mensile (anche gratuita).»
- Proposta: «Corso: i soci si iscrivono per mesi e pagano una mensilità (anche gratuita).»
- Perché: per il glossario «quota» è solo la quota associativa; il contributo di un mese di corso è la «mensilità».

### assets/admin.js:195 · Media · bacheca, nuova attività (nota sotto il tipo)
- Ora: «Evento ricorrente: molte date (le aggiungi dopo la creazione); ci si iscrive al singolo evento e si paga per ogni evento.»
- Proposta: «Evento ricorrente: molte date (le aggiungi dopo averlo creato); ci si prenota e si paga data per data.»
- Perché: a un evento ci si «prenota», non ci si «iscrive» (glossario); «evento» ripetuto tre volte.

### assets/admin.js:554 · Media · bacheca, incasso (righe 554-555; il testo principale è in `includes/Admin/IncomePage.php` 154-155)
- Ora: «⚠ il socio ha già la tessera {anno}: l'incasso non verrà salvato finché c'è questa voce» · «⚠ il socio ha già la tessera: l'incasso non verrà salvato finché c'è questa voce»
- Proposta: «⚠ il socio ha già la tessera {anno}: togli questa voce per salvare l'incasso» · «⚠ il socio ha già la tessera: togli questa voce per salvare l'incasso»
- Perché: dice cosa fare invece di cosa non succederà; più corta.

### wordpress/readme-portale.txt:2 · Media · scheda del plugin scaricato dal portale (inglese)
- Ora: «it sends a request to https://associazionesemplice.it/api/plugin/update, at most every 12 hours or more (24 hours by default; Settings - Limits and thresholds lets you choose from 12 to 168 hours)»
- Proposta: «it sends a request to https://associazionesemplice.it/api/plugin/update no more than once every 24 hours (you can choose from 12 to 168 hours in Settings - Limits and thresholds)»
- Perché: «at most every 12 hours or more» si contraddice da solo; l'intero paragrafo è un blocco unico di oltre 250 parole e conviene spezzarlo in 4-5 frasi brevi (cosa invia, cosa non invia, come si spegne, cosa cambia con il Pro).

### wordpress/associazionesemplice/readme.txt:23 · Bassa · scheda su WordPress.org (inglese)
- Ora: «* **Members' area** on the site, with notices, the rules to accept, the card and the events and courses, through shortcodes, blocks and Elementor widgets.»
- Proposta: «* **Members' area** on the site: notices, rules to accept, membership card, events and courses. Available as shortcodes, blocks and Elementor widgets.»
- Perché: catena di «the… and the… and», difficile da leggere; nessuna promessa in più, resta conforme a WordPress.org.

### wordpress/associazionesemplice-pro/readme.txt:14 · Media · scheda del Pro (inglese)
- Ora: «Associazione Semplice Pro adds to Associazione Semplice (required) the features that go beyond the basics: online payments with Stripe and PayPal, several accounts and funds, accounting and reports, insurance and attendance registers, mass emails, installable app with notifications, Apple and Google Wallet cards, receipts in PDF, several member levels, VAT and customisation of every text and language.»
- Proposta: «Associazione Semplice Pro adds the advanced features to Associazione Semplice (required):» seguito da un elenco puntato come nel readme del gratuito: «* Online payments (Stripe, PayPal, SumUp, Satispay)» · «* Several accounts and funds, accounting and reports, VAT» · «* Insurance and attendance registers» · «* Mass emails, installable app with notifications» · «* Apple and Google Wallet cards, PDF receipts» · «* Several member levels» · «* Every text and language can be customised»
- Perché: una frase sola di 60 parole; la sezione «External services» elenca quattro circuiti di pagamento, la descrizione ne cita due.

## Da verificare (sospetti errori di fatto, non riscritti)

- **Errore nel codice, non nel testo** — `includes/Frontend/CreditsFront.php:148`: `AssociazioneSempliceCsrf::field(…)` senza barre rovesciate (altrove è `\AssociazioneSemplice\Csrf::field`). Se è davvero così, la pagina «Pagare con i crediti?» va in errore proprio quando il socio ha crediti sufficienti.
- **«Troppi tentativi: riprova tra qualche minuto.»** (`Activation.php` 193; `Application.php` 78 e 203; `FirstAccess.php` 207): il conteggio si rinnova a ogni tentativo e dura un'ora (`HOUR_IN_SECONDS`), quindi «qualche minuto» può non bastare. `MemberLoginPage.php` 28 dice infatti «Aspetta un'ora e riprova».
- **«Altrimenti ti contatta la segreteria su WhatsApp.»** (`FirstAccess.php` 22-23): con l'iscrizione «su presentazione» la richiesta di chi non è riconosciuto non viene registrata (righe 149-151), quindi nessuno lo contatterà. La risposta deve restare uguale per tutti, ma così promette una cosa che non succede.
- **«…appena Stripe o PayPal lo confermano.»** (`PayReturn.php` 34 e 49): il readme del Pro elenca anche SumUp e Satispay; chi ha pagato con quelli legge due nomi che non lo riguardano.
- **«Ricarica dalla tua area»** (`CreditsFront.php` 151): il link porta alla home del sito (`home_url('/')`), non all'area soci.
- **Pulsante «Accedi» dei contenuti riservati** (`Restrict.php` 186-187) e rinvii al login di `Actions.php` 81, `ResourcesFront.php` 52, `CreditsFront.php` 52 e 117, `ResourcesGate.php`, `PayReturn.php`: usano `wp_login_url()`. Dove i soci non sono utenti WordPress finiscono sulla pagina che dice «Questa pagina è per la segreteria e per chi gestisce il sito». `Views::member_login_url()` esiste già per questo.
- **«riservato ai soci e volontari»** (`Views.php` 1349; `Restrict.php` 180): si può leggere «a tutti i soci, e ai volontari». Se la regola vale solo per i soci volontari, il testo corretto è «riservato ai soci volontari».
- **«Sempre rinnovata» sotto «Valida fino al»** (`Views.php` 208, 210; `CardVerify.php` 83): come valore di una data si legge male («Valida fino al: Sempre rinnovata»). Se per i soci fondatori è corretto dire «Senza scadenza», sarebbe più chiaro; `admin.js` 143 parla però di «scadenza a lungo termine».
- **Voto** (`BlockBViews.php` 53): se il voto non si può cambiare, la conferma dovrebbe dirlo («Confermare il voto? Non potrai cambiarlo.»).
- **Pubblico dichiarato**: l'intestazione «Description» del gratuito dice «associazioni del Terzo settore (APS ed ETS)», il readme «APS and other third-sector entities»; le ASD non compaiono in nessuno dei due. Se il plugin si rivolge anche a loro, le due schede vanno allineate.
- **`readme.txt` del gratuito, riga 45**: «it can be started again from the Tools menu». Da controllare che il percorso di menu sia ancora quello.
