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

1. **Ente**: forma dell'ente, codice fiscale, partita IVA (se c'è), sede e provincia.
2. **Tesseramento**: il mese in cui comincia l'**anno sociale** e la **quota associativa**. Con inizio a gennaio l'anno sociale coincide con quello solare («2026»); altrimenti è a cavallo («2025/2026»).
3. **Conti**: aggiungi il **conto in banca** e il suo **saldo di partenza**.
4. **Soci**: l'indirizzo della pagina dell'**informativa privacy** e quello del **regolamento**.

Dopo il salvataggio la pagina ti propone i passi successivi: **Importa i soci** e **Invita gli altri operatori**. Puoi riaprire la configurazione quando vuoi dalla Home con **«Riapri la configurazione guidata»**.

## Dove trovi le cose

Il menu è diviso in quattro gruppi: **Persone** (Soci, Domande di adesione), **Attività e risorse** (Attività, Calendario, Risorse), **Soldi** (Prima nota, Conti, Rendiconto, Pagamenti online) e **Gestione** (Operatori e ruoli, Esportazione dei dati, Configurazione guidata). Il pulsante **Nuovo** crea una scheda socio, un incasso, una spesa o un'attività. Alcune voci compaiono solo se il tuo piano le include o se il tuo ruolo lo permette.
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

Dalla scheda del socio, nella parte **Tesseramento**, premi **«Incassa e rinnova»**: l'incasso va in **prima nota** con la ricevuta, e la tessera viene rinnovata. Se **storni** quell'incasso, la tessera non vale più.

- **Tessera senza incasso**: se rinnovi senza un pagamento (per esempio una tessera regalata), scrivi il **motivo** e premi **«Registra la tessera»**.
- **Socio fondatore**: la tessera è sempre rinnovata.
- Il quadro del tesseramento (quote, livelli) è in **Soci → Livelli e quote**.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soldi', 'slug' => 'gestionale-registrare-un-incasso-e-una-spesa',
        'title' => 'Registrare un incasso e una spesa',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Tutti i movimenti stanno nella **Prima nota**: l'elenco di entrate e uscite in ordine di data.

## Registrare

1. Dal pulsante **Nuovo** (o dalla **Prima nota**) scegli **«Registra un incasso»** o **«Registra una spesa»**.
2. Scegli il **conto**, l'importo, la data e, se serve, l'**attività** collegata. Puoi dividere il movimento in più voci con **«Aggiungi un'altra voce»** e **«Togli questa voce»**.
3. Salva. L'incasso dà la **ricevuta**; con **«Invia per email»** la mandi alla persona.

## Cercare e filtrare

In **Prima nota** filtra per **conto**, per **attività** e per periodo, poi **«Mostra i movimenti»**. **«Esporta l'esercizio (CSV)»** scarica i movimenti; **«Importa da Excel o CSV»** li carica.

## Correggere un errore

Un movimento sbagliato non si cancella: si **storna** con **«Storna il movimento»**. Scrivi **perché**: il movimento resta in elenco ma non conta più.

Per spostare soldi da un conto all'altro (per esempio contanti versati in banca) usa **Giroconto**: non è né un incasso né una spesa.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soldi', 'slug' => 'gestionale-conti-e-giroconti',
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
        'topic' => 'gestionale', 'section' => 'Soldi', 'slug' => 'gestionale-chiudere-l-esercizio-e-leggere-il-rendiconto',
        'title' => 'Chiudere l\'esercizio e leggere il rendiconto',
        'body' => <<<'MD'
> Vale per il **gestionale online**. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

## Il rendiconto per cassa

Il **Rendiconto** mostra **entrate e uscite dell'anno per categoria**, nello schema del **Mod. D** degli enti del Terzo settore: è il documento da portare in assemblea e al commercialista. In fondo vedi l'**avanzo (o disavanzo)** dell'esercizio. Con **«Stampa o salva in PDF»** lo ottieni in un file.

## Chiudere l'esercizio

Nella stessa pagina vedi **inizio e fine dell'esercizio**. Con **«Chiudi l'esercizio»** non accetta più incassi né spese. Se serve correggere qualcosa, **«Riapri l'esercizio»** (scrivi il **motivo della riapertura**: resta traccia).

> Chiudi l'esercizio solo dopo aver controllato la prima nota e il rendiconto.
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
