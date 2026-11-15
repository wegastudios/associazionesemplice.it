<?php

// Guide del gestionale online (prodotto «saas»). I nomi dei piani si scrivono solo con i segnaposto {piano_piccola}, {piano_media}, {piano_grande};
// niente prezzi né limiti numerici fissi: stanno nella pagina dei prezzi.
return [
    [
        'topic' => 'gestionale', 'section' => 'Per cominciare', 'slug' => 'gestionale-primo-accesso-e-configurazione-guidata',
        'title' => 'Primo accesso e configurazione guidata',
        'body' => <<<'MD'
> Vale per il **gestionale online** (non per il plugin WordPress). Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Entri dal tuo account: da **Il mio account** scegli **Gestionale**. Sulla Home, finché non hai finito, trovi **«Completa la configurazione»**.

## La configurazione guidata

La **Configurazione guidata** raccoglie le impostazioni principali in **una sola pagina**: compila quello che sai, il resto si cambia dopo. Si salva tutto insieme con **«Salva la configurazione»**; **«Più tardi»** ti lascia rimandare.

1. **Anno sociale e anno fiscale**: scegli i **due mesi** da cui cominciano (**«L'anno sociale comincia a»** e **«L'anno fiscale comincia a»**). Sono obbligatori e indipendenti: con inizio a gennaio l'anno coincide con quello solare («2026»); altrimenti è a cavallo («2025/2026»). Scegli il mese fiscale prima di chiudere il primo anno: dopo la prima chiusura non si può più cambiare.
2. **Dati dell'ente**: sono **obbligatori** la forma dell'ente (per esempio «APS – associazione di promozione sociale» o «ASD – associazione sportiva dilettantistica»), il **codice fiscale**, la **sede legale** (indirizzo, CAP, comune e provincia), il **legale rappresentante** (nome e cognome, di solito il presidente), l'**«Email della segreteria»** e il **«Telefono della segreteria»**. Per **partita IVA**, **numero RUNTS** e **PEC** scrivi il dato oppure, se non esiste, metti la spunta **«L'associazione non ha la partita IVA»**, **«L'associazione non è iscritta al RUNTS»** o **«L'associazione non ha una PEC»**. Il **codice destinatario** per la fattura elettronica è facoltativo. Poi c'è la **quota associativa**.
3. **Conti**: aggiungi il **conto in banca** e il suo **saldo di partenza**.
4. **Soci**: l'indirizzo della pagina dell'**informativa privacy** e quello del **regolamento**.

Dopo il salvataggio la pagina ti propone i passi successivi: **Importa i soci** e **Invita gli altri operatori**. Puoi riaprire la configurazione quando vuoi dalla Home con **«Riapri la configurazione guidata»**.

## Perché servono i dati dell'ente e i contatti della segreteria

I dati dell'ente compaiono sulle **ricevute** e sul **modulo di adesione**. L'**email** e il **telefono della segreteria** servono ai soci per sapere **a chi scrivere**: le email che il programma manda ai soci hanno la segreteria come indirizzo di risposta e finiscono con la riga «Segreteria: email · tel.»; gli stessi contatti compaiono in fondo all'**area soci** e nel **modulo di adesione**, accanto al consenso sulla privacy. Scrivi quindi un recapito che qualcuno controlla davvero.

## Perché vedo una banda in alto?

Finché manca qualche dato dell'ente, in cima a ogni pagina c'è una **banda fissa** (non si chiude): **«Mancano dei dati dell'associazione»**, con l'elenco di quello che manca. Chi amministra preme **«Completali ora»** e arriva alla **Configurazione guidata** (anche da **Altro → Configurazione guidata**), al passo **Dati dell'ente**. Chi non amministra legge **«Chiedi a chi amministra di completarli.»**. Se nell'elenco vedi **partita IVA**, **numero RUNTS** o **PEC** e l'associazione non li ha, basta mettere la spunta **«non ce l'ha»**. La banda sparisce da sola quando è tutto compilato.

## Dove trovi le cose

Il menu è diviso in quattro gruppi: **Persone** (Soci, Domande di adesione), **Attività e risorse** (Attività, Calendario, Risorse), **Cassa e conti** (Prima nota, Conti, Rendiconto, Pagamenti online) e **Gestione** (Operatori e ruoli, Esportazione dei dati, Configurazione guidata). Il pulsante **Nuovo** crea una scheda socio, un incasso, una spesa o un'attività. Alcune voci compaiono solo se il tuo piano le include o se il tuo ruolo lo permette.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Per cominciare', 'slug' => 'gestionale-prova-di-30-giorni-e-scelta-del-piano',
        'title' => 'La prova di 30 giorni e la scelta del piano',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

## La prova

La prova dura **30 giorni** e **non chiede la carta**. Puoi provare quasi tutto il gestionale; **non comprende** le **esportazioni** (dei dati, della contabilità e del libro soci) e la possibilità di **collegare un commercialista**.

## I tre piani

I piani sono **{piano_piccola}**, **{piano_media}** e **{piano_grande}**. Si distinguono per il **numero di soci**, il **numero di operatori** e le funzioni incluse; per esempio i **pagamenti online dei soci** e la **fattura elettronica** partono da {piano_media}, le **comunicazioni** e la **vita associativa** da {piano_grande}. Alcune funzioni si aggiungono a parte (per esempio il sito, le risorse e prenotazioni, lo sport, il dominio personalizzato, il database dedicato). Limiti e prezzi aggiornati sono sempre nella **pagina dei prezzi** di associazionesemplice.it: qui non li ripetiamo per non darti numeri vecchi.

{piano_piccola} e {piano_media} non hanno pacchetti: se superi il numero di soci previsto, passi al piano successivo. Solo sopra **{piano_grande}** puoi aggiungere i pacchetti **«500 soci in più»** e **«1.000 soci in più»**; i prezzi sono nella pagina dei prezzi.

## Se non scegli un piano

Finita la prova hai ancora **7 giorni**: puoi entrare e consultare tutto, ma **non inserire né modificare**. Se in quei giorni **scegli un piano**, riprendi da dove eri arrivato. Dopo 7 giorni i dati della prova vengono **cancellati**. Prima ricevi **email di avviso** con una **proposta scontata** per continuare.

## Se un piano scade

Se un piano a pagamento non viene rinnovato, il gestionale si **sospende il giorno dopo la scadenza** e i tuoi dati vengono **conservati per 180 giorni**: rinnovando, riparti da dove eri. Puoi sempre **esportare i tuoi dati** (vedi [Esportare tutti i dati](guida:gestionale-esportare-i-dati)).

## Il commercialista

Il commercialista può avere un accesso **solo fiscale**, gratuito e che **non conta tra gli operatori**, oppure un accesso **completo**, che conta come un operatore. Vedi [Operatori e ruoli](guida:gestionale-operatori-e-ruoli).
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-aggiungere-e-importare-i-soci',
        'title' => 'Aggiungere i soci e importarli da Excel o CSV',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

## Una scheda alla volta

In **Soci** premi **Nuovo → Nuova scheda socio**. Nella scheda trovi i **dati principali**, i **dati riservati** (codice fiscale, data e luogo di nascita: li vede solo chi ha il permesso di aprire le schede), il **tesseramento**, il **certificato medico** e, per i minori, i **genitori o tutori**: il tutore è chi entra nell'area soci al posto del minore.

## Tanti soci insieme

1. In **Soci** apri **Importa da Excel o CSV**.
2. Premi **«Scarica il modello»** e compila il file: servono almeno le colonne indicate nella pagina.
3. Scegli il file (**.xlsx o .csv, al massimo 5 MB**) e, se nel file manca la colonna «Tipo», indica il tipo di default.
4. Premi **«Mostra l'anteprima»**: vedi cosa verrà importato. Le righe con errori si saltano: correggile nel file e ricaricalo quando vuoi.
5. Conferma. A fine importazione trovi il riepilogo con le **righe non importate** e il collegamento **«Vai all'elenco»**.

## Trovare e esportare

In alto cerchi un socio per nome. **«Esporta l'elenco (CSV)»** scarica l'elenco; **Livelli e quote** e **Certificati medici** sono le altre viste del gruppo.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-domande-di-adesione-online',
        'title' => 'Le domande di adesione online',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

La pagina **Domande di adesione** ha tre parti: le domande **da esaminare**, quelle **decise di recente** e le impostazioni **«Domande online e promemoria»**.

## Pubblicare il modulo

Nelle impostazioni della pagina trovi l'**indirizzo del modulo da pubblicare sul sito**, l'**indirizzo dell'informativa privacy**, quello del **regolamento** e i **giorni di preavviso per la tessera** (quando avvisare chi sta per scadere). Per far arrivare le domande spunta **«Accetta domande di adesione online»**: compare l'indirizzo del modulo.

## Esaminare una domanda

Apri la domanda dall'elenco **«Da esaminare»**, controlla i dati e decidi. Una domanda decisa passa tra le **«Decise di recente»**. Se non ce ne sono, la pagina dice «Nessuna domanda in attesa».
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-rinnovare-una-tessera',
        'title' => 'Rinnovare una tessera e incassare la quota',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Dalla scheda del socio, nella parte **Tesseramento**, premi **«Incassa e rinnova»**: l'incasso va in **prima nota** con la ricevuta, e la tessera viene rinnovata. Se **storni** quell'incasso, la tessera non vale più; dopo lo storno puoi ripetere la quota.

## Se il socio ha già la tessera

Se il socio ha **già la tessera di quell'anno**, il gestionale non registra la quota una seconda volta e ti dice, per esempio, **«Il socio ha già la tessera 2026.»**. Dalla scheda del socio si ferma tutto: nessun incasso, nessuna ricevuta, nessuna tessera.

## Se il socio ha già pagato online

Se la quota era già presente quando arriva il **pagamento online** dall'area soci, la quota non viene registrata e il pagamento resta segnato **«da controllare»**. Il socio vede «Pagamento ricevuto: la segreteria lo sta controllando.». Se nello stesso pagamento ci sono altre voci (per esempio un'attività), quelle si registrano.

I soldi sono arrivati sul **conto Stripe o PayPal dell'associazione**, ma per quella quota non ci sono né movimento né ricevuta nel gestionale. **Cosa fare:**

1. Apri **Pagamenti online**, sezione **«Pagamenti dei soci»**, e leggi il **motivo**, scritto in rosso sotto il nome nella colonna Voci.
2. Se è la quota già pagata, premi **«rimborsa»** e scrivi **quanto restituire**: tutto o una parte (minimo **0,50 €**), anche in più volte. Ti chiede la **password** e il **motivo del rimborso**.

   Il motivo è **obbligatorio** e lo **leggerà il socio** nell'email: scrivilo in modo chiaro e gentile, perché il socio lo legge così com'è. I soldi tornano sul metodo di pagamento del socio: **il rimborso non si può annullare**.

   Se il pagamento era già stato registrato, vedi sotto.

   **Non vedi «rimborsa»?** Serve il permesso **«Emettere rimborsi»**: chiedilo a chi amministra il gestionale.
3. Dopo l'invio compare «Rimborso di X,XX € inviato al socio. Il socio è stato avvisato per email.» e sotto lo stato leggi **«restituiti X,XX €»**.

   Il socio riceve un'email con oggetto «Rimborso del tuo pagamento» e il nome dell'associazione, con **importo**, **data del pagamento**, **motivo** e **tempi di accredito** (in genere pochi giorni lavorativi, fino a 10); in fondo ci sono i **contatti della segreteria** (email e telefono), se li hai inseriti nei **Dati dell'ente**.  Se il socio non ha un'email, il gestionale te lo dice: «Il socio non ha un'email: avvisalo tu.».
4. Stripe o PayPal di solito non restituiscono la loro commissione: chiedi al tuo commercialista se e come registrarla.
5. Premi **«controllato»** accanto allo stato: serve solo a dire «ho visto e sistemato», non registra e non rimborsa nulla (il rimborso lo fa «rimborsa»). Lo vede chi ha il permesso di contabilità.

## Restituire un pagamento già registrato

Se il pagamento era **già stato registrato** (c'è la ricevuta in Prima nota e la tessera), premi **«rimborsa e storna»**. In un gesto solo:

- **restituisce tutto il pagamento**;
- **storna le ricevute** in Prima nota;
- **annulla le tessere** collegate.

Ti chiede la **password** e il **motivo del rimborso**, che il socio leggerà nell'email. **Restituisce sempre tutto il pagamento**: qui non si può restituire solo una parte. **Non si può annullare.** A operazione finita compare «Rimborsato e stornato: ricevute e tessere annullate. Il socio è stato avvisato per email.».

Se l'**anno fiscale dell'incasso è chiuso**, l'operazione non parte: né rimborso né storno. Se il rimborso non riesce, non cambia nulla: ricevute e tessere restano come prima. Serve lo stesso permesso **«Emettere rimborsi»**. Il rimborso **parziale** c'è solo per i soldi non registrati, con «rimborsa».

## Se l'importo pagato è diverso

Se l'importo incassato è diverso da quello atteso, il motivo è «Incassati X invece di Y: da controllare» e in quel caso **nessuna voce** viene registrata: leggi il motivo nella stessa pagina e premi «controllato» quando hai sistemato.

L'anno della tessera lo decide il programma, non il socio.

MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-registrare-un-incasso-e-una-spesa',
        'title' => 'Registrare un incasso e una spesa',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Tutti i movimenti stanno nella **Prima nota**: l'elenco di entrate e uscite in ordine di data.

## Registrare

1. Dal pulsante **Nuovo** (o dalla **Prima nota**) scegli **«Registra un incasso»** o **«Registra una spesa»**.
2. Scegli il **conto**, l'importo, la data e, se serve, l'**attività** collegata. Puoi dividere il movimento in più voci con **«Aggiungi un'altra voce»** e **«Togli questa voce»**.
3. Salva. L'incasso dà la **ricevuta**; con **«Invia per email»** la mandi alla persona. Se l'associazione ha la partita IVA, ogni voce ha anche la sua **aliquota IVA** (vedi [IVA: la pagina «Aspetto fiscale»](guida:gestionale-iva-e-aspetto-fiscale)).

### Perché non riesco a inviare la ricevuta per email?

Se mancano l'**email** e il **telefono della segreteria**, il pulsante **«Invia per email»** non invia e compare: «Prima inserisci email e telefono della segreteria (Altro › Configurazione guidata › Dati dell'ente): senza, non puoi inviare la ricevuta per email. Intanto scarica la ricevuta e consegnala a mano.» Inseriscili nei **Dati dell'ente** e riprova (se non amministri, chiedilo a chi amministra). Lo stesso avviso, in forma diversa, c'è se chi ha pagato non ha un'email in anagrafica: in quel caso scarica la ricevuta e consegnala a mano. Gli invii **automatici**, come il link di accesso all'area soci e l'avviso di rimborso, partono comunque.

### La ricevuta non cambia più

Il **PDF di una ricevuta si crea una volta sola**, quando viene emessa, e non cambia più, nemmeno se poi cambiano i dati del socio o quelli dell'associazione. Quando la scarichi o la spedisci è sempre lo **stesso file**: il programma controlla che sia quello emesso.

Le ricevute emesse **prima di questo aggiornamento** vengono fissate in automatico nelle ore successive (o subito, dalla pagina «Verifica delle ricevute»). Per quelle il PDF usa i testi e i dati di oggi; le ricevute emesse da ora in poi restano identiche per sempre.

### Perché una ricevuta non si scarica?

- **Se sei tu a scaricarla** e il file manca o non coincide con quello emesso, il gestionale non la rifà di nascosto e ti avvisa; un amministratore può controllare da **«Verifica delle ricevute»**, come spiegato qui sotto. Se non si riesce a rifarla, scrivi all'assistenza di Associazione Semplice.
- **Se il socio la scarica dall'area soci** e c'è lo stesso problema, legge: «Questa ricevuta non è disponibile in questo momento. Avvisa la segreteria: {email}, tel. {telefono}.» con i contatti che hai inserito nei Dati dell'ente.
- Una ricevuta **annullata** non si scarica più: il file emesso resta conservato, ma non è più disponibile.

### La pagina «Verifica delle ricevute»

La trovi in **Altro**, ed è **solo per gli amministratori**. Scegli l'**anno fiscale** e vedi l'elenco delle ricevute con il loro stato:

- **«A posto»**: il file è quello emesso;
- **«Da fissare»**: ricevuta emessa prima dell'aggiornamento, non ancora fissata; il pulsante **«Fissa i prossimi PDF»** le fissa a gruppi (se ne restano, premilo ancora);
- **«Mancante»** e **«Alterato»**: il file non c'è più o non coincide con quello emesso; il pulsante **«Rifai il PDF»** lo rifà. Si rifà **solo se risulta identico a quello emesso**; altrimenti non cambia nulla e compare un messaggio che ti dice di scrivere all'assistenza di Associazione Semplice. L'operazione resta scritta nel registro azioni;
- **«Annullata»**: «La ricevuta è stata annullata: il file emesso resta conservato ma non si scarica più.»

## Cercare e filtrare

In **Prima nota** filtra per **conto**, per **attività** e per periodo, poi **«Mostra i movimenti»**. **«Esporta l'anno fiscale (CSV)»** scarica i movimenti; **«Importa da Excel o CSV»** li carica.

## Correggere un errore

Un movimento sbagliato non si cancella: si **storna** con **«Storna il movimento»**. Scrivi **perché**: il movimento resta in elenco ma non conta più.

Per spostare soldi da un conto all'altro (per esempio contanti versati in banca) usa **Giroconto**: non è né un incasso né una spesa.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-iva-e-aspetto-fiscale',
        'title' => 'IVA: la pagina «Aspetto fiscale»',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

**Se la tua associazione non ha la partita IVA, questa pagina non ti serve: incassi e spese si registrano con il loro importo e basta.**

Per il gestionale conta una sola cosa: l'associazione ha la **partita IVA** o no.

## Se non hai la partita IVA

**L'IVA non si applica**: incassi e spese si registrano con il loro importo, senza aliquote. Non compare nessuna dicitura sull'IVA, né in prima nota né sulle ricevute. In **Aspetto fiscale** trovi solo un promemoria: «Se l'associazione ha la partita IVA, indicala in Altro › Configurazione guidata › Dati dell'ente: qui compariranno le aliquote.»

## Se hai la partita IVA

Scrivila in **Altro → Configurazione guidata**, passo **Dati dell'ente** (vedi [Primo accesso e configurazione guidata](guida:gestionale-primo-accesso-e-configurazione-guidata)). Poi apri **Altro → Gestione → Aspetto fiscale** (la vedi se puoi cambiare le impostazioni) e scegli:

- **«Aliquote IVA che usi»**: i numeri separati da virgola. Di partenza sono **22, 10, 5, 4**. **«IVA esente»** c'è sempre, anche se non la scrivi.
- **«I prezzi che inserisci sono»**: **«finali, con l'IVA compresa»** (la scelta di partenza) oppure **«senza IVA: l'IVA si aggiunge»**. Con i prezzi senza IVA, sotto l'importo vedi subito **«Totale con IVA»**, cioè quanto pagherà chi paga.
- **«Aliquota della quota associativa»**: **IVA esente** oppure una delle aliquote che hai scelto. Di partenza è **IVA esente**.

Premi **«Salva»**.

## In prima nota

Con la partita IVA, ogni voce di incasso e ogni spesa ha la sua **aliquota IVA**: il gestionale propone il **22%** e tu scegli quella giusta. Le scelte sono le aliquote che hai indicato più:

- **«IVA esente»**;
- **«Fuori campo IVA»**;
- **«Reverse charge»**, **solo sulle spese**: la spesa si registra **senza IVA esposta**. Non si può scegliere insieme a un'aliquota.

Se non sai quale voce usare, **chiedi al tuo commercialista**: il gestionale registra quella che scegli, non decide per te.

L'IVA compare nel dettaglio del movimento. Sulla **ricevuta** in PDF vedi, per ogni aliquota, l'imponibile e l'IVA; con l'aliquota «IVA esente» la riga dice «di cui IVA esente». Se provi a usare un'aliquota che non hai scelto in **Aspetto fiscale**, il gestionale ti dice che non è tra quelle scelte.

## Se togli un'aliquota dall'elenco

Se togli un'aliquota dall'elenco, i movimenti già registrati con quella aliquota non cambiano. Se togli l'aliquota usata per la quota associativa, la pagina non si salva e ti dice: «Prima cambia l'aliquota della quota associativa: deve essere «IVA esente» o una di quelle dell'elenco. I movimenti già registrati restano come sono.»

## IVA su attività, corsi ed eventi

Con la partita IVA, nella **scheda di un'attività** (un corso o un evento) trovi la casella **«Con IVA»**:

- **senza la spunta**, gli incassi di quell'attività si registrano come **«Fuori campo IVA»**;
- **con la spunta**, scegli dal menu **«IVA esente»** oppure una delle aliquote che hai indicato in Aspetto fiscale («IVA 22%», «IVA 10%»…).

Sotto il menu la scheda ti ricorda come leggere gli importi **«Importo per i soci»** e **«Importo per gli ospiti»**:

- se in Aspetto fiscale hai scelto i prezzi **senza IVA**: «Gli importi che scrivi sono senza IVA: chi paga vede l'importo con l'IVA aggiunta.» Per esempio, un corso da **100,00 €** con **IVA 22%**: chi paga vede **122,00 €**;
- se hai scelto i prezzi **finali**: «Gli importi che scrivi sono finali, con l'IVA compresa.»

Gli incassi di corsi, eventi e prenotazioni nascono in prima nota **con l'aliquota dell'attività**. Le attività che esistevano già restano **senza IVA** finché non le cambi tu. Se non sai se un'attività va con IVA, esente o fuori campo, **chiedi al tuo commercialista**.

Il gestionale ti avvisa se qualcosa non torna:

- se provi a togliere da Aspetto fiscale un'aliquota usata da qualche attività: «Alcune attività usano l'aliquota X%: prima cambia l'IVA di quelle attività, poi toglila dall'elenco.»;
- se nei Dati dell'ente spunti «L'associazione non ha la partita IVA» e ci sono attività con l'IVA: «Hai N attività con l'IVA: senza partita IVA l'IVA non si applica più a nessuna. I movimenti già registrati non cambiano.»;
- in scheda, un avviso rosso: «L'aliquota di questa attività non è più nell'elenco di Aspetto fiscale: scegline un'altra.» oppure «Senza partita IVA l'IVA non si applica: gli incassi di questa attività si registrano senza IVA.»

## Cosa non c'è ancora

Non si può ancora impostare l'IVA sulle risorse e sulle prenotazioni di spazi.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-conti-e-giroconti',
        'title' => 'I conti: cassa, banca e giroconti',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

In **Conti** dichiari **dove stanno i soldi** dell'associazione: la cassa, il conto in banca, il conto PayPal. Ogni incasso e ogni spesa passa da uno di questi.

1. Premi **«Nuovo conto»**, dagli un nome e indica il **saldo iniziale**.
2. Per un POS fisico puoi indicare la **commissione trattenuta a ogni incasso**: a ogni incasso la spesa corrispondente va in prima nota.
3. Premi **«Crea il conto»**.

Per spostare soldi tra due conti usa il **Giroconto tra conti** dalla **Prima nota**.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-chiudere-l-esercizio-e-leggere-il-rendiconto',
        'title' => 'Chiudere l\'anno fiscale e leggere il rendiconto',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

## Il rendiconto per cassa

Il **Rendiconto** mostra **entrate e uscite dell'anno per categoria**, nello schema del **Mod. D** degli enti del Terzo settore: è il documento da portare in assemblea e al commercialista. In fondo vedi l'**avanzo (o disavanzo)** dell'anno fiscale. Con **«Stampa o salva in PDF»** lo ottieni in un file.

Prima della chiusura il rendiconto è una **bozza**: i numeri sono quelli di oggi e possono cambiare. Alla chiusura dell'anno fiscale diventa **definitivo**: emesso e bloccato, non cambia più.

## Chiudere l'anno fiscale

Nella stessa pagina vedi **inizio e fine dell'anno fiscale**. Quando il rendiconto è approvato premi **«Emetti il rendiconto definitivo»** (con l'anno): da quel momento non si registrano né si stornano movimenti con date di quell'anno. Se serve correggere qualcosa, **«Riapri l'anno fiscale»**: scrivi il **motivo della riapertura** e l'anno per confermare, e il motivo resta in traccia. Se lo riapri e lo richiudi nasce una nuova versione del rendiconto; le precedenti restano elencate.

