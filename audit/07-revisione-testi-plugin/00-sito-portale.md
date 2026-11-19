# Sito (portale) · Pagine che parlano del plugin
File letti: 17 · Proposte: 49 (Alta 4, Media 27, Bassa 18)

Letti: `portale/content/plugin.md`, `portale/content/confronto.md`, `portale/src/Site.php` (descrizioni, funzioni, domande, fasce), `portale/src/Demo.php`, `portale/src/Emails.php` (email delle licenze del plugin), e le viste `home`, `wordpress_section`, `wordpress_changelog`, `free`, `features`, `faq`, `pricing`, `_plans`, `buy`, `demo`, `account`, `login`, `layout`.
Non letti: pagine e email del gestionale SaaS, amministrazione del portale, testi legali.

## Difetti ricorrenti

- **Tre nomi per la stessa cosa: «piani», «versioni», «Free».** «Confronto tra le versioni», «Cosa cambia tra i piani», «le tre versioni», «versioni evolute», «Tutto il Free». Ma «versione» è anche il numero di rilascio (1.3.30, «Versioni precedenti»). Regola proposta: **piani** per Gratuito / Pro Contabile / Pro Fiscale; **versione** solo per i numeri di rilascio; mai «Free» nei testi italiani (resta il nome del piano dal pannello).
- **«Funzioni di base» e «funzioni del gratuito» usati a turno** (domande, email, confronto). Regola proposta: sempre «le funzioni del piano gratuito».
- **Quattro nomi per l'assistenza:** «supporto», «sito di supporto», «centro assistenza», «assistenza». Regola proposta: «supporto» per il sito, «scrivici dal supporto» per l'azione.
- **Tre nomi per l'amministrazione di WordPress:** «pannello di WordPress», «wp-admin», «Bacheca». Regola proposta: «bacheca di WordPress».
- **«Pro» con e senza articolo** («se hai già Pro», «Prova gratuita di Pro», «passi al Pro»). Regola proposta: sempre con l'articolo, «il Pro».
- **Nel confronto la descrizione ripete il titolo** in 44 righe («Più livelli di socio :: Più livelli di socio con quote diverse»). Regola proposta: la descrizione parte da ciò che il titolo non dice.
- **Elenco dei sistemi di pagamento diverso da pagina a pagina:** «Stripe, PayPal, WooCommerce» (`plugin.md:21`), «carta, PayPal e WooCommerce» (`pricing.php:18`), «Stripe, PayPal, SumUp, Satispay o WooCommerce» (`confronto.md:102`). Da allineare a un elenco solo.

## Proposte

### portale/views/home.php:8 · Media · pagina iniziale, pulsanti principali
- Ora: «Hai già un sito WordPress? Plugin» / «Non hai un sito? Gestionale SaaS»
- Proposta: «Ho un sito WordPress: vedi il plugin» / «Non ho un sito: vedi il gestionale»
- Perché: un pulsante dice cosa succede premendolo; «Plugin» da solo dopo una domanda non è un'azione.

### portale/views/home.php:36 · Alta · pagina iniziale, «Si parte in tre passi»
- Ora: «Inserisci email e dominio del sito: crei il tuo account, accedi al supporto e alle demo dei piani Pro e ricevi la chiave. Il plugin funziona anche senza.»
- Proposta: «Scrivi email e dominio del sito: ricevi la chiave ed entri nel supporto. Con l'account puoi anche provare gratis un piano Pro. Il plugin funziona anche senza chiave.»
- Perché: la demo è pubblica e non chiede account (lo dice la fascia di /domande: «provala senza registrarti»); ciò che l'account sblocca è la prova gratuita del Pro. «Anche senza» non diceva senza cosa.

### portale/views/home.php:43 · Bassa · pagina iniziale, piani
- Ora: «I piani Pro si rinnovano da soli e si disdicono in autonomia.»
- Proposta: «I piani Pro si rinnovano da soli e li disdici quando vuoi.»
- Perché: «in autonomia» è burocratese; altrove si dice già «quando vuoi».

