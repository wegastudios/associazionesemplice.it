# Plugin · Servizi, email e messaggi (file A–D)
File letti: 60 · Proposte: 51 (Alta 2, Media 31, Bassa 18)

Percorso comune dei file: `wordpress/associazionesemplice/includes/`. Dei 60 file, 14 non hanno testi per l'utente (AccessRequests, ActivationToken, Age, Areas, AttemptLimit, BackupSeal, BoardRole, BootState, CachePolicy, CardToken, ClientIp, Color, Download, e Access che ha solo commenti). Nelle citazioni «Ora» gli apostrofi sono scritti senza la barra di escape del PHP (`\'` → `'`); dove il testo è composto da pezzi e variabili, i pezzi sono separati da «…».

## Difetti ricorrenti
- **«(errore del database)» nei messaggi di salvataggio** (11 punti): gergo tecnico e nessuna indicazione su cosa fare. Regola proposta: «<Cosa> non salvato/a: riprova.» Vedi ActivityService.php:1198.
- **«anno sociale» per corsi ed eventi**: la regola dice «anno delle attività». Nel lotto sono 3 messaggi (ActivityCopy:99, ActivityService:319 e 385); in tutto il plugin «anno sociale» compare ancora circa 60 volte contro 23 «anno delle attività»: il passaggio è a metà.
- **«area riservata» al posto di «area soci»** (9 punti, quasi tutti nelle email ai soci): il glossario riserva «area riservata» all'account sul portale. Nel plugin i due nomi si dividono a metà (circa 123 contro 126): va scelto uno e usato ovunque.
- **«cancellare/cancellata» e «quota» fuori glossario**: «cancellata» per un'iscrizione che viene annullata (email al socio), «quota» per il contributo di un evento o di un corso (descrizioni in prima nota), «utile» per l'avanzo, «Disponibilità effettiva» per «Disponibilità reale».
- **Errori impersonali che non dicono cosa fare**: «Si tessera un socio attivo.», «Si copia solo in…», «Il certificato si registra per…», «Questo server non può…». Regola proposta: «Puoi … solo …» oppure causa + rimedio dopo i due punti.
- **Virgolette dritte "…" al posto di «…»** (14 punti, quasi tutti nei messaggi sui file caricati).
- **Maschile generico o doppia forma con la barra**: «sei convocato», «sei stato tu», «prenotato/a». Regola proposta: girare la frase in modo che non serva il genere.
- **«È arrivata l'email»**: il plugin sa di averla inviata, non che sia arrivata. Regola proposta: «Abbiamo inviato…».
- **Lineetta «—» come separatore nei testi** (elenchi dei promemoria alla segreteria, esiti): meglio i due punti. Negli oggetti delle email («… — Nome associazione») è coerente e può restare.
- **Freccia dei percorsi di menu**: quasi ovunque «→» (circa 130 volte), in 3 punti «›».

## Proposte

### wordpress/associazionesemplice/includes/ActivityCopy.php:99 · Media · bacheca (copia di un corso nel nuovo anno)
- Ora: «Scegli l'anno sociale del nuovo corso.»
- Proposta: «Scegli l'anno delle attività del nuovo corso.»
- Perché: per corsi ed eventi il nome a schermo è «anno delle attività» (così è già in Impostazioni → Ente).

### wordpress/associazionesemplice/includes/ActivityKind.php:23 · Media · bacheca (scelta del tipo di attività)
- Ora: «Evento ricorrente (iscrizione al singolo evento)»
- Proposta: «Evento ricorrente (prenotazione data per data)»
- Perché: agli eventi ci si prenota, non ci si iscrive (glossario); «singolo evento» dentro «evento ricorrente» confonde.

### wordpress/associazionesemplice/includes/ActivityReset.php:358 · Media · bacheca (descrizione del movimento in prima nota)
- Ora: « (quota non fruita)»
- Proposta: « (contributo non usato)»
- Perché: «quota» vale solo per la quota associativa; «fruita» è burocratese. Il codice riconosce solo «Restituzione di un incasso», quindi la parentesi si può cambiare.

### wordpress/associazionesemplice/includes/ActivityReset.php:512 · Media · bacheca (riepilogo prima di eliminare un evento o un'iscrizione; anche righe 515 e 518)
- Ora (512): « Gli incassi già fruiti (…) restano in prima nota così come sono e non vengono restituiti né annullati.»
- Proposta: « Gli incassi del periodo già frequentato (…) restano in prima nota: non vengono restituiti né annullati.»
- Ora (515): « Per il fruito mancano …: restano da incassare, fuori dall'iscrizione.»
- Proposta: « Per il periodo già frequentato mancano …: restano da incassare a parte.»
- Ora (518): « Per il fruito risultano versati … in più: il credito va restituito a mano.»
- Proposta: « Per il periodo già frequentato sono stati versati … in più: restituiscili a mano.»
- Perché: «fruito» non è una parola da volontario di segreteria; detto così si capisce di quali soldi si parla. Leggermente più lungo, ma oggi la frase non si capisce.

### wordpress/associazionesemplice/includes/ActivityReset.php:647 · Bassa · bacheca
- Ora: «L'iscrizione non esiste (più).»
- Proposta: «L'iscrizione non esiste più.»
- Perché: la parentesi è un vezzo da programmatore.

### wordpress/associazionesemplice/includes/ActivityReset.php:670 · Media · bacheca (blocco per anno fiscale chiuso)
- Ora: « (Contiene incassi di questa iscrizione: cancellarla li modificherebbe.)»
- Proposta: « (Contiene incassi di questa iscrizione: annullarla li modificherebbe.)»
- Perché: glossario, «annullare» e non «cancellare».

### wordpress/associazionesemplice/includes/ActivityReset.php:743 · Media · email al socio (iscrizione o prenotazione tolta dalla segreteria; oggetto alla riga 745)
- Ora (743): «la tua iscrizione a «…»… è stata cancellata.»
- Proposta: «la tua iscrizione a «…»… è stata annullata.»
- Ora (745, oggetto): «Iscrizione cancellata: »
- Proposta: «Iscrizione annullata: »
- Perché: glossario; «cancellata» al socio suona come un errore del sistema. Vedi anche «Da verificare» per la data e per «iscrizione/prenotazione».

### wordpress/associazionesemplice/includes/ActivityService.php:319 · Media · bacheca (salvataggio di un'attività)
- Ora: «Anno sociale mancante.»
- Proposta: «Scegli l'anno delle attività.»
- Perché: termine fissato, e dice cosa fare invece di segnalare una mancanza.

### wordpress/associazionesemplice/includes/ActivityService.php:385 · Media · bacheca (date di corsi ed eventi fuori dall'anno)
- Ora: «… (…) deve cadere nell'anno sociale …, dal … al …: un corso o un evento inizia e finisce nello stesso anno sociale.»
- Proposta: «… (…) deve cadere nell'anno delle attività … (dal … al …): corsi ed eventi iniziano e finiscono nello stesso anno.»
- Perché: termine fissato; frase oltre le 25 parole, alleggerita togliendo la ripetizione.

### wordpress/associazionesemplice/includes/ActivityService.php:425 · Media · bacheca (aggiunta di una data a un corso)
- Ora: «I corsi non hanno date: si iscrivono per mesi.»
- Proposta: «I corsi non hanno date: ci si iscrive per mesi.»
- Perché: così com'è sembra che siano i corsi a iscriversi.

### wordpress/associazionesemplice/includes/ActivityService.php:484 · Bassa · bacheca e area soci (virgolette dritte; stesso difetto in ApplePass.php:89, AttachmentRules.php:29, 32, 36, 88, 89, Attachments.php:87, 97, 124, 207, DocumentRules.php:87, 90, 94)
- Ora: «Un evento una tantum ha una sola data: per più date scegli "Evento ricorrente".»
- Proposta: «Un evento una tantum ha una sola data: per più date scegli «Evento ricorrente».»
- Perché: lo standard è «…». Negli altri punti basta sostituire `"` con `«` e `»` attorno al nome del file o della pagina (es. «Il file «…» è vuoto.»).