> Chiudi l'anno fiscale solo dopo aver controllato la prima nota e il rendiconto.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Attività', 'slug' => 'gestionale-creare-un-corso-o-un-evento',
        'title' => 'Creare un corso o un evento',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

In **Attività** trovi i corsi e gli eventi dell'anno sociale: ognuno raccoglie i **suoi incassi e le sue spese**.

1. Premi **«Nuova attività»** (o **Nuovo → Nuova attività**).
2. Compila nome, **importo per i soci** e **importo per gli ospiti**. Per un corso scegli i **giorni delle lezioni**: il mese è dovuto dalla prima lezione del mese.
3. Facoltativi: il **referente** (un socio volontario) e un **listino** (uno per riga: nome e prezzo; per un carnet «x» e gli ingressi). Senza listino vale l'importo per i soci o per gli ospiti.
4. Con la funzione **Risorse** puoi indicare **dove si svolge**; più attività sulla stessa risorsa ne condividono il calendario.

Se l'associazione ha la partita IVA, nella scheda trovi anche la casella **«Con IVA»**: vedi [IVA: la pagina «Aspetto fiscale»](guida:gestionale-iva-e-aspetto-fiscale).

Nella scheda vedi gli **iscritti**, il **registro presenze** e i **carnet** con ingressi ancora da usare. Con **«Chiudi l'iscrizione»** non si accettano altre iscrizioni. Il **Calendario** mostra tutte le date.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Pagamenti', 'slug' => 'gestionale-pagamenti-online-dei-soci',
        'title' => 'Pagamenti online dei soci con Stripe o PayPal',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_media}, {piano_grande}