### portale/views/free.php:5 · Alta · pagina «Ottieni la licenza gratuita»
- Ora: «Due dati e hai il tuo account: accesso al supporto, alle demo dei piani Pro e la chiave per i servizi collegati. Ti scriviamo un link per confermare l'email.»
- Proposta: «Bastano email e dominio: ricevi la chiave, entri nel supporto e puoi provare gratis un piano Pro. Ti mandiamo un link per confermare l'email.»
- Perché: «la chiave per i servizi collegati» non dice nulla; «demo dei piani Pro» confonde con la demo pubblica; «ti scriviamo un link» non è italiano corrente.

### portale/views/free.php:25 · Media · pagina «Ottieni la licenza gratuita», elenco a destra
- Ora: «Supporto e demo dei piani Pro» · «Con l'account entri nel supporto e provi le versioni evolute.»
- Proposta: «Supporto e prova del Pro» · «Con l'account scrivi al supporto e provi gratis un piano Pro.»
- Perché: «versioni evolute» è un quarto nome per i piani Pro.

### portale/content/plugin.md:7 · Media · pagina «Il plugin»
- Ora: «C'è una demo con il plugin gratuito e il Pro già installati e piena di dati inventati. Gli accessi di prova (segreteria e socio), i collegamenti e i ruoli del plugin sono nella pagina [Prova la demo](/prova).»
- Proposta: «La demo ha il plugin gratuito e il Pro già installati, con dati inventati. Accessi di prova, collegamenti e ruoli sono nella pagina [Prova la demo](/prova).»
- Perché: più corta; gli accessi oggi sono tre (c'è anche l'amministratore del plugin), quindi la parentesi è superata.

### portale/content/plugin.md:13 e portale/views/pricing.php:11 · Alta · pagina «Il plugin» e prezzi
- Ora: «anni solari» / «Anni solari: apertura e chiusura»
- Proposta: «anni fiscali» / «Anni fiscali: apertura e chiusura»
- Perché: il plugin e il confronto dicono «anno fiscale» (con mese di inizio a scelta): «solare» è sbagliato.

### portale/content/plugin.md:29 · Media · pagina «Il plugin», «Cosa contatta il plugin»
- Ora: un solo capoverso di 90 parole («Il gratuito non invia dati… Il Pro verifica la licenza una volta al giorno e controlla gli aggiornamenti di norma una volta al giorno con questo sito, inviando solo chiave, dominio e versione: i dettagli sono…»)
- Proposta:
  «Il plugin gratuito non invia dati dei soci né dell'associazione.
  - **Scaricato da WordPress.org:** si aggiorna da lì e a noi non arriva nulla.
  - **Scaricato da questo sito:** una volta al giorno chiede se c'è una versione nuova, inviando solo nome del plugin e versione. Si può spegnere dalle impostazioni.
  - **Il Pro:** una volta al giorno verifica la licenza e cerca aggiornamenti, inviando solo chiave, dominio e versione.

  I dettagli sono nell'[informativa sulla privacy](/privacy) e nei [termini di utilizzo](/termini).»
- Perché: stessi fatti, ma si leggono in dieci secondi; via il doppio «una volta al giorno… di norma una volta al giorno».

### portale/src/Site.php:48 · Media · scheda «Libro soci in ordine» (home e /funzioni)
- Ora: «Soci, ospiti e figure diverse, con tessera, quote e storico. Importi l'elenco che hai già da Excel o CSV.»
- Proposta: «Soci e ospiti, con tessera, quote e storico. Importi da Excel o CSV l'elenco che hai già.»
- Perché: «figure diverse» non dice nulla.

### portale/src/Site.php:50 · Media · scheda «Prima nota»
- Ora: «Entrate e uscite sempre al loro posto, con allegati e viste pronte per il bilancio.»
- Proposta: «Entrate e uscite in ordine di data, con scontrini e fatture allegati.»
- Perché: «viste» è gergo; «bilancio» promette ciò che il gratuito non fa (il rendiconto è del piano fiscale) e il glossario lo sconsiglia.

