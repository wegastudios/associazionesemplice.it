<?php

// Guide: installazione e licenza. Testo in un sottoinsieme di Markdown (vedi App\Support\ArticleBody).
return [
    [
        'topic' => 'installazione', 'section' => 'Primi passi', 'slug' => 'installare-e-attivare-il-plugin',
        'title' => 'Installare e attivare il plugin',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Associazione Semplice si installa come qualsiasi plugin di WordPress. Ti servono un sito WordPress 6.0 o successivo e PHP 7.4 o successivo.

## Cosa fare

1. Accedi alla bacheca del tuo sito WordPress come amministratore.
2. Vai su **Plugin → Aggiungi nuovo** e cerca «Associazione Semplice». In alternativa, scegli **Carica plugin** e seleziona il file `.zip` scaricato.
3. Premi **Installa ora** e poi **Attiva**.
4. Al primo accesso si apre da sola la **configurazione guidata**: segui le domande (la guida [Completare la configurazione guidata](guida:completare-la-configurazione-guidata) ti accompagna passo per passo).

## Cosa aspettarti

- All'inizio le funzioni facoltative sono spente: la configurazione guidata accende solo ciò che ti serve.
- I testi sono in italiano, perché il plugin segue le regole del terzo settore italiano (APS, ETS e simili).
- I dati restano nel database del sito anche se disattivi il plugin. Vengono eliminati solo se, nelle impostazioni, scegli di cancellarli definitivamente quando elimini il plugin.

## Se qualcosa non va

- Se l'attivazione si interrompe con un errore, controlla di avere PHP 7.4 o successivo (lo vedi in **Strumenti → Salute del sito** di WordPress).
- Se il problema resta, [apri una richiesta](/ticket/nuovo) indicando la versione di WordPress e di PHP.
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Primi passi', 'slug' => 'completare-la-configurazione-guidata',
        'title' => 'Completare la configurazione guidata',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

La configurazione guidata è una procedura a domande che prepara il plugin per la tua associazione. Poche domande indispensabili: i dettagli stanno in **Più dettagli**, da aprire solo se servono. Ogni risposta si può cambiare in seguito dalle Impostazioni.

## Come si apre

Parte da sola alla prima attivazione. Puoi riaprirla quando vuoi da **Strumenti → Configurazione guidata**. Il pulsante **Salta per ora** la rimanda senza cambiare nulla.

## Le sezioni

1. **Ente**: nome, tipo di ente, come chiamare chi partecipa (socio, tesserato…), se accetti ospiti e chi può iscriversi. Scegliendo **«Chiunque può chiedere di iscriversi»** si accende anche la **domanda di adesione online** (pagina **«Diventa socio»**), mentre **«Solo su presentazione»** la spegne. Chi ha già configurato non cambia nulla finché non riapre la procedura guidata. Nei dettagli trovi codice fiscale, inizio dell'anno sociale e sede.
2. **Partita IVA**: rispondi sì o no. Se sì, inserisci numero e regime.
3. **Cosa ti serve**: scegli le parti che userai, per esempio corsi ed eventi, prima nota, libro soci e verbali. Una parte che spegni sparisce dal menu, ma i dati non vengono toccati.
4. **Quota associativa** e, nei dettagli, altri tipi di socio.
5. **Pagamenti dei soci**: nessuno, Stripe, PayPal, WooCommerce o bonifico (la scelta dipende dalla tua licenza).
6. **Pagine del sito**: scegli la pagina dell'informativa privacy e crea le pagine che servono.
7. **Elenco dei soci**: se vuoi, importa subito il tuo elenco da Excel o CSV.

> Le chiavi dei fornitori di pagamento e gli IBAN non si inseriscono qui: alla fine ti porta nella pagina giusta per farlo in sicurezza.

## Dopo la procedura

Apri **Impostazioni → Guida iniziale**: sette controlli sullo stato reale dei dati (denominazione, quota, soci, presidente, area soci, primo corso o evento, copia di sicurezza) ti dicono cosa manca.
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Primi passi', 'slug' => 'creare-le-pagine-del-sito',
        'title' => 'Creare le pagine del sito (area soci, ingressi, tesoriere)',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale} (alcune pagine usano funzioni Pro)

Le schermate per i soci e per chi lavora sul campo sono pagine normali del tuo sito, che mostrano contenuti del plugin tramite **shortcode**, **blocchi** o **widget Elementor**. Le pagine riservate controllano i permessi di chi le apre.

## Il modo più semplice

La configurazione guidata propone e crea le pagine per area, solo quelle che servono, e non duplica quelle già create. Se hai saltato il passo, riapri la procedura da **Strumenti → Configurazione guidata**.

## Le pagine più usate

- **Area soci**: lo spazio personale del socio (tessera, attività, pagamenti, ospiti, profilo, avvisi, calendario).
- **Segreteria**: richieste di accesso da evadere e collegamenti alla gestione, per segreteria, presidente e vicepresidente.
- **Tesoriere**: incassi, spese con foto dello scontrino, nuove iscrizioni e vendita degli eventi.
- **Ingressi**: elenco dei prenotati e registrazione degli ingressi per chi gestisce un evento.
- **Pubblico**: elenco attività, prossimi eventi, coordinate per il bonifico, 5 per mille, accesso.

## Se usi un plugin di cache

Gli elenchi pubblici di attività ed eventi e i posti liberi non restano più «vecchi» nella cache delle pagine (per esempio con LiteSpeed). Se hai pagine in cache, **svuota la cache una volta** dal plugin di cache dopo aver aggiornato il plugin.

## Creare una pagina a mano

1. Crea una pagina in WordPress.
2. Aggiungi il blocco **Associazione Semplice** (o il widget Elementor con lo stesso nome) e scegli la vista dall'elenco, ordinato per area.
3. Pubblica la pagina e aggiungila al menu del sito.

La voce **Area riservata** si aggiunge al menu di navigazione quando crei la pagina «Area soci» (non si duplica se la pagina è già in un altro menu).
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Primi passi', 'slug' => 'installare-associazione-semplice-pro',
        'title' => 'Installare Associazione Semplice Pro',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

Associazione Semplice Pro è un plugin a parte, che si aggiunge a quello gratuito. Il plugin gratuito deve restare installato e attivo.

## Cosa ti serve

- Il plugin gratuito già installato.
- Una licenza Pro valida per il dominio del tuo sito (la ricevi dopo l'acquisto o la prova gratuita).
- Il file `.zip` di Associazione Semplice Pro, che scarichi dalla tua area riservata.

## Cosa fare

1. Scarica il file di Pro dall'**area riservata** del sito associazionesemplice.it.
2. In WordPress vai su **Plugin → Aggiungi nuovo → Carica plugin**, seleziona il file e premi **Installa ora**.
3. Premi **Attiva**.
4. Vai in **Impostazioni → Licenza** e inserisci la chiave. Premi **Controlla adesso**.

Dopo il controllo compaiono nel menu le funzioni incluse nel tuo piano.

## Aggiornare

Il plugin gratuito e Pro hanno numeri di versione diversi, ma **vanno aggiornati insieme**: con un plugin gratuito vecchio, il Pro nuovo si sospende e mostra un avviso. Aggiorna prima il gratuito, poi Pro; se il sito segnala che i due non sono compatibili, aggiorna entrambi all'ultima versione.

> Se cambi piano (per esempio da {piano_contabile} a {piano_fiscale}) non devi reinstallare nulla: dopo il controllo della licenza compaiono le funzioni in più.
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Manutenzione', 'slug' => 'copia-di-sicurezza-e-ripristino',
        'title' => 'Fare una copia di sicurezza e ripristinarla',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

La copia di sicurezza è un file ZIP con tutti i dati del plugin: soci, attività, prima nota, registri e impostazioni (senza le chiavi segrete dei pagamenti). Puoi aggiungere anche gli allegati. Solo gli amministratori possono farla.

## Fare la copia

1. Vai in **Strumenti → Copia di sicurezza**.
2. Scegli se includere gli allegati.
3. Premi il pulsante per creare la copia e scarica il file.
4. Conservalo in un posto sicuro, fuori dal sito.

Se l'ultima copia ha più di 30 giorni, la Bacheca te lo ricorda.

## Ripristinare

> Il ripristino funziona sullo stesso sito e sostituisce i dati del plugin con quelli della copia, **tutto o niente**.

1. Nella stessa pagina scegli **Ripristina** e seleziona il file.
2. Prima di sostituire i dati, il plugin salva sul sito una copia dello stato attuale (ne tiene alcune, scaricabili).
3. Conferma. A operazione finita controlla soci e prima nota.

## Da sapere

- Gli utenti di WordPress non fanno parte della copia.
- La copia contiene dati personali: tienila protetta e non condividerla.
MD,
    ],
    [
        'topic' => 'installazione', 'section' => 'Manutenzione', 'slug' => 'azzerare-i-dati-e-ripartire',
        'title' => 'Azzerare i dati e ripartire da zero',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Serve, per esempio, dopo le prove prima di iniziare davvero. Solo gli amministratori possono farlo ed è un'operazione che **non si può annullare**.

## Prima di cominciare

Il sito non conserva copie dei dati cancellati, per rispetto della privacy. Prima di azzerare devi **scaricare una copia completa con gli allegati** (vale poco tempo): senza lo scarico l'azzeramento non parte. Conservala tu.

## Cosa fare

1. Vai in **Impostazioni → Sistema → Azzeramento dati**.
2. Scegli cosa azzerare e guarda l'anteprima di ciò che c'è:
   - **Solo i dati**: soci, ospiti, attività, prima nota, registri, pagamenti, comunicazioni, ricevute e allegati. Restano impostazioni, testi, pagine e registro azioni.
   - **Ripristino di fabbrica**: cancella anche impostazioni e testi e riapre la configurazione guidata. La procedura **riprende le pagine già create** invece di duplicarle.
3. Se vuoi, elimina anche gli accessi dei soci (gli utenti WordPress con solo il ruolo «Socio»: mai amministratori o altri utenti).
4. Nel riepilogo conferma con la spunta, scrivi la frase **AZZERA TUTTO** e inserisci la tua password.

La numerazione riparte da 1. L'operazione resta nel registro azioni.
MD,
    ],
    [
        'topic' => 'licenza', 'section' => 'Chiave e piani', 'slug' => 'richiedere-la-licenza-gratuita',
        'title' => 'Richiedere la licenza gratuita',
        'body' => <<<'MD'
> Disponibile in: Free

Il plugin gratuito funziona sempre, anche senza chiave: nessuna funzione di base dipende dalla licenza. La **chiave gratuita** è facoltativa e abilita i servizi collegati, come la prova Pro e l'avviso degli aggiornamenti. Crea un account che dà accesso al supporto e alle demo dei piani Pro.

## Cosa fare

1. Vai su [associazionesemplice.it/free](https://associazionesemplice.it/free).
2. Inserisci la tua email e il dominio del sito (per esempio `miaassociazione.it`).
3. Controlla la posta: ti arriva un link per confermare l'email. La chiave arriva **dopo la conferma**.
4. Apri il link ed entra nell'area riservata: lì vedi la chiave.
5. In WordPress vai in **Impostazioni → Licenza**, incolla la chiave e salva.

## Cosa viene inviato

Una volta al giorno, e quando salvi la chiave o premi **Controlla adesso**, il plugin invia al server delle licenze solo la chiave e il nome del dominio, per confermare che siano validi. Nessun dato dei soci e nessun contenuto del sito viene inviato.

> Una chiave vale per il **dominio registrato** e per **tutti i suoi sottodomini e sottocartelle** (staging, test, copie di prova).

## Durata e rinnovo

La chiave gratuita si **rinnova da sola** poco prima della scadenza, finché la usi: serve almeno un accesso recente, cioè una verifica dal plugin o il tuo ingresso nell'area riservata. Se per un po' non usi il plugin né entri nell'area riservata, la chiave scade. Il plugin gratuito continua a funzionare anche senza chiave.
MD,
    ],
    [
        'topic' => 'licenza', 'section' => 'Chiave e piani', 'slug' => 'inserire-e-controllare-la-chiave',
        'title' => 'Inserire la chiave e controllarne lo stato',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

## Inserire la chiave

1. In WordPress vai in **Impostazioni → Licenza**.
2. Incolla la chiave che trovi nell'area riservata di associazionesemplice.it.
3. Salva. Il plugin verifica subito la chiave.

## Controllare lo stato

Premi **Controlla adesso** per rifare la verifica quando vuoi. Il plugin la ripete comunque una volta al giorno. Nella pagina vedi il piano, la scadenza e lo stato.

## Cosa significano gli stati

- **Attiva**: tutto in regola. Se la scadenza è vicina o appena passata puoi vedere un invito a rinnovare: le funzioni restano disponibili per un breve periodo di tolleranza.
- **Non in regola** (pagamento mancante o scadenza): le funzioni Pro si sospendono e il plugin torna alle funzioni di base. I dati non vengono toccati.
- **Dominio non corrispondente**: la chiave è associata a un altro dominio. Puoi cambiare il dominio dall'area riservata (vedi [Cambiare il dominio della licenza](guida:cambiare-il-dominio-della-licenza)).

## Se il controllo non riesce

Un errore di rete o del server non cambia lo stato precedente: non perdi funzioni per un problema momentaneo. L'errore compare nella pagina; riprova più tardi.

> Sui siti locali (`localhost`, `*.local`, `*.test` o indirizzi IP) non serve nessuna licenza.
MD,
    ],
    [
        'topic' => 'licenza', 'section' => 'Chiave e piani', 'slug' => 'passare-a-pro-e-rinnovare',
        'title' => 'Passare a Pro, provarlo e rinnovare',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

## Prova gratis 30 giorni

Se hai già la licenza gratuita, puoi provare un piano Pro **per 30 giorni**, una volta sola, dall'area riservata del sito associazionesemplice.it.

## Acquistare un piano

1. Vai su [associazionesemplice.it](https://associazionesemplice.it) e scegli il piano nella sezione **Prezzi** (mensile o annuale).
2. Inserisci email e dominio del sito.
3. Paga con Stripe o PayPal.
4. Quando il pagamento è confermato ricevi per email il link per entrare nell'area riservata e vedere la chiave.
5. Installa Associazione Semplice Pro e inserisci la chiave (vedi [Installare Associazione Semplice Pro](guida:installare-associazione-semplice-pro)).

## Rinnovare, cambiare o disdire

I piani si rinnovano da soli. Dall'area soci puoi rinnovare, cambiare piano, aggiornare i dati di fatturazione o disdire il rinnovo in autonomia. Se non rinnovi, alla scadenza il plugin torna alle funzioni di base senza toccare i tuoi dati.

> I prezzi e le funzioni dei piani sono sempre aggiornati nella pagina [associazionesemplice.it](https://associazionesemplice.it). Per il confronto completo vedi [Free, {piano_contabile} e {piano_fiscale} a confronto](guida:free-pro-contabile-e-pro-fiscale-a-confronto).
MD,
    ],
    [
        'topic' => 'licenza', 'section' => 'Chiave e piani', 'slug' => 'cambiare-il-dominio-della-licenza',
        'title' => 'Cambiare il dominio della licenza',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Ogni licenza è legata a un dominio. Se sposti il sito su un indirizzo nuovo, aggiorna il dominio della licenza.

## Cosa fare

1. Entra nell'**area riservata** di associazionesemplice.it.
2. Apri la licenza che vuoi spostare.
3. Scegli di cambiare il dominio e scrivi quello nuovo, senza `https://` e senza `www`, per esempio `miaassociazione.it`.
4. Conferma.
5. In WordPress, nel sito con il nuovo dominio, vai in **Impostazioni → Licenza** e premi **Controlla adesso**.

## Limiti

- Il **primo cambio è libero**. Dopo un cambio la chiave resta legata al nuovo dominio per un po' di giorni (lo vedi nell'area riservata); se ti serve prima, apri una richiesta.
- Il dominio viene normalizzato: minuscolo, senza `www`, senza schema, porta e percorso.
- Un dominio già usato da un'altra licenza non può essere assegnato.

> Se il sito di prova è su un indirizzo locale o di test, non serve nessuna licenza.
MD,
    ],
];