I soci pagano dall'**area soci** con il **conto Stripe o PayPal dell'associazione**: i soldi arrivano a voi. Ogni pagamento va da solo in **prima nota** con la **ricevuta**.

1. Apri **Pagamenti online**.
2. Scegli il **conto della prima nota per gli incassi online**: di solito un conto «Stripe» o «PayPal» di tipo banca, da girocontare quando arrivano i bonifici.
3. Per **Stripe (carte)** inserisci la **chiave segreta** e il **segreto del webhook**; nel pannello di Stripe aggiungi il **webhook** all'indirizzo indicato nella pagina. Per **PayPal** inserisci le chiavi e, quando sei pronto, passa alla modalità reale.
4. Premi **«Prova le chiavi»**: la pagina ti dice se funzionano.

In fondo trovi l'elenco dei **pagamenti dei soci** con lo stato (per esempio **controllato**).

> Le chiavi sono segrete: non mandarle per email e non condividerle.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Gestione', 'slug' => 'gestionale-operatori-e-ruoli',
        'title' => 'Operatori e ruoli, anche per il commercialista',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande} (il numero di operatori dipende dal piano)

In **Operatori e ruoli** decidi **chi entra nel gestionale e cosa può fare**.

## Invitare un operatore

Nella sezione **Operatori** scrivi **nome** ed **email**, scegli il **ruolo** e premi **«Invita»**: la persona riceve un'email. Accedono con il loro account Associazione Semplice: un solo accesso per tutte le aree.

## I ruoli

- **Ruoli fissi**: titolare, amministratore, operatore e **commercialista**. L'abbonamento, le impostazioni e gli operatori restano a titolare e amministratore.
- **Commercialista**: legge la contabilità e l'anagrafica ma **non modifica**; non vede i dati sanitari né l'anagrafica con i dati sensibili.
- **Ruoli dell'associazione**: con **«Nuovo ruolo»** crei ruoli per segretari, consiglieri, tesorieri… scegliendo i permessi.

Cambi il ruolo di un operatore dalla tendina accanto al suo nome.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Gestione', 'slug' => 'gestionale-esportare-i-dati',
        'title' => 'Esportare tutti i dati',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande} (non durante la prova)

In **Esportazione dei dati** scarichi l'**archivio completo** con **«Scarica l'archivio»**. La prima nota e l'anagrafica si possono ricaricare con gli import.

- L'archivio contiene **dati personali e sanitari**: conservalo con cura e cancellalo quando non serve più. Resta traccia di chi lo scarica.
- I **file allegati ai certificati medici** non sono compresi: si scaricano dalla scheda della persona.
MD,
    ],
];