### portale/src/Site.php:52 · Media · scheda «Area riservata per i soci»
- Ora: «Ogni socio ha la sua pagina sul sito: tessera, pagamenti, ingressi. Nel menu del sito, non in wp-admin.»
- Proposta: «Ogni socio ha la sua pagina sul sito: tessera, pagamenti, ingressi. Ci arriva dal menu del sito, senza entrare nella bacheca di WordPress.»
- Perché: «wp-admin» è gergo da tecnici.

### portale/src/Site.php:60 · Alta · domanda «Devo pagare per usare Associazione Semplice?»
- Ora: «…La licenza gratuita è facoltativa: è legata al dominio del tuo sito e dà accesso al supporto e alle demo dei piani Pro. La ricevi appena confermi la tua email.»
- Proposta: «…La licenza gratuita è facoltativa: vale per il dominio del tuo sito, ti fa scrivere al supporto e provare gratis un piano Pro. Arriva appena confermi l'email.»
- Perché: stesso equivoco sulle «demo dei piani Pro».

### portale/src/Site.php:61 · Media · domanda «Cosa significa licenza legata a un dominio?»
- Ora: «La chiave funziona sul sito per cui l'hai richiesta. Se cambi indirizzo, lo aggiorni tu dall'area riservata, senza scrivere a nessuno. Dopo un cambio la chiave resta legata al nuovo dominio per N giorni; per un cambio sito urgente prima di allora ti aiuta l'assistenza. I sottodomini di prova (staging, test…) funzionano già con la stessa chiave.»
- Proposta: «La chiave funziona sul sito per cui l'hai chiesta, compresi i sottodomini di prova (staging, test…). Se cambi indirizzo lo aggiorni tu dall'area riservata. Dopo un cambio aspetti N giorni prima di farne un altro: se hai urgenza, scrivici dal supporto.»
- Perché: quattro frasi diventano tre, e l'attesa è detta in modo diretto.

### portale/src/Site.php:64 · Media · domanda «Cosa succede se un pagamento non va a buon fine?»
- Ora: «Hai alcuni giorni di tolleranza. I tuoi dati non vengono mai cancellati: se la licenza scade, tornano disponibili le funzioni di base.»
- Proposta: «Ti avvisiamo per email e hai alcuni giorni per sistemare il pagamento. Se la licenza scade restano le funzioni del piano gratuito: i tuoi dati non vengono cancellati.»
- Perché: dice cosa succede in ordine; «tornano disponibili le funzioni di base» sembrava un vantaggio.

### portale/src/Site.php:65 · Media · domanda «Dove stanno i dati dei miei soci?»
- Ora: «Sul tuo sito WordPress, non da noi. Il plugin gratuito non invia dati dei soci né dell'associazione (scaricato da questo sito, chiede solo una volta al giorno se c'è una versione nuova, con nome del plugin e versione; si può spegnere); il Pro verifica la licenza una volta al giorno e controlla gli aggiornamenti inviando solo chiave, dominio e versione.»
- Proposta: «Sul tuo sito WordPress, non da noi. Il plugin gratuito non invia dati dei soci né dell'associazione: se lo scarichi da questo sito chiede solo, una volta al giorno, se c'è una versione nuova (si può spegnere). Il Pro, una volta al giorno, verifica la licenza e cerca aggiornamenti inviando solo chiave, dominio e versione.»
- Perché: via la parentesi lunga con il punto e virgola dentro.

### portale/src/Site.php:66 · Bassa · domanda «Cosa succede ai dati se disdico o la licenza scade?»
- Ora: «Alla scadenza il plugin torna alle funzioni del gratuito»
- Proposta: «Alla scadenza restano le funzioni del piano gratuito»
- Perché: un termine solo in tutto il sito.

### portale/src/Site.php:85 · Bassa · fascia in fondo a /domande
- Ora: «La demo risponde meglio di qualsiasi spiegazione: provala senza registrarti.»
- Proposta: «Nella demo vedi tutto in funzione: provala senza registrarti.»
- Perché: l'originale sminuisce la pagina di domande appena letta.