### wordpress/associazionesemplice/includes/ActivityService.php:577 · Bassa · area soci e bacheca (prenotazione; uguale alla riga 1091)
- Ora: «Data non disponibile (inesistente o annullata).»
- Proposta: «Questa data non è più disponibile.»
- Perché: al socio non serve la distinzione tecnica tra «inesistente» e «annullata».

### wordpress/associazionesemplice/includes/ActivityService.php:939 · Bassa · bacheca (gestori di un evento)
- Ora: «È già il referente dell'evento e dispone di tutti i permessi di gestione.»
- Proposta: «È già il referente dell'evento: ha già tutti i permessi.»
- Perché: «dispone di» è burocratese.

### wordpress/associazionesemplice/includes/ActivityService.php:1110 · Media · area soci (cessione del posto)
- Ora: «… è già prenotato/a a questa data.»
- Proposta: «… ha già una prenotazione per questa data.»
- Perché: via la doppia forma con la barra.

### wordpress/associazionesemplice/includes/ActivityService.php:1114 · Bassa · bacheca (nota aggiunta alla descrizione del movimento in prima nota)
- Ora: « [intestato da … a …]»
- Proposta: « [posto ceduto da … a …]»
- Perché: a schermo l'operazione si chiama «cedere il posto»; «intestato» non la richiama.

