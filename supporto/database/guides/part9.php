<?php

// Guide: votazioni, inventario e archivio documenti (plugin gratuito, blocco B).
return [
    [
        'topic' => 'altro', 'section' => 'Registri', 'slug' => 'votazioni-e-sondaggi',
        'title' => 'Far votare i soci: votazioni e sondaggi',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Con le votazioni i soci decidono, o danno un parere, senza fogli e riunioni. La segreteria, il presidente, il vicepresidente e gli amministratori le gestiscono da **Registri → Votazioni**, accanto a Libro soci e Verbali.

## Creare una votazione

1. Vai in **Registri → Votazioni** e premi **Nuova votazione**.
2. Scrivi il **titolo** e la **domanda**.
3. Scrivi le **risposte**, una per riga (da 2 a 20).
4. Scegli quando si **apre** e quando si **chiude**.
5. Scegli se si può dare **una sola risposta** o **più risposte**, e se il voto è **segreto**.
6. Scegli **chi può votare** (vedi sotto) e salva.

## Chi può votare

Tutti i soci in regola con la tessera, oppure solo gli iscritti a una certa attività.

**Non votano**: genitori e tutori che non sono soci, ospiti, soci sospesi, soci usciti e soci con la tessera scaduta. Non si vota per delega: ogni socio vota dal proprio accesso.

## Come votano i soci

Dall'**area riservata**, nella sezione **«Votazioni e sondaggi»**. Si vota **una volta sola** e la risposta non si può cambiare. Per mostrare la sezione in una pagina del sito usa lo shortcode `[associazionesemplice_votazioni]`.

## Voto palese e voto segreto

- **Voto segreto**: il sito ricorda *chi* ha votato, così nessuno vota due volte, ma non *cosa* ha scelto. Nemmeno la segreteria può risalirci.
- **Voto palese**: la segreteria vede i nomi accanto a ogni risposta.

## Risultati

- La segreteria li vede quando vuole, anche a votazione aperta (in quel caso sono provvisori).
- I soci li vedono **solo dopo la chiusura** e solo se hai spuntato **«Dopo la chiusura i soci vedono i risultati»**.
- Puoi scaricare il **verbale dei risultati in PDF**, con lo spazio per le firme di segretario e presidente, e la tabella in **CSV**. Il PDF è un documento di supporto: il verbale dell'assemblea resta quello di **Registri → Verbali**.

## Avvisare i soci

Con **«Avvisa i soci per email»** attivo e i promemoria accesi (**Impostazioni → Privacy e regolamento**), parte un'email all'apertura e un promemoria nelle ultime 48 ore, solo a chi non ha ancora votato. Il pulsante **«Avvisa i soci ora»** manda l'avviso subito.

## Cose da sapere

- Dopo il **primo voto** non si cambiano più le risposte, il tipo di scelta, il voto segreto e chi può votare. Restano modificabili titolo, chiusura e avvisi.
- Per cambiare altro, o per correggere dei voti, crea una **nuova votazione**: i voti non si possono riaprire.
- I dati delle votazioni sono inclusi nella [copia di sicurezza](guida:copia-di-sicurezza-e-ripristino); l'azzeramento dei dati li cancella.

## Un socio non vede la votazione

Controlla che:

1. la votazione sia già **aperta**;
2. il socio abbia la **tessera valida** e non sia sospeso né uscito;
3. se la votazione è per gli iscritti a un'attività, sia **iscritto** a quell'attività;
4. non sia un genitore o tutore non socio (non vota).
MD,
    ],
    [
        'topic' => 'altro', 'section' => 'Registri', 'slug' => 'inventario-e-prestiti',
        'title' => 'Tenere l\'inventario dei beni e i prestiti ai soci',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

L'**Inventario** è il registro di ciò che l'associazione possiede (casse, proiettore, tavoli…) e ti dice chi ha cosa in prestito. Lo trovi in **Registri → Inventario**.

> Non è il sistema delle **Risorse** prenotabili (sale, campi, armadietti, solo Pro): qui si tiene l'elenco dei beni.

## Registrare un bene

1. Vai in **Registri → Inventario** e premi **Nuovo bene**.
2. Compila **nome**, **categoria**, **quantità**, **stato** e **ubicazione**. Il **codice** è facoltativo, ma se lo inserisci deve essere unico.
3. Se vuoi, aggiungi **valore**, **data d'acquisto** e una **foto o un allegato**.
4. Salva. Con l'esportazione in **CSV** scarichi l'elenco completo.

## Prestare un bene a un socio

1. Apri la **scheda del bene**.
2. Scegli il **socio o volontario**, i **pezzi**, la **data di uscita** e la **data di rientro prevista**. Puoi annotare le condizioni.
3. Salva.

Non puoi prestare più pezzi di quelli disponibili, né un bene da riparare, perso o dismesso.

## Registrare il rientro

1. Nella scheda del bene premi **Rientrato** sul prestito.
2. Indica la data, le note sullo stato e, se il bene si è rovinato, il **nuovo stato**.

Lo storico dei prestiti si vede per bene e per persona.

## Prestiti scaduti e promemoria

Un prestito non rientrato diventa **scaduto il giorno dopo** la data prevista. Lo trovi:

- nel riquadro **«Prestiti da recuperare»** della Bacheca di Associazione Semplice;
- nel riquadro **Da lavorare** della Bacheca di WordPress (vedi [Usare la Bacheca](guida:bacheca-da-lavorare-ed-eventi-della-settimana)).

A chi ha il bene arriva anche un **promemoria per email**, una volta per prestito. Si attiva da **Impostazioni → Privacy e regolamento → Prestiti**.

## Privacy

Un socio con beni in prestito **non si può anonimizzare** finché non li riporta. I suoi prestiti compaiono nell'esportazione dei suoi dati personali.
MD,
    ],
    [
        'topic' => 'altro', 'section' => 'Registri', 'slug' => 'archivio-documenti',
        'title' => 'Archiviare i documenti e decidere chi li vede',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

L'**Archivio documenti** raccoglie in un posto solo statuto, regolamenti, verbali firmati e moduli, e ti fa decidere chi può leggerli. Lo trovi in **Registri → Documenti**.

## Caricare un documento

1. Vai in **Registri → Documenti** e premi **Carica un documento**.
2. Scrivi il **titolo**, scegli o scrivi la **categoria**, indica la **data** e, se vuoi, una **descrizione**.
3. Scegli il **file**.
4. Scegli **chi lo vede** (vedi sotto) e salva.

**File ammessi**: PDF, foto (JPG, PNG, WebP), Word, Excel, LibreOffice e testo, fino al limite **«Peso massimo di un allegato»** (10 MB di norma).

## Chi lo vede

- **Solo segreteria**: lo vede solo chi gestisce il plugin.
- **Soci**: lo vedono i soci in regola dall'area riservata, nella sezione **«Documenti»**, oppure in una pagina con lo shortcode `[associazionesemplice_documenti_soci]`.
- **Pubblico**: lo vede chiunque, con lo shortcode `[associazionesemplice_documenti]` oppure con il blocco Associazione Semplice. Per mostrare una sola categoria usa `[associazionesemplice_documenti categoria="Moduli"]`.

## Sicurezza

I file stanno in una **cartella protetta** e non hanno un indirizzo diretto: ogni apertura passa da un controllo dei permessi. Chi non ne ha diritto vede un errore; chi non ha fatto l'accesso viene mandato al login, tranne per i documenti pubblici.

## Sostituire o eliminare

- Per **sostituire il file** apri il documento e scegli un nuovo file.
- **Elimina** toglie il documento dagli elenchi, ma il file resta sul sito, come gli allegati dei movimenti.

I documenti sono inclusi nella [copia di sicurezza](guida:copia-di-sicurezza-e-ripristino).
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Registri', 'slug' => 'certificati-medici-sportivi',
        'title' => 'Tenere i certificati medici sportivi dei soci',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale (da Pro 1.1.25; richiede il plugin gratuito 1.2.29, aggiorna insieme gratuito e Pro)

Il registro dei **certificati medici** ti dice chi ha il certificato in regola, chi è in scadenza, chi è scaduto e chi non lo ha mai portato, tra gli iscritti alle attività che lo richiedono. Lo trovi in **Registri → Certificati medici**, accanto a Libro soci e Verbali. Lo usano segreteria, presidente, vicepresidente e amministratori. Senza Pro, o con la licenza non in regola, la voce sparisce.

## Preparare le attività

1. Apri il modulo dell'attività (corso o evento).
2. Spunta **«richiede il certificato medico sportivo»** nella riga **Certificato medico**.
3. Salva.

## Registrare un certificato

1. Apri la scheda del socio, sezione **«Certificato medico»**, oppure vai in **Registri → Certificati medici**.
2. Scegli il **tipo**: non agonistico, agonistico o altro.
3. Indica la **data di rilascio** e la **scadenza**. La scadenza viene proposta a un anno dal rilascio: correggila con quella scritta sul certificato.
4. Se vuoi, indica il **medico o la struttura** e allega il **file** (PDF o foto).
5. Salva.

## Controllare chi è in regola

**Registri → Certificati medici** mostra gli iscritti con lo stato: **valido**, **in scadenza**, **scaduto** o **mancante**. Filtra per stato, per attività o su «tutti i soci attivi». **Esporta (CSV)** scarica l'elenco. In **Bacheca** un riquadro avvisa quanti certificati sono scaduti, mancanti o in scadenza.

## Promemoria

Sono **spenti** di partenza. Li accendi in **Registri → Certificati medici → Impostazioni → Promemoria automatici**.

- Una volta al giorno il socio, o il suo tutore se è minorenne, riceve un'email quando il certificato sta per scadere e, una volta, quando è scaduto da poco.
- La segreteria riceve un'unica email con l'elenco.
- Ogni avviso parte una sola volta per certificato.
- **Preavviso**: i giorni prima della scadenza in cui il certificato risulta «in scadenza» (di partenza 30).

Nelle stesse impostazioni puoi attivare **«Il socio vede nella sua area la scadenza del proprio certificato»**: il socio vede stato e scadenza, non il file né il medico. Le impostazioni le cambia solo l'amministratore.

## Privacy

- Il certificato è un **dato sanitario**: lo vede solo la segreteria, con i suoi stessi permessi. Il file non ha un indirizzo pubblico e si scarica solo dal sito da chi è autorizzato.
- Nel [registro azioni](guida:registro-azioni-e-spazio-occupato) resta scritto che un certificato è stato aggiunto, aperto o eliminato, mai cosa conteneva.
- I certificati compaiono nell'esportazione dei dati della persona. Se la persona viene anonimizzata, certificati e file vengono cancellati.
- Genitori e tutori non sono soci: non hanno certificati.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Registri', 'slug' => 'affiliazioni-agli-enti',
        'title' => 'Gestire le affiliazioni agli enti e le tessere dei soci',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale (da Pro 1.1.25; richiede il plugin gratuito 1.2.29, aggiorna insieme gratuito e Pro)

In **Registri → Affiliazioni** tieni i codici di affiliazione dell'associazione agli enti di promozione sportiva, le tessere dei soci e i file da caricare sui portali degli enti.

Gli enti disponibili sono CONI/RAS, UISP, CSEN, ACSI, AICS, oppure **«Altro ente»** con il nome scritto a mano.

## Registrare l'affiliazione dell'associazione

1. Vai in **Registri → Affiliazioni** e premi **Registra un'affiliazione**.
2. Scegli l'**ente** e scrivi il **codice**.
3. Indica la **stagione** (per esempio 2026 oppure 2025/2026), la **scadenza** e il **costo**.
4. Salva. Puoi avere una sola affiliazione per ente e per stagione.

## Tesserare un socio

**Una persona per volta**: dalla scheda del socio, sezione **«Tessere degli enti»**, oppure da **Registri → Affiliazioni → Nuova tessera**. Indica ente, stagione, numero (facoltativo), tipo di tessera, data, scadenza e costo.

**Più soci insieme**:

1. Premi **Tesseramento in blocco**.
2. Scegli **tutti i soci attivi** oppure **gli iscritti a un'attività**.
3. Conferma. Chi ha già la tessera dello stesso ente e stagione viene saltato.
4. Quando l'ente assegna i numeri, aggiungili dall'elenco.

Tutori e ospiti non sono soci: non si tesserano.

## Elenco e file per il portale dell'ente

Filtra l'elenco per ente, stagione, stato e attività.

- **Esporta l'elenco (CSV)**: scarica l'elenco con numeri, costi e stato.
- **File per il portale dell'ente (CSV)**: scarica nome, cognome, codice fiscale, data e luogo di nascita, sesso, indirizzo, email, telefono e tipo di tessera.

> **Controlla il file prima di caricarlo.** Il sito non conserva luogo di nascita e sesso: li ricava dal codice fiscale, e il luogo è il **codice catastale** (per esempio H501), non il nome del comune.

## Promemoria di scadenza

Sono **spenti** di partenza. Li accendi in **Registri → Affiliazioni → Impostazioni**.

- Il socio, o il suo tutore, riceve un'email per la scadenza della tessera.
- La segreteria riceve un'unica email con affiliazioni e tessere in scadenza o scadute da poco.
- Ogni avviso parte una sola volta per scadenza. Il **preavviso** di partenza è di 30 giorni.
- In **Bacheca** un riquadro ricorda le scadenze.

Le impostazioni le cambia solo l'amministratore.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Risorse e spazi', 'slug' => 'risorse-promemoria-qr-occupazione-richieste',
        'title' => 'Risorse: promemoria, QR d\'ingresso, report di occupazione e richieste dei soci',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale (da Pro 1.1.26; plugin gratuito invariato)

Queste funzioni completano le [risorse](guida:creare-e-gestire-le-risorse): le impostazioni stanno nella scheda di ciascuna risorsa.

## Promemoria prima della prenotazione

1. Apri la scheda della risorsa (**Risorse → Elenco**).
2. In **Promemoria** scegli **nessuno**, **2**, **12**, **24** o **48 ore** prima.
3. Salva.

Il socio riceve un'email con data, ora, ospiti e quanto resta da pagare. L'email parte **una sola volta** per prenotazione; per i minori va al tutore. Non parte per chi prenota dentro la finestra scelta, per le prenotazioni provvisorie in attesa del pagamento online e per quelle annullate.

## Voce di prima nota per risorsa

Nella scheda della risorsa puoi scegliere la **voce di entrata** su cui registrare gli incassi, in segreteria e online. La quota associativa non è tra le voci scelte. Se non scegli nulla, gli incassi vanno su «Altra entrata». Nei report e nel rendiconto le risorse si vedono separate. Con i **crediti** l'entrata è la vendita dei crediti, non la singola prenotazione.

## Report di occupazione

1. Vai in **Risorse → Occupazione**.
2. Scegli il **periodo**.

Per ogni risorsa a fasce vedi le ore **disponibili** (tra apertura e chiusura), **prenotate dai soci**, **occupate da corsi ed eventi**, **in allestimento** e **bloccate**, la **percentuale di occupazione** (allestimenti e blocchi si vedono a parte), gli incassi del periodo e i crediti spesi. Per le risorse assegnate a periodo vedi le assegnazioni in corso e gli incassi.

## Richieste di assegnazione dei soci

Per i posti auto, gli armadietti e i posti barca:

- se la risorsa è **libera**, il socio la **chiede** dalla sua area;
- se è **occupata**, il socio si mette in coda come prima.

Come segreteria apri la risorsa e vai in **«Richieste e lista d'attesa»**. Premi **Assegna** per approvare la richiesta, oppure toglila.

## Ingresso con il QR

Per le risorse che lo prevedono, il socio vede nella sua area il **QR di ogni prenotazione**. Non c'è per le assegnazioni né per le prenotazioni ancora in attesa del pagamento online.

1. Inquadra il QR con la **fotocamera del telefono**.
2. Si apre una pagina con risorsa, giorno e ora, socio, ospiti e pagamento, con una fascia colorata: **valida**, **già entrato**, **troppo presto**, **scaduta** o **annullata**.
3. Con l'accesso della **segreteria** premi **Registra l'ingresso** (o **Togli l'ingresso** se l'hai registrato per sbaglio).

L'ingresso vale **dalla mezz'ora prima dell'inizio alla fine della fascia**, una volta sola, e si vede anche nell'elenco delle prenotazioni della risorsa. Chi non fa parte dello staff vede solo il nome abbreviato del socio (per esempio «Mario R.»). Il QR contiene solo prenotazione, socio e una firma che non si può indovinare.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Area riservata', 'slug' => 'convenzioni-per-i-soci',
        'title' => 'Offrire ai soci sconti e vantaggi: le convenzioni',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Le **convenzioni** raccolgono in un posto solo gli sconti e i vantaggi che negozi, palestre, teatri e altri enti partner riservano ai soci, e li mostrano ai soci giusti nell'area riservata. Le trovi in **Associazione Semplice → Registri → Convenzioni**. Le gestiscono segreteria, presidente, vicepresidente e amministratori. Non ci sono impostazioni da attivare.

## Creare una convenzione

1. Vai in **Registri → Convenzioni** e premi **Nuova convenzione**.
2. Compila i campi obbligatori: **esercente o ente partner** e **vantaggio** (per esempio «Sconto del 10%», «Prezzo agevolato 8 €», «Un caffè in omaggio»).
3. Scegli la **categoria** dall'elenco o scrivine una nuova. L'elenco si gestisce in fondo alla pagina «Convenzioni», una categoria per riga.
4. Se vuoi, aggiungi le **condizioni d'uso** (le vedono solo i soci), i contatti (indirizzo, telefono, email, sito: il sito va bene anche senza «https://») e il **logo**, scelto dalla libreria media di WordPress.
5. Indica **a chi spetta**: tutti i soci in regola oppure solo alcuni tipi (socio fondatore, ordinario, socio e volontario).
6. Scegli se è **visibile anche al pubblico** e, se vuoi, un **codice convenzione** da mostrare all'esercente.
7. Indica **valida dal / al** (facoltative). L'ultimo giorno è compreso. Senza data di fine la convenzione non scade.
8. Salva.

Dalla scheda puoi anche **archiviare** la convenzione (esce dall'area soci ma resta in elenco, e puoi **riportarla in elenco**) oppure **eliminarla**.

## Quando è visibile

Una convenzione è «in corso» se non è archiviata, è già iniziata e non è finita. **Solo quelle in corso** si vedono ai soci e sul sito. Passato l'ultimo giorno sparisce da sola dall'area soci, ma resta nella pagina di amministrazione con lo stato **«Scaduta»** (puoi filtrare per stato). Per riattivarla sposta o togli la data di fine.

In **Bacheca** il riquadro **«Convenzioni in scadenza»** mostra quelle che finiscono nei prossimi 30 giorni.

## Cosa vedono i soci

Nell'**area riservata**, sezione **Convenzioni** (o con lo shortcode `[associazionesemplice_convenzioni_soci]` in una pagina). Vedono le convenzioni in corso che spettano a loro e possono filtrarle per categoria. Serve una **tessera valida oggi** e il tipo di socio previsto.

**Non le vedono**: soci sospesi, con tessera scaduta o usciti, ospiti, e genitori o tutori che non sono soci (nemmeno per i figli minori).

Aprendo **«Come usarla»** il socio trova il codice (se c'è), le condizioni, i contatti dell'esercente e la riga per farsi riconoscere: nome e cognome, numero di tessera e scadenza. Può mostrarla all'esercente insieme alla tessera digitale.

## Elenco pubblico sul sito

Lo shortcode `[associazionesemplice_convenzioni]` mostra a tutti le convenzioni segnate «visibile anche al pubblico» e in corso: nome, logo, categoria e vantaggio in breve. Per una sola categoria usa `[associazionesemplice_convenzioni categoria="Sport e benessere"]`. L'elenco pubblico **non mostra mai** codice, condizioni né contatti.

Le pagine con questi shortcode non finiscono nella cache di pagina, così le convenzioni scadute non restano visibili.

## Avvisare i soci

Nella scheda della convenzione, il pulsante **«Avvisa i soci della nuova convenzione»** manda un'email a tutti i soci a cui spetta (tessera valida e tipo previsto) con il vantaggio e il rimando all'area riservata. Prima dell'invio la pagina dice quanti soci hanno un indirizzo email.

- Funziona solo se la convenzione è **in corso** e ogni socio riceve l'email **una volta sola**.
- Non dipende dall'interruttore «Promemoria attivi» di Impostazioni → Privacy e regolamento.
- Per un minore l'email va al tutore.
- **Una volta partito, l'avviso non si può rimandare.** Se invece non parte nessuna email (per esempio mancano gli indirizzi o la posta non funziona), l'avviso non risulta usato e puoi **riprovare**. Per un'altra comunicazione usa le comunicazioni ai soci.

## Esportare e salvare i dati

**Esporta (CSV)**, nella pagina «Convenzioni», scarica tutte le convenzioni (anche scadute e archiviate) con codice e contatti; il file si apre in Excel. Le convenzioni sono incluse nella [copia di sicurezza](guida:copia-di-sicurezza-e-ripristino); l'azzeramento dei dati le cancella.

## Domande frequenti

- **Un socio non vede nessuna convenzione.** Controlla che abbia la tessera valida oggi, che non sia sospeso né uscito e che esistano convenzioni in corso per il suo tipo. Ospiti e tutori non le vedono mai.
- **La convenzione è scaduta ma serve ancora.** Aprila e sposta la data di fine (o toglila). Se è archiviata, premi **Riporta in elenco**.
- **Il logo non si vede.** Deve essere un'immagine della libreria media: gli altri file vengono ignorati.
- **Il pubblico può vedere il codice?** No: l'elenco pubblico non mostra mai codice, condizioni né contatti.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Comunicazione', 'slug' => 'sms-ai-soci-e-consensi',
        'title' => 'Mandare SMS ai soci e gestire i consensi',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale (da Pro 1.1.28, che richiede il plugin gratuito 1.2.31: aggiorna insieme gratuito e Pro; non nel plugin gratuito)

Oltre alle email puoi scrivere ai soci per **SMS**, con il fornitore che preferisci. Il costo degli SMS è quello del fornitore scelto.

## Prima di tutto: il consenso

Per scrivere a qualcuno per SMS, o per iscriverlo alla newsletter, serve il suo **consenso**. Lo registri in due modi:

- nella **scheda della persona**, riquadro **«Consensi SMS e newsletter»**, se il consenso è su modulo cartaceo o a voce;
- oppure lo dà il socio da solo, nell'**area riservata**, sezione **«Le mie comunicazioni»**.

Si può **revocare quando si vuole**: da quel momento non parte più nulla. I consensi sono compresi nell'**esportazione dei dati** della persona.

## Collegare il fornitore

1. Vai in **Strumenti → Integrazioni → SMS ai soci**.
2. Scegli **Brevo** o **Twilio** e inserisci le chiavi.
3. Scrivi il **mittente** (fino a 11 lettere o cifre) e salva.
4. Premi **Verifica il collegamento**: controlla anche i **crediti residui**.

## Mandare una comunicazione per SMS

1. Vai in **Rubrica soci → Comunicazioni** e crea la comunicazione (vedi [Comunicazioni a gruppi](guida:comunicazioni-a-gruppi)).
2. Sotto **«Canali»** spunta **Email**, **SMS** o tutti e due.
3. Guarda l'**anteprima**: dice quanti riceveranno l'SMS e quanti no, perché senza consenso o senza numero valido.
4. Scrivi il testo: al massimo **480 caratteri**. I messaggi lunghi o con emoji usano **più SMS**.
5. Invia.

Nella scheda della comunicazione vedi chi ha ricevuto l'SMS. Se un invio non riesce il sito **riprova da solo** (dopo 15 minuti e poi dopo 2 ore); per gli errori definitivi, come un numero non valido, c'è il pulsante **Riprova**. I numeri nel registro sono sempre in parte nascosti.

## Avvisi automatici anche per SMS

I promemoria di scadenza, gli avvisi sulle attività e quelli della lista d'attesa possono partire anche per SMS. In **Integrazioni** attiva **«Manda anche per SMS gli avvisi automatici»**: è **spento** di partenza.

## Un SMS non è partito

Controlla che la persona abbia un **numero di cellulare valido** e il **consenso agli SMS**.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Comunicazione', 'slug' => 'newsletter-con-brevo-mailchimp-o-mailpoet',
        'title' => 'Collegare la newsletter: Brevo, Mailchimp o MailPoet',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale (da Pro 1.1.28, che richiede il plugin gratuito 1.2.31: aggiorna insieme gratuito e Pro; non nel plugin gratuito)

Il sito tiene **allineata la lista** della tua newsletter con i soci che hanno dato il consenso. **La newsletter si scrive e si invia dal servizio che scegli**, non dal plugin.

## Collegare il servizio

1. Vai in **Strumenti → Integrazioni → Newsletter**.
2. Scegli **Brevo**, **Mailchimp** o **MailPoet** (se è attivo sul sito).
3. Inserisci la **chiave** e premi **Leggi le liste**.
4. Scegli la **lista** e salva.

**Sincronizza adesso** allinea subito la lista; ogni giorno lo fa da solo.

## Chi entra e chi esce

- Si iscrive **solo chi ha dato il consenso alla newsletter** e ha un'email valida (vedi [Mandare SMS ai soci e gestire i consensi](guida:sms-ai-soci-e-consensi) per registrare i consensi).
- Chi **revoca il consenso**, cambia indirizzo o viene anonimizzato **esce** dalla lista.
- Chi si è **disiscritto dal servizio** resta disiscritto: il sito non lo reiscrive.

## Tipo di socio e stato della tessera

Con l'opzione **«Invia anche tipo di socio e stato della tessera»**:

- in **Brevo** servono due campi di testo, `TIPO_SOCIO` e `STATO_TESSERA`;
- in **Mailchimp** arrivano come etichette;
- **MailPoet** non li supporta.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'attivare-satispay-e-klarna',
        'title' => 'Attivare Satispay e Klarna',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale (da Pro 1.1.28, che richiede il plugin gratuito 1.2.31: aggiorna insieme gratuito e Pro; non nel plugin gratuito)

Due metodi in più per i pagamenti online. Il resto della configurazione è in [Attivare i pagamenti online con Stripe o PayPal](guida:attivare-stripe-o-paypal).

## Satispay

1. In **Satispay Business** genera un **codice di attivazione**.
2. In **Impostazioni → Pagamenti online** scegli **Satispay** (da solo o con gli altri metodi).
3. Incolla il codice in **«Collega Satispay»** e premi il pulsante. Il sito crea e conserva da solo la **chiave di firma**.
4. Premi **Verifica connessione Satispay** per controllare che tutto funzioni.

Il socio paga nell'**app Satispay**; l'incasso entra da solo in prima nota sul conto **«Satispay»**.

- Se cambi **modalità** (prova o reale) devi **ricollegare**.
- In modalità reale il sito deve essere in **https**.

## Klarna

Funziona **con Stripe attivo**.

1. In **Pagamenti online** spunta **«Offri anche Klarna»**.
2. Attivalo anche nel tuo **account Stripe**, in **Metodi di pagamento**.
3. Nel **webhook di Stripe** aggiungi l'evento `checkout.session.async_payment_failed`.

Cose da sapere:

- Si propone **solo per pagamenti in euro da 35 € a 10.000 €** e a chi ha un'**email**; altrimenti resta la sola carta.
- Con Klarna acceso il sito **elenca a mano** i metodi (carta e Klarna): gli altri metodi attivati nel pannello di Stripe, come **Link**, **non compaiono**.
MD,
    ],
    [
        'topic' => 'altro', 'section' => 'Registri', 'slug' => 'registro-azioni-e-spazio-occupato',
        'title' => 'Registro azioni: controllare lo spazio e liberarlo',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (dalla versione 1.2.28 del plugin gratuito)

Il **registro azioni** tiene traccia di ciò che si fa nel plugin (chi ha cambiato cosa e quando). Col tempo occupa spazio nel database: ora puoi vedere quanto e liberarlo.

## Vedere lo spazio occupato

1. Vai in **Impostazioni → Registro azioni**.
2. In alto leggi lo **spazio occupato** dal registro.

## Liberare spazio

1. Nella stessa pagina premi **«Libera spazio»**.
2. Conferma.

Il pulsante **elimina tutto ciò che non appartiene all'anno solare in corso**. L'operazione non si può annullare e la può fare **solo l'amministratore**. Se ti serve conservare lo storico, fai prima una [copia di sicurezza](guida:copia-di-sicurezza-e-ripristino).

## Pulizia automatica

Ogni giorno il plugin cancella da solo le righe **più vecchie di 18 mesi**. Per cambiare il limite vai in **Limiti e soglie** e scegli un valore da **6 a 120 mesi**.
MD,
    ],
];