### portale/views/pricing.php:7 · Media · prezzi, elenco del piano gratuito
- Ora: «Libro soci con nuovi iscritti, schede e numerazione»
- Proposta: «Libro soci con schede e numerazione progressiva»
- Perché: «con nuovi iscritti» non è una funzione.

### portale/views/pricing.php:42 · Bassa · prezzi, sottotitolo
- Ora: «I piani Pro si rinnovano da soli, ogni mese o ogni anno, e si disdicono quando vuoi dall'area riservata.»
- Proposta: «I piani Pro si rinnovano da soli, ogni mese o ogni anno, e li disdici quando vuoi dall'area riservata.»
- Perché: impersonale e seconda persona nella stessa frase.

### portale/views/pricing.php:84 · Bassa · prezzi, riga finale
- Ora: «Confronta tutte le funzioni delle tre versioni»
- Proposta: «Confronta tutte le funzioni dei tre piani»
- Perché: regola piani/versioni.

### portale/views/confronto.php:129, portale/views/layout.php:42 e 61, portale/views/_plans.php (riga finale), portale/src/Site.php:81 e 88 · Media · titolo e voci di menu del confronto
- Ora: «Confronto tra le versioni» · «Confronto versioni» · «Confronta le funzioni tra le versioni» · «Confronta le versioni»
- Proposta: «Confronto tra i piani» · «Confronto dei piani» · «Confronta le funzioni dei piani» · «Confronta i piani»
- Perché: regola piani/versioni; l'occhiello della stessa pagina dice già «Cosa cambia tra i piani».

### portale/views/buy.php:30 · Bassa · acquisto
- Ora: «Codice promo»
- Proposta: «Codice sconto»
- Perché: parola italiana e più chiara.

### portale/views/buy.php:48 · Bassa · acquisto, «Cosa succede dopo»
- Ora: «Trovi la chiave» · «Incolla la chiave nel plugin e le funzioni Pro si attivano.»
- Proposta: «Trovi la chiave» · «È nell'area riservata: la incolli nel plugin e le funzioni Pro si attivano.»
- Perché: mancava dove si trova.

### portale/views/demo.php:8 · Media · pagina «Prova la demo», apertura
- Ora: «Un sito WordPress vero con il plugin gratuito e il Pro già installati e pieno di dati inventati. Serve soprattutto a provare le funzioni Pro. Clicca, inserisci, cancella: non rompi niente. Non scrivere dati veri, perché tutto si azzera ogni notte alle 4, non partono email e le password non si possono cambiare.»
- Proposta: «Un sito WordPress vero, con il plugin gratuito e il Pro già installati e dati inventati. Clicca, inserisci, cancella: non rompi niente. Non scrivere dati veri: la demo è pubblica e si azzera ogni notte alle 4. Non partono email e le password non si cambiano.»
- Perché: «non scrivere dati veri perché… non partono email» non regge; il motivo vero è che la demo è pubblica.

### portale/views/demo.php:14 · Media · pagina «Prova la demo», riquadro
- Ora: «La demo si azzera ogni notte alle 4, ma con l'accesso «amministratore del plugin» puoi scaricare una copia dei tuoi dati da Copia di sicurezza («Scarica la copia dei dati») e ricaricarla il giorno dopo con «Ripristina dalla copia»: ritrovi tutto com'era.»
- Proposta: «Entra come «amministratore del plugin», apri **Copia di sicurezza** e premi «Scarica la copia dei dati». Il giorno dopo la ricarichi con «Ripristina dalla copia» e ritrovi tutto.»
- Perché: l'azzeramento è già scritto due righe sopra; i passi in ordine si seguono meglio.