### wordpress/associazionesemplice/includes/ActivityService.php:1198 · Media · bacheca («errore del database»; stesso difetto in Affiliations.php:50 e 116, Applications.php:327, Assemblies.php:69, AssocPolicies.php:66, Budget.php:54, Certificates.php:147, Conventions.php:86, Documents.php:115, Broadcasts.php:239)
- Ora: «Impossibile salvare la variante (errore del database).»
- Proposta: «Variante non salvata: riprova.»
- Perché: «database» è gergo e il messaggio non dice cosa fare. Gli altri seguono lo stesso schema: «Affiliazione non salvata: riprova.», «Tessera non salvata: riprova.», «Genitore non registrato: riprova.», «Salvataggio non riuscito: riprova.» (Assemblies, Budget), «Polizza non salvata: riprova.», «Certificato non salvato: riprova.», «Convenzione non salvata: riprova.», «Documento non salvato: riprova.»; per Broadcasts.php:239 «La comunicazione non è stata creata: non è partito nulla. Riprova.»

### wordpress/associazionesemplice/includes/Affiliations.php:103 · Media · bacheca (tessera di un ente a un socio)
- Ora: «Si tessera un socio attivo.»
- Proposta: «Puoi tesserare solo un socio attivo.»
- Perché: letto come errore non si capisce che è un divieto.

### wordpress/associazionesemplice/includes/Affiliations.php:371 · Bassa · email alla segreteria (elenco scadenze) e bacheca (lineetta come separatore; stesso difetto in Affiliations.php:399, Certificates.php:401, Docs.php:361, DoorSales.php:94)
- Ora: «… (codice …) — scade il »
- Proposta: «… (codice …): scade il »
- Perché: la lineetta fa da inciso; i due punti bastano. Per Docs.php:361 « — più votata» → « (più votata)».

### wordpress/associazionesemplice/includes/ApplePass.php:20 · Media · bacheca (tessera per Apple Wallet)
- Ora: «Questo server non può creare le tessere per Apple Wallet (servono le estensioni zip e openssl di PHP).»
- Proposta: «Questo server non può creare le tessere per Apple Wallet: chiedi al tuo hosting di attivare le estensioni zip e openssl di PHP.»
- Perché: manca il rimedio; un volontario non sa cosa farsene di «estensioni di PHP» se non gli si dice a chi girarlo.