### portale/views/demo.php:56 · Bassa · pagina «Prova la demo», «Dove andare»
- Ora: «I collegamenti sono tutti nella demo. Quelli «come socio» e «come segreteria» chiedono di aver fatto l'accesso con il ruolo giusto.»
- Proposta: «I collegamenti aprono la demo. Per quelli «come socio» e «come segreteria» entra prima con quel ruolo.»
- Perché: più diretto.

### portale/src/Demo.php:21 · Bassa · accesso «Segreteria»
- Ora: «Lavora nel pannello di WordPress.»
- Proposta: «Lavora nella bacheca di WordPress.»
- Perché: un nome solo per l'amministrazione.

### portale/src/Demo.php:80 e 95 · Media · tabella «Chi può fare cosa» e ruolo «Tesoriere»
- Ora: «vende» · «iscrive soci e vende gli eventi»
- Proposta: «iscrive e incassa» · «iscrive i soci e incassa i contributi degli eventi»
- Perché: un'associazione non «vende eventi» (l'audit 03 l'aveva già segnalato nel plugin).

### portale/src/Demo.php:93 · Bassa · ruolo «Segreteria»
- Ora: «Una persona del sito con il ruolo «Segreteria»: opera su soci, incassi, attività e registri, senza toccare le impostazioni.»
- Proposta: «Chi ha il ruolo «Segreteria»: lavora su soci, incassi, attività e registri, senza toccare le impostazioni.»
- Perché: «una persona del sito» e «opera su» sono freddi.

### portale/views/account.php:88 · Bassa · area riservata, «Scarica il plugin»
- Ora: «I pacchetti si scaricano solo da qui, con la licenza in regola. Carica lo zip da WordPress: Plugin → Aggiungi nuovo → Carica plugin. Per tornare indietro disattiva e sostituisci il plugin con la versione precedente (i dati restano).»
- Proposta: «I file del plugin si scaricano solo da qui, con la licenza in regola. Li carichi da WordPress: **Plugin → Aggiungi nuovo → Carica plugin**. Per tornare a una versione precedente carichi il suo zip: i dati restano.»
- Perché: «pacchetti» è gergo; «disattiva e sostituisci» non dice come.

### portale/views/account.php:153 · Bassa · area riservata, dominio bloccato
- Ora: «Per un cambio sito vero prima di quella data, scrivi all'assistenza.»
- Proposta: «Se devi cambiarlo prima, scrivici dal supporto.»
- Perché: «cambio sito vero» sottintende che gli altri siano finti.

### portale/views/account.php:169 · Media · area riservata, siti di prova
- Ora: «La licenza vale per il dominio registrato **X** e per tutti i suoi sottodomini e sottocartelle: puoi usarla anche su staging.X, test.X, clone.X oppure X/staging, con la stessa chiave e senza cambiare nulla.»
- Proposta: «La stessa chiave vale anche per i sottodomini e le sottocartelle di **X**, per esempio staging.X oppure X/staging.»
- Perché: due esempi bastano; il resto ripete.

### portale/views/account.php:170 · Media · area riservata, cambio dominio
- Ora: «Il vecchio dominio smette di funzionare al controllo successivo. Il cambio è libero, ma dopo averlo fatto la chiave resta legata al nuovo dominio per N giorni: poi puoi cambiarlo di nuovo.»
- Proposta: «Dopo il cambio il vecchio dominio smette di funzionare entro un giorno, e per N giorni non puoi cambiarlo di nuovo.»
- Perché: «al controllo successivo» è il punto di vista del programma, non del cliente.

### portale/views/wordpress_section.php:5 · Bassa · pagina /wordpress, scheda «Cos'è»
- Ora: «Una panoramica del plugin, di cosa include il gratuito e di cosa aggiunge il Pro: leggi la presentazione.»
- Proposta: «Cosa fa il plugin, cosa include il gratuito e cosa aggiunge il Pro: leggi la presentazione.»
- Perché: via la catena di «di cosa».

### portale/views/layout.php:61 · Media · piè di pagina
- Ora: due voci vicine, «Plugin WordPress» (porta a /wordpress) e «Il plugin» (porta a /plugin)
- Proposta: «Plugin WordPress» e «Cos'è il plugin»
- Perché: due etichette quasi uguali per due pagine diverse.

### portale/views/login.php:4 · Bassa · accesso arrivando dal supporto
- Ora: «Entra per scrivere al supporto: dopo l'accesso torni subito al centro assistenza. Serve per sapere a quale licenza si riferisce la richiesta.»
- Proposta: «Entra per scrivere al supporto: dopo l'accesso torni subito lì. Così sappiamo a quale licenza si riferisce la richiesta.»
- Perché: «supporto» e «centro assistenza» nella stessa frase sembrano due posti.

### portale/src/Emails.php:36 · Bassa · email «Abbonamento attivo»
- Ora: «Grazie!\nLa licenza {piano} per {dominio} è pronta e vale fino al {scadenza}.»
- Proposta: «Ciao,\ngrazie per l'acquisto. La licenza {piano} per {dominio} è pronta e vale fino al {scadenza}.»
- Perché: niente esclamativo; stesso saluto delle altre email.

### portale/src/Emails.php:41, 46, 51, 56, 61, 66, 71, 76, 81, 86 · Bassa · email delle licenze
- Ora: solo «Link di accesso» e «Conferma licenza gratuita» iniziano con «Ciao,»; le altre dieci partono senza saluto.
- Proposta: tutte iniziano con «Ciao,» (come fanno già quelle del gestionale con «Ciao {nome},»).
- Perché: tono uniforme e meno brusco.