### wordpress/associazionesemplice/includes/ApplePass.php:42 · Bassa · tessera in Apple Wallet (retro, sul telefono del socio)
- Ora: «La validità della tessera si verifica sempre in diretta: il periodo indicato è quello al momento dell'emissione.»
- Proposta: «Il periodo indicato è quello di quando la tessera è stata emessa: la validità si controlla ogni volta al momento.»
- Perché: «in diretta» è fuori registro; meglio partire da ciò che il socio vede.

### wordpress/associazionesemplice/includes/ApplicationRules.php:109 · Media · modulo pubblico di adesione
- Ora: «Scrivi un'email valida: ci arriva il link per entrare.»
- Proposta: «Scrivi un'email valida: lì ricevi il link per entrare.»
- Perché: «ci arriva» si legge come «arriva a noi».

### wordpress/associazionesemplice/includes/Applications.php:185 · Media · email a chi ha fatto domanda di adesione
- Ora: «. La segreteria la guarda e ti scrive appena è approvata.»
- Proposta: «. La segreteria la esamina e ti risponde per email.»
- Perché: «appena è approvata» dà per scontato l'esito, che può essere un rifiuto.

### wordpress/associazionesemplice/includes/Applications.php:188 · Bassa · email a chi ha fatto domanda di adesione
- Ora: «Se la domanda non venisse accettata, la quota ti viene restituita.»
- Proposta: «Se la domanda non viene accettata, ti restituiamo la quota.»
- Perché: tempi verbali discordanti e passivo inutile.

### wordpress/associazionesemplice/includes/Applications.php:193 · Bassa · email a chi ha fatto domanda di adesione (maschile generico; stesso difetto in Bank.php:243 e Donations.php:81, email di avviso agli amministratori)
- Ora: «Se non sei stato tu a chiederlo, ignora questo messaggio.»
- Proposta: «Se non hai fatto tu la domanda, ignora questo messaggio.»
- Perché: niente genere e dice a cosa si riferisce «chiederlo». In Bank.php:243 e Donations.php:81 «Se non sei stato tu, cambia subito…» → «Se non l'hai fatto tu, cambia subito…».