### portale/src/Emails.php:46 · Media · email «Pagamento non riuscito»
- Ora: «Non siamo riusciti a incassare il rinnovo del piano {piano} per {dominio}.\nControlla il metodo di pagamento: la licenza resta valida fino al {scadenza}, poi tornano le funzioni di base. I tuoi dati non vengono cancellati.»
- Proposta: «Non siamo riusciti a incassare il rinnovo del piano {piano} per {dominio}. Di solito succede per una carta scaduta o un limite di spesa.\nControlla il metodo di pagamento dall'area riservata. La licenza resta valida fino al {scadenza}; dopo restano le funzioni del piano gratuito e i tuoi dati non vengono cancellati.»
- Perché: dice la causa probabile e dove rimediare (l'email del gestionale lo fa già).

### portale/src/Emails.php:56 · Media · email «Piano cambiato (upgrade)»
- Ora: «le nuove funzioni compaiono al prossimo controllo del plugin.»
- Proposta: «le nuove funzioni compaiono nel plugin entro un giorno.»
- Perché: «al prossimo controllo» è il punto di vista del programma. (Se nel plugin c'è un pulsante per controllare subito, va nominato qui.)

### portale/content/confronto.md · Media · tabella di confronto, 44 righe con descrizione che ripete il titolo
Righe 14, 15, 19, 20, 22, 39, 75, 78, 79, 81, 102, 103, 105, 106, 107, 109, 111, 113, 114, 118, 119, 120, 121, 127–130, 132–136, 147, 153–156, 165, 173–175, 180. Esempi di riscrittura:
- 19 · Ora: «Più livelli di socio :: Più livelli di socio con quote diverse» → «Più livelli di socio :: Ognuno con la sua quota»
- 20 · Ora: «Nucleo familiare con sconto :: Nucleo familiare con sconto per i familiari» → «Nucleo familiare :: Con sconto per i familiari»
- 75 · Ora: «Incasso sul posto agli eventi :: Incasso sul posto durante gli eventi» → solo «Incasso sul posto agli eventi» (senza descrizione)
- 81 · Ora: «Report di occupazione :: Report di occupazione delle risorse» → «Report di occupazione delle risorse» (senza descrizione)
- 102 · Ora: «Pagamenti online :: Pagamenti online con Stripe, PayPal, SumUp, Satispay o WooCommerce» → «Pagamenti online :: Con Stripe, PayPal, SumUp, Satispay o WooCommerce»
- 111 · Ora: «Fondi accantonati :: Fondi accantonati (per esempio rimborso dei volontari)» → «Fondi accantonati :: Somme già destinate a qualcosa, per esempio i rimborsi ai volontari»
- 121 · Ora: «Rimborsi ai volontari :: Rimborsi ai volontari: richiesta, approvazione, pagamento e registrazione in prima nota» → «Rimborsi ai volontari :: Richiesta, approvazione, pagamento e registrazione in prima nota»
- 127 · Ora: «IVA :: IVA su quote, attività, incassi e spese» → «IVA :: Su quote, attività, incassi e spese»
- 129 · Ora: «Rendiconto per cassa :: Rendiconto per cassa per il commercialista» → «Rendiconto per cassa :: Pronto per il commercialista»
- 147 · Ora: «Assemblee dei soci :: Assemblee dei soci: convocazione per email…» → «Assemblee dei soci :: Convocazione per email, presenze, deleghe tra soci, numero legale e verbale già compilato»
- 175 · Ora: «Lingue aggiuntive :: Lingue aggiuntive (inglese incluso)» → «Lingue aggiuntive :: Inglese incluso»

### portale/content/confronto.md:10 · Media · confronto, «Libro soci per anno fiscale»
- Ora: «Il libro soci segue l'anno fiscale e alla chiusura diventa definitivo (pubblicato e bloccato, non cambia più), prima è una bozza; ogni socio ha una matricola fissa e ogni riga un numero; «Emetti i documenti definitivi degli anni già chiusi» ricostruisce i libri passati; ogni libro si scarica in PDF e in CSV»
- Proposta: «È una bozza finché l'anno fiscale è aperto e diventa definitivo alla chiusura. Ogni socio ha una matricola fissa. I libri degli anni già chiusi si ricostruiscono con un pulsante; ogni libro si scarica in PDF e CSV»
- Perché: 56 parole con tre punti e virgola e una parentesi, in una cella di tabella.

### portale/content/confronto.md:15 · Media · confronto, «Accesso dei soci semplificato»
- Ora: «Accesso dei soci in una sola schermata, con email e password o con un link via email, e «Ricordami» per 30 giorni; senza utente WordPress (utente WordPress solo per chi ha un ruolo, o per tutti se lo scegli)»
- Proposta: «Una sola schermata: email e password oppure un link via email, con «Ricordami» per 30 giorni. Ai soci non serve un utente WordPress»
- Perché: la parentesi è un dettaglio da guida, non da tabella di vendita.

### portale/content/confronto.md:82 · Media · confronto, «Corsi ed eventi e risorse»
- Ora: «Corsi ed eventi e risorse :: Anche «fuori sede», con tempi di allestimento e fasce occupate solo dove ci sono prenotazioni»
- Proposta: «Risorse per corsi ed eventi :: Un corso o un evento occupa la sua sala o il suo campo, con i tempi di allestimento; può anche essere «fuori sede»»
- Perché: il titolo con due «e» non si capisce, e la descrizione parte da «Anche» senza dire cosa.

### portale/content/confronto.md:83 · Media · confronto, «Cambio di risorsa»
- Ora: «Cambiando la risorsa di un'attività già occupata nelle stesse date si vede cosa si sovrappone e il cambio non si applica; spuntando «Sposta sulla risorsa di prima ciò che si sovrappone», prenotazioni e altre attività passano alla risorsa liberata, tutto o niente»
- Proposta: «Se sposti un'attività su una risorsa già occupata vedi cosa si sovrappone. Con una spunta, prenotazioni e attività in conflitto passano alla risorsa liberata»
- Perché: istruzioni d'uso dentro una tabella di confronto; «tutto o niente» è gergo.

### portale/content/confronto.md:85 · Media · confronto, «Non rinnova»
- Ora: «eliminando un corso si rende la quota proporzionale delle lezioni non tenute»
- Proposta: «eliminando un corso si restituisce il contributo delle lezioni non tenute»
- Perché: per i corsi il glossario dice «contributo», non «quota»; «si rende» è desueto.

### portale/content/confronto.md:97 · Media · confronto, «Anni fiscali»
- Ora: «Anni fiscali: apertura e chiusura (solo amministratori), con il mese di inizio a scelta e l'avviso del nuovo anno da settembre; si chiudono in ordine; il mese di inizio dell'anno fiscale si blocca alla prima chiusura, anche dopo la riapertura»
- Proposta: «Anni fiscali :: Apertura e chiusura (solo amministratori), con mese di inizio a scelta. Si chiudono in ordine; dopo la prima chiusura il mese di inizio non si cambia più»
- Perché: tre frasi incollate con i punti e virgola.

### portale/content/confronto.md:190 e 199 · Bassa · confronto, licenza
- Ora: «Prova gratuita di Pro» · «(se hai già Pro)»
- Proposta: «Prova gratuita del Pro» · «(se hai già il Pro)»
- Perché: articolo uniforme.

### portale/content/confronto.md:193 · Bassa · confronto, nota finale
- Ora: «Con una licenza non in regola Pro torna alle funzioni di base, senza toccare i dati.»
- Proposta: «Se la licenza non è in regola restano le funzioni del piano gratuito: i dati non si toccano.»
- Perché: termine unico e frase più naturale.

## Da verificare (sospetti errori di fatto, non riscritti)

- **`portale/content/confronto.md:18`** dice «Tessera annuale che segue l'anno sociale» e **riga 119** «Report di gestione (conti e liquidità, anno sociale)»: dal 18/11 la tessera segue l'anno fiscale e a schermo non si dice più «anno sociale» per la tessera. Da riallineare alla 1.3.30.
- **`portale/content/plugin.md:10`**: «Si scarica da WordPress.org». Il plugin è già pubblicato su WordPress.org? Il confronto (riga 173) dice «finché non è su WordPress.org». Se non c'è ancora, la frase è falsa.
- **`portale/views/account.php:87`**: «Il gratuito si aggiorna caricando lo zip da qui; il Pro si aggiorna direttamente da WordPress.» Il confronto (riga 173) dice invece che anche il gratuito si aggiorna dalla Bacheca di WordPress. Una delle due è superata.
- **`portale/views/pricing.php:75` e `portale/views/_plans.php:24`**: il pulsante «Avvisami quando si può acquistare» porta al modulo della licenza gratuita e non registra alcun avviso. O si avvisa davvero, o il pulsante va chiamato «Intanto parti dal gratuito».
- **`portale/views/wordpress_section.php:9`**: «Requisiti, installazione e assistenza sono nelle domande frequenti»: nelle otto domande di `Site::faq()` non c'è nulla su requisiti e installazione (stanno nelle guide di supporto).
- **`portale/content/confronto.md:193`**: «nella sezione **Prezzi** della home». I prezzi completi sono nella pagina /prezzi, non in una sezione della home.
- **`portale/content/confronto.md:99`**: «Cassa «Prenota» (per data o per attività)»: chi non conosce il plugin non può capire cosa sia. Serve una descrizione dopo «::».
- **`portale/content/confronto.md:65`**: «Listini :: Quanti prezzi vuoi…»: il glossario dell'audit 03 sconsiglia «prezzo» e «listino» a favore di «contributo». Da decidere se la funzione cambia nome anche qui.
- **`portale/content/confronto.md:178`**: «Quota già pagata nell'anno» sta sotto «Personalizzazione e sicurezza dei dati»: è una regola degli incassi, il suo posto è «Soci e tessere» o «Cassa e conti».
- **`portale/src/Site.php:64`**: «Hai alcuni giorni di tolleranza»: se il numero è fisso conviene scriverlo.
- **Le due tabelle di confronto non sono più uguali**: `portale/content/confronto.md` e `supporto/database/guides/confronto.md` differiscono in molte righe (titoli con «::» solo nel portale, righe presenti in una sola delle due, «resta gratuito per sempre» contro «resta gratuito»). Se devono restare allineate, serve un passaggio di riallineo prima di applicare le proposte sopra a tutte e due.