### wordpress/associazionesemplice/includes/Applications.php:253 · Media · bacheca ed email ai soci («area riservata»; stesso difetto in Applications.php:297, 343, 348, 350, 352, Assemblies.php:180, Broadcasts.php:180, Conventions.php:266)
- Ora: «L'email del genitore è già usata da un ospite, che non può entrare nell'area riservata: iscrivilo come socio o cambia l'email, poi riprova.»
- Proposta: «L'email del genitore è già usata da un ospite, che non può entrare nell'area soci: iscrivilo come socio o cambia l'email, poi riprova.»
- Perché: il glossario chiama «area soci» la pagina del socio. Negli altri punti basta la sostituzione («dalla tua area soci», «Area soci: » nel piè delle comunicazioni, «nell'area soci, sezione «Convenzioni»»).

### wordpress/associazionesemplice/includes/Applications.php:292 · Media · bacheca (esito dell'approvazione di una domanda)
- Ora: «È arrivata l'email al genitore.» / «Al nuovo socio è arrivato il link per scegliere la password.»
- Proposta: «Abbiamo inviato l'email al genitore.» / «Abbiamo inviato al nuovo socio il link per scegliere la password.»
- Perché: il plugin sa di aver inviato, non che il messaggio sia arrivato; se finisce nello spam la segreteria crede il contrario.

### wordpress/associazionesemplice/includes/Applications.php:294 · Alta · bacheca (esito dell'approvazione, genitore già in anagrafica)
- Ora: « Il genitore è già in anagrafica: gli è arrivata un'email per confermare di aver accettato lui, a nome del minore, regolamento, privacy e immagini. Il genitore non ha ancora confermato: il collegamento si crea quando conferma, e finché non lo fa queste accettazioni non gli vengono attribuite (lo vedi nella scheda del minore).»
- Proposta: « Il genitore è già in anagrafica: gli abbiamo inviato un'email per confermare che ha accettato lui, a nome del minore, regolamento, privacy e immagini. Finché non conferma, il collegamento con il minore non si crea e le accettazioni non risultano a suo nome (lo vedi nella scheda del minore).»
- Perché: 55 parole in due frasi che dicono la stessa cosa due volte; in un messaggio di esito nessuno arriva in fondo.

### wordpress/associazionesemplice/includes/Applications.php:348 · Media · email al genitore (domanda del minore approvata)
- Ora: «… compare nella tua area riservata dopo che hai confermato dal link che ti abbiamo mandato con un'altra email.»
- Proposta: «Per vedere … nella tua area soci, conferma dal link che ti abbiamo mandato in un'altra email.»
- Perché: mette prima l'azione da fare; così com'è sembra un fatto già avvenuto. Il segnaposto del nome si sposta dopo «Per vedere».

### wordpress/associazionesemplice/includes/Applications.php:395 · Media · email a chi ha fatto domanda (domanda rifiutata, oggetto)
- Ora: «Domanda di adesione»
- Proposta: «Esito della domanda di adesione»
- Perché: le altre due email hanno «ricevuta» e «approvata»; questa non dice di cosa parla e si confonde con la prima.

### wordpress/associazionesemplice/includes/Assemblies.php:174 · Media · email ai soci (convocazione dell'assemblea)
- Ora: «sei convocato all'»
- Proposta: «è convocata l'»
- Perché: evita il maschile generico; la frase diventa «è convocata l'assemblea ordinaria «…».», che è anche la formula d'uso.

### wordpress/associazionesemplice/includes/AssemblyRules.php:153 · Bassa · verbale dell'assemblea (riga di riepilogo, bacheca e PDF)
- Ora: «NON raggiunge il numero legale»
- Proposta: «non raggiunge il numero legale»
- Perché: il maiuscolo grida, e in un verbale stona.

### wordpress/associazionesemplice/includes/Backup.php:143 · Media · bacheca (Strumenti → Copia di sicurezza; stesso difetto alle righe 333 e 472)
- Ora (143): «Questo server non può creare file ZIP (manca l'estensione zip di PHP).»
- Proposta: «Questo server non può creare file ZIP: chiedi al tuo hosting di attivare l'estensione zip di PHP.»
- Ora (333, 472): «Questo server non può leggere file ZIP.»
- Proposta: «Questo server non può leggere file ZIP: chiedi al tuo hosting di attivare l'estensione zip di PHP.»
- Perché: manca il rimedio; alle righe 333 e 472 manca anche la causa.

### wordpress/associazionesemplice/includes/Backup.php:257 · Alta · file di istruzioni dentro la copia di sicurezza
- Ora: «I documenti riservati (certificati, allegati, scansioni) sono cifrati quando il sito li ha cifrati; i campi di testo che sul sito non si potevano cifrare (il manifest ne conta il numero in «plain_fields») stanno nella copia in chiaro. Le chiavi dei documenti cifrati sono in chiavi.json, avvolte con la chiave del sito, con la frase in uso (il codice di licenza, la data e l'ora in cui è stata creata la chiave dei file, in UTC, oppure la frase scelta; la frase non è la data di installazione del plugin) e, se il Pro era attivo quando hai fatto la copia, anche con il codice di licenza. Senza la chiave del sito di origine e senza una di queste frasi i documenti cifrati si rimettono sul sito ma restano chiusi: il ripristino dice quanti sono e si aprono più tardi con «Riapri la chiave con la frase».»
- Proposta: «I documenti riservati (certificati, allegati, scansioni) restano cifrati anche nella copia. Per aprirli dopo il ripristino serve la chiave del sito di origine oppure la frase in uso quando hai fatto la copia: quella che hai scelto tu, oppure quella automatica (il codice di licenza, la data e l'ora, in UTC, in cui è stata creata la chiave dei file: non la data di installazione del plugin). Se il Pro era attivo quando hai fatto la copia, vale anche il codice di licenza. Senza, i documenti tornano sul sito ma restano chiusi: il ripristino dice quanti sono e li apri più tardi con «Riapri la chiave con la frase». I testi che il sito non aveva potuto cifrare sono in chiaro anche nella copia (il manifest li conta in «plain_fields»); le chiavi stanno in chiavi.json.»
- Perché: è il passaggio che serve nel momento peggiore (ripristino dopo un guasto) ed è quasi illeggibile: «cifrati quando il sito li ha cifrati», «avvolte», una parentesi di 35 parole. La proposta mette prima cosa serve per riaprire i documenti. Da far rileggere a chi ha scritto la cifratura: vedi «Da verificare».

### wordpress/associazionesemplice/includes/Backup.php:258 · Media · file di istruzioni dentro la copia di sicurezza
- Ora: «Il ripristino rimette le password: ogni socio, al primo accesso, entra con la password di prima e deve scegliere subito una nuova password (vale anche entrando con il link ricevuto per e-mail).»
- Proposta: «Dopo il ripristino ogni socio entra con la password di prima e deve sceglierne subito una nuova (anche se entra con il link ricevuto per email).»
- Perché: «password» tre volte in una frase; «e-mail» è l'unica grafia diversa da «email» in tutto il lotto.

### wordpress/associazionesemplice/includes/Backup.php:302 · Media · bacheca (conferma di ripristino di una copia fatta su un altro sito)
- Ora: «Verranno sostituiti: tutte le tabelle del plugin (soci, prima nota, attività, ricevute, fondi e le altre), le impostazioni e i testi personalizzati, gli allegati e i PDF delle ricevute citati dai dati della copia, le chiavi dei documenti riservati e l'identificativo del sito che li lega.»
- Proposta: «Verranno sostituiti: tutti i dati del plugin (soci, prima nota, attività, ricevute, fondi e il resto), le impostazioni e i testi personalizzati, gli allegati e i PDF delle ricevute presenti nella copia, le chiavi dei documenti riservati e il codice del sito a cui sono legati.»
- Perché: «tabelle», «citati dai dati», «identificativo che li lega» sono parole da programmatore in una conferma che va capita prima di premere.

### wordpress/associazionesemplice/includes/Backup.php:303 · Media · bacheca (stessa conferma)
- Ora: «Verranno cancellate, senza essere ripristinate dalla copia: le sessioni dei soci e le notifiche push di adesso. Le password dei soci invece tornano come nella copia, e ogni socio dovrà sceglierne una nuova al primo accesso.»
- Proposta: «Verranno eliminati, e non tornano dalla copia: gli accessi aperti dei soci (dovranno rientrare) e le notifiche push attive. Le password dei soci tornano quelle della copia: ogni socio ne sceglierà una nuova al primo accesso.»
- Perché: «sessioni» è gergo e non dice la conseguenza pratica; «cancellate» è fuori glossario.

### wordpress/associazionesemplice/includes/Backup.php:385 · Media · bacheca (ripristino rifiutato)
- Ora: «La copia è stata modificata dopo essere stata fatta, oppure è danneggiata: il suo contenuto non coincide con l'elenco con cui è stata firmata. Non è stato cambiato nulla.»
- Proposta: «La copia è stata modificata dopo la creazione, oppure è danneggiata. Non è stato cambiato nulla.»
- Perché: la spiegazione sulla firma non aiuta chi legge e allunga un messaggio che deve soprattutto rassicurare.

### wordpress/associazionesemplice/includes/Backup.php:424 · Media · bacheca (ripristino fallito; uguale alle righe 574 e 582)
- Ora: «Errore del database durante il ripristino: »
- Proposta: «Ripristino non riuscito. Dettaglio tecnico: »
- Perché: prima l'esito in parole normali; il dettaglio resta, ma dichiarato come tale (serve all'assistenza).

### wordpress/associazionesemplice/includes/Backup.php:590 · Bassa · bacheca (ripristino fallito)
- Ora: « Nessun dato è stato cambiato.»
- Proposta: « Non è stato cambiato nulla.»
- Perché: negli altri messaggi dello stesso file la formula è «Non è stato cambiato nulla.»

### wordpress/associazionesemplice/includes/BalanceWatchRules.php:63 · Media · bacheca ed email alla segreteria (avviso sull'andamento dell'anno)
- Ora: «: un utile molto più alto del necessario. Conviene destinare le risorse alle attività dei soci (corsi, eventi, attrezzature) o rivedere le quote, e parlarne in consiglio direttivo.»
- Proposta: «: un avanzo molto più alto del necessario. Conviene usarlo per le attività dei soci (corsi, eventi, attrezzature) o rivedere le quote, e parlarne in consiglio direttivo.»
- Perché: un'associazione non fa «utile» ma «avanzo» (glossario); «destinare le risorse» è burocratese.

### wordpress/associazionesemplice/includes/BalanceWatchRules.php:68 · Media · bacheca (titolo dell'avviso)
- Ora: «Utile troppo alto»
- Proposta: «Avanzo troppo alto»
- Perché: come sopra.

### wordpress/associazionesemplice/includes/BrevoNewsletter.php:96 · Bassa · bacheca (prova di connessione in Strumenti → Integrazioni; uguale in BrevoSms.php:75)
- Ora: «Brevo: risposta inattesa (…).»
- Proposta: «Brevo non ha risposto come previsto (codice …): riprova più tardi.»
- Perché: «risposta inattesa» è da programmatore e non dice cosa fare.

### wordpress/associazionesemplice/includes/Broadcasts.php:221 · Bassa · bacheca (invio di una comunicazione)
- Ora: « comunicazioni nelle ultime 24 ore: aspetta un po'.»
- Proposta: « comunicazioni nelle ultime 24 ore: riprova più tardi.»
- Perché: «aspetta un po'» è troppo colloquiale.

### wordpress/associazionesemplice/includes/Broadcasts.php:324 · Bassa · bacheca (invio di prova a sé stessi)
- Ora: «L'utente collegato non ha un indirizzo email.»
- Proposta: «Il tuo utente non ha un indirizzo email: aggiungilo nel tuo profilo.»
- Perché: «l'utente collegato» sei tu che leggi; manca il rimedio.

### wordpress/associazionesemplice/includes/Budget.php:44 · Bassa · bacheca e PDF (titolo predefinito del preventivo)
- Ora: «Bilancio preventivo »
- Proposta: «Preventivo »
- Perché: nel resto del plugin si chiama «preventivo»; «bilancio» è tra i termini da evitare nel glossario.

### wordpress/associazionesemplice/includes/Budget.php:216 · Bassa · bacheca (copia delle voci dall'anno prima)
- Ora: «Si copia solo in un preventivo in preparazione.»
- Proposta: «Puoi copiare le voci solo in un preventivo in preparazione.»
- Perché: non si capisce cosa «si copia» né che è un divieto.

### wordpress/associazionesemplice/includes/Certificates.php:133 · Bassa · bacheca (registro dei certificati medici)
- Ora: «Il certificato si registra per un socio attivo.»
- Proposta: «Puoi registrare il certificato solo per un socio attivo.»
- Perché: stesso schema di Affiliations.php:103.

### wordpress/associazionesemplice/includes/Contacts.php:119 · Bassa · bacheca (avviso quando mancano i contatti della segreteria)
- Ora: «(Impostazioni › Ente)»
- Proposta: «(Impostazioni → Ente)»
- Perché: i percorsi di menu usano «→» in tutto il resto del plugin.

### wordpress/associazionesemplice/includes/Conventions.php:53 · Media · bacheca (scheda di una convenzione)
- Ora: «L'indirizzo del sito non è valido (deve essere http o https).»
- Proposta: «L'indirizzo del sito non è valido: deve iniziare con https:// o http://.»
- Perché: «deve essere http» non dice niente a chi non è tecnico; così si vede cosa scrivere.

### wordpress/associazionesemplice/includes/Credits.php:328 · Media · bacheca (descrizione della somma accantonata nel fondo dell'attività)
- Ora: «Quota di «…» pagata in crediti»
- Proposta: «Contributo di «…» pagato in crediti»
- Perché: per corsi ed eventi il termine è «contributo»; «quota» è solo la quota associativa.

### wordpress/associazionesemplice/includes/DocsPro.php:237 · Media · PDF del rendiconto per cassa
- Ora: «Disponibilità effettiva»
- Proposta: «Disponibilità reale»
- Perché: è l'unico punto del plugin che la chiama «effettiva»; ovunque (circa 26 volte) è «Disponibilità reale».

## Da verificare (sospetti errori di fatto, non riscritti)
- ActivityReset.php:743 (email al socio): la data è messa nel testo così com'è salvata (`session_date`), quindi con ogni probabilità esce «del 2026-11-20» invece di «del 20/11/2026». Stesso sospetto per `tx_date` nelle descrizioni in prima nota alle righe 358, 374 e 714 («Restituzione di un incasso del 2026-…»).
- ActivityReset.php:743 e 745: quando c'è una data (`$p['session']`) si tratta di una prenotazione a un evento, ma email e oggetto dicono «iscrizione». Secondo il glossario servirebbero due testi («la tua prenotazione a «…» del … è stata annullata»).
- ActivityReset.php:316: la categoria creata in prima nota si chiama «Restituzione quote», ma riguarda contributi di eventi e corsi (glossario: «quota» solo per la quota associativa). Non riscritta perché il codice la cerca per nome (riga 314) e i test la controllano: cambiarla richiede di rinominare anche quelle già create.
- ActivityReset.php:714, 722 e 729: «[Iscrizione cancellata…]» nelle descrizioni dei movimenti è fuori glossario («annullata»), ma la riga 245 riconosce i movimenti proprio dal prefisso «[Iscrizione cancellata]»: si cambia solo insieme al codice e ai movimenti già scritti.
- Backup.php:257: nell'originale non è chiaro se la «frase in uso» automatica sia il codice di licenza insieme a data e ora, oppure uno dei due, né che differenza ci sia con il «codice di licenza» citato subito dopo per il Pro. La proposta conserva la stessa formulazione tra parentesi: va confermata da chi conosce la cifratura.
- Credits.php:172: «Manca la voce «Altra entrata» in prima nota.» non dice come rimediare, e non è chiaro dove la segreteria possa ricrearla (altrove la stessa cosa è chiamata anche «categoria», es. Discrepancies.php:22 e BalanceWatch.php:140 «Categoria eliminata»). Serve il percorso giusto da aggiungere al messaggio.
- Docs.php:248 (sottotitolo del PDF del libro soci): «… soci, … in carica». «In carica» si dice di chi ha un incarico (consiglio direttivo); per i soci ci si aspetterebbe «attivi» o «iscritti oggi». Da confermare cosa conta il numero.
- Applications.php:393-395: l'email di rifiuto non dice il motivo né un recapito («Per chiarimenti rivolgiti alla segreteria.»): se i contatti della segreteria sono ormai obbligatori (decisione del 15/11) potrebbero essere aggiunti qui.
- «anno sociale»: nel lotto è stato proposto il cambio nei 3 messaggi che lo mostrano; nel resto del plugin ne restano molti (circa 60 righe, non tutte a schermo). Il glossario in `audit/03-plugin.md` riga 51 riporta ancora «Anno sociale» e va allineato alla decisione del 18/11.
