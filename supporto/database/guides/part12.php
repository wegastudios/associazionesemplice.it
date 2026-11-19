<?php

// Guide del gestionale online (prodotto «saas»). I nomi dei piani si scrivono solo con i segnaposto {piano_piccola}, {piano_media}, {piano_grande};
// niente prezzi né limiti numerici fissi: stanno nella pagina dei prezzi.
return [
    [
        'topic' => 'gestionale', 'section' => 'Per cominciare', 'slug' => 'gestionale-primo-accesso-e-configurazione-guidata',
        'title' => 'Primo accesso e configurazione guidata',
        'body' => <<<'MD'
> Vale per il gestionale online (non per il plugin WordPress). Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Entri da associazionesemplice.it: in **Il mio account** premi **Apri il gestionale**. Finché la configurazione non è completa, sulla Home trovi **Completa la configurazione**.

## La configurazione guidata

La **Configurazione guidata** raccoglie le impostazioni principali in una sola pagina: compila quello che sai, il resto si cambia dopo. Si salva tutto insieme con **«Salva la configurazione»**; **«Più tardi»** ti lascia rimandare.

1. **Anno delle attività e anno fiscale**: scegli il mese da cui comincia ciascuno. L'anno delle attività riguarda solo corsi ed eventi; tessera, quote e incassi seguono l'anno fiscale. Con inizio a gennaio l'anno coincide con quello solare («2026»), altrimenti è a cavallo («2025/2026»). Il mese dell'anno fiscale non si cambia più dopo la prima chiusura.
2. **Dati dell'ente**: forma dell'ente, codice fiscale, sede legale, legale rappresentante (di solito il presidente), email e telefono della segreteria sono obbligatori. Per partita IVA, numero RUNTS e PEC scrivi il dato oppure metti la spunta che dice che l'associazione non ce l'ha. Il codice destinatario per la fattura elettronica è facoltativo.
3. **Quota associativa**: l'importo della quota.
4. **Conti e saldi di partenza**: aggiungi il conto in banca e il suo saldo di partenza.
5. **Domande di adesione e promemoria**: l'indirizzo della pagina dell'informativa privacy e quello del regolamento, e se far arrivare le domande online e i promemoria di tessera e certificato medico.
6. **Indirizzo dell'area soci**: l'indirizzo da dare ai soci, con il pulsante **Copia**.

Alcuni passi compaiono solo se il tuo piano li include.

Dopo il salvataggio la pagina ti propone i passi successivi: **Importa i soci** e **Invita gli altri operatori**. Puoi riaprire la configurazione quando vuoi dalla Home con **Riapri la configurazione guidata**.

## Se cambi il mese dell'anno fiscale

Compare l'anteprima «Cosa succede alle tessere»: quante tessere degli anni fiscali aperti passano al nuovo anno (per esempio «2025/2026 → 2026») e quante restano «sostituite», perché la persona ha già la tessera dello stesso anno e resta valida la più recente. Le tessere degli anni chiusi non cambiano.

1. Metti la spunta «Ho letto cosa succede alle tessere e voglio cambiare il mese dell'anno fiscale.»
2. Premi **Salva e riallinea le tessere**.

Alla fine leggi quante tessere sono state riallineate e quante sostituite. Ogni tessera cambiata resta scritta nel registro delle azioni, con l'etichetta e le date di prima.

## Perché servono i dati dell'ente e i contatti della segreteria

I dati dell'ente compaiono sulle ricevute e sul modulo di adesione. L'email e il telefono della segreteria servono ai soci per sapere a chi scrivere: le email che il gestionale manda ai soci hanno la segreteria come indirizzo di risposta e finiscono con la riga «Segreteria: email · tel.». Gli stessi contatti compaiono in fondo all'area soci e nel modulo di adesione. L'oggetto delle email comincia sempre con il nome dell'associazione. Scrivi quindi un recapito che qualcuno controlla davvero.

## Perché vedo una banda in alto?

Finché manca qualche dato dell'ente, in cima a ogni pagina c'è una banda fissa (non si chiude): «Mancano dei dati dell'associazione», con l'elenco di quello che manca. Chi amministra preme **Completali ora** e arriva alla **Configurazione guidata**, al passo **Dati dell'ente**. Chi non amministra legge «Chiedi a chi amministra di completarli.». Se l'associazione non ha partita IVA, numero RUNTS o PEC, metti la spunta corrispondente (per esempio «L'associazione non ha una PEC»). La banda sparisce da sola quando è tutto compilato.

## Dove trovi le cose

Il menu è diviso in quattro gruppi: **Persone** (Soci, Domande di adesione), **Attività e risorse** (Attività, Calendario, Risorse), **Cassa e conti** (Prima nota, Conti, Rendiconto, Pagamenti online) e **Gestione** (Abbonamento e pacchetti, Operatori e ruoli, Aspetto fiscale, Verifica delle ricevute, Copie di sicurezza, Configurazione guidata). Sul telefono trovi tutti i gruppi in **Altro**. Il pulsante **Nuovo** crea una scheda socio, un incasso, una spesa, un'attività o una risorsa. Alcune voci compaiono solo se il tuo piano le include o se il tuo ruolo lo permette.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Per cominciare', 'slug' => 'gestionale-prova-di-30-giorni-e-scelta-del-piano',
        'title' => 'La prova di 30 giorni e la scelta del piano',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

## La prova

La prova dura 30 giorni e non chiede la carta. Puoi provare quasi tutto il gestionale; non comprende le esportazioni (dei dati, della contabilità e del libro soci) e la possibilità di collegare un commercialista.

## I tre piani

I piani sono {piano_piccola}, {piano_media} e {piano_grande}. Si distinguono per il numero di soci, il numero di operatori e le funzioni incluse; per esempio i pagamenti online dei soci e la fattura elettronica partono da {piano_media}, le comunicazioni e la vita associativa da {piano_grande}. Alcune funzioni si aggiungono a parte (per esempio il sito, le risorse e prenotazioni, lo sport, il dominio personalizzato, il database dedicato). Limiti e prezzi aggiornati sono nella pagina dei prezzi di associazionesemplice.it.

{piano_piccola} e {piano_media} non hanno pacchetti: se superi il numero di soci previsto, passi al piano successivo. Solo con {piano_grande} puoi aggiungere i pacchetti «500 soci in più» e «1.000 soci in più».

## Se non scegli un piano

Finita la prova hai ancora 7 giorni: puoi entrare e consultare tutto, ma non inserire né modificare. Se in quei giorni scegli un piano, riprendi da dove eri arrivato. Dopo 7 giorni i dati della prova vengono cancellati. Prima ricevi alcune email di avviso.

## Se un piano scade

Se un piano a pagamento non viene rinnovato, il gestionale si sospende il giorno dopo la scadenza e i tuoi dati vengono conservati per 180 giorni: rinnovando, riparti da dove eri. Prima della scadenza ti conviene scaricare una copia di sicurezza (vedi [Copie di sicurezza e documenti da consegnare](guida:gestionale-esportare-i-dati)).

## Il commercialista

Il commercialista può avere un accesso solo fiscale, gratuito e che non conta tra gli operatori, oppure un accesso completo, che conta come un operatore. Vedi [Invitare gli operatori e scegliere i ruoli](guida:gestionale-operatori-e-ruoli).
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-aggiungere-e-importare-i-soci',
        'title' => 'Aggiungere i soci e importarli da Excel o CSV',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

## Una scheda alla volta

In **Soci** premi **Nuovo → Nuova scheda socio**. La scheda raccoglie i dati principali, la tessera, il certificato medico e, per i minori, i genitori o tutori (sono loro a entrare nell'area soci al posto del minore). Codice fiscale, data e luogo di nascita li vede solo chi ha il permesso di aprire le schede.

## Tanti soci insieme

1. In **Soci** apri **Importa da Excel o CSV**.
2. Premi **«Scarica il modello»** e compila il file: servono almeno le colonne indicate nella pagina.
3. Scegli il file (.xlsx o .csv, al massimo 5 MB) e, se nel file manca la colonna «Tipo», indica il tipo di default.
4. Premi **«Mostra l'anteprima»**: vedi cosa verrà importato. Le righe con errori si saltano: correggile nel file e ricaricalo quando vuoi.
5. Conferma. A fine importazione trovi il riepilogo con le righe non importate e il collegamento **«Vai all'elenco»**.

Se nel file ci sono tessere con un'etichetta che non corrisponde all'anno fiscale (per esempio «2025» con l'anno fiscale che non inizia a gennaio), l'anteprima mostra il riquadro **«Tessere con anno diverso dall'anno fiscale»**: con la conferma passano all'anno fiscale che le contiene (per esempio «2025/2026»). Per importare devi mettere la spunta **«Ho letto cosa succede alle tessere e voglio importarle così.»**

## Trovare ed esportare

In alto cerchi un socio per nome. Il pulsante con i tre puntini (**Altri strumenti**) apre **Esporta l'elenco (CSV)**, per chi può esportare, e le pagine **Libro soci**, **Livelli e quote**, **Certificati medici** e **Dati per il RUNTS**.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-domande-di-adesione-online',
        'title' => 'Ricevere ed esaminare le domande di adesione online',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

La pagina **Domande di adesione** ha tre parti: le domande da esaminare, quelle decise di recente e le impostazioni **«Domande online e promemoria»**.

## Pubblicare il modulo

Nelle impostazioni della pagina trovi l'indirizzo del modulo da pubblicare sul sito, l'indirizzo dell'informativa privacy, quello del regolamento e i giorni di preavviso per la tessera (quanti giorni prima della scadenza avvisare il socio). Per far arrivare le domande spunta **«Accetta domande di adesione online»**: compare l'indirizzo del modulo.

## Esaminare una domanda

Apri la domanda dall'elenco «Da esaminare», controlla i dati e decidi. La domanda decisa passa tra le «Decise di recente»; se non ce ne sono da esaminare, la pagina dice «Nessuna domanda in attesa».

Chi ha fatto la domanda riceve un'email quando la domanda è ricevuta, accolta o non accolta; se non è accolta, l'email riporta i recapiti della segreteria.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-area-soci-per-la-segreteria',
        'title' => 'Area soci: come entrano i soci e cosa trovano',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_media}, {piano_grande}

L'area soci è la parte del gestionale che il socio apre dal telefono o dal computer, senza chiedere nulla alla segreteria: vede la sua tessera, le quote e le ricevute, gli eventi, il suo profilo e, se l'associazione lo offre, può prenotare gli spazi.

## L'indirizzo

L'indirizzo dell'area soci lo trovi in **Configurazione guidata** (menu **Gestione**; sul telefono **Altro**), nel passo **Indirizzo dell'area soci**, con il pulsante **Copia**: daglielo tu (sul sito, in una email, in un messaggio). Di solito è del tipo https://nome-breve.associazionesemplice.it/area. Se l'associazione ha un dominio proprio verificato, l'area soci risponde anche lì. Il link di accesso, il codice e il QR della tessera restano sullo stesso indirizzo da cui il socio è entrato. Per spiegare ai soci come entrare puoi mandare la pagina [Come entro nell'area soci](guida:gestionale-come-entro-nell-area-soci).

## Come entra un socio

- Se ha un'email registrata, entra da solo: scrive la sua email, riceve un link e un codice di 6 cifre nella stessa email e usa l'uno o l'altro. Non c'è nessuna password da ricordare. Link e codice valgono 15 minuti e una volta sola; il codice si può sbagliare al massimo 5 volte. Se l'email non è di un socio, la pagina risponde allo stesso modo ma non parte nessuna email.
- Se non ha un'email, apri la sua scheda, scheda **«Altro»**, sezione **«Area soci»**, e premi **«Prepara il link d'accesso»**, poi **«Copia»**. Il link vale 7 giorni, una volta sola: mandalo per esempio su WhatsApp. Il pulsante non c'è per gli ospiti.

Una volta dentro, il socio resta collegato fino a **30 giorni senza usare** l'area, e comunque al massimo 90 giorni.

Se il socio sbaglia o chiede troppi link, vede «Troppi tentativi: riprova tra qualche minuto.»: si può chiedere un link al minuto e cinque all'ora.

## Cosa trova il socio

Il menu in basso (o in alto sul computer) ha queste voci:

- **Tessera**: il nome e un codice da mostrare all'ingresso; chi lo legge vede se la tessera è valida. Se la persona non è socia, il gestionale dice «Non è una tessera da socio: qui trovi eventi, quote e ricevute.»; se è uscita dall'associazione, «Risulti uscito dall'associazione.».
- **Eventi**: gli eventi dei prossimi due mesi, con **Prenota**. Se l'evento è pieno compare **Lista d'attesa**: quando si libera un posto entra il primo in lista e, se ha un'email, viene avvisato (per un minore, il genitore o tutore). La prenotazione vale anche senza email: il socio la trova in **Eventi**. Il socio annulla da solo fino al giorno prima, se la prenotazione non è già pagata e non fa parte di un pacchetto o di un listino; altrimenti scrive alla segreteria.
- **Prenota** (solo se l'associazione ha il pacchetto **Risorse**, con la prenotazione dall'area soci attiva, e almeno una risorsa a ore o a giornata): il socio sceglie il giorno e tocca una fascia libera. Le risorse stagionali non compaiono: le assegna la segreteria. Vedi [Creare le risorse e gestire le prenotazioni di spazi](guida:gestionale-risorse-e-prenotazioni).
- **Quote**: corsi e attività con **«Dovuto finora … · versato …»** e **«in regola»** oppure **«da versare …»**, il pulsante **«Paga online»** (se l'associazione ha attivato i pagamenti online: vedi [Pagamenti online dei soci](guida:gestionale-pagamenti-online-dei-soci)) e le ricevute da scaricare in PDF. Le ricevute annullate non compaiono.
- **Profilo**: i suoi dati (nome, email e codice fiscale non si cambiano da qui: «Per correggere nome, email o codice fiscale scrivi alla segreteria.»), telefono e indirizzo che può correggere da solo, le tessere degli anni e il pulsante **«Esci da tutti i dispositivi»**.

## I minori

Per un minore entra il genitore o il tutore, con la propria email, anche se non è socio. In alto trova **Io** e il nome di ogni figlio: Tessera, Eventi, Quote e Profilo mostrano la scheda scelta. In **Quote** può pagare online e scaricare le ricevute dei figli. A ogni nuovo ingresso si riparte dalla propria scheda.

Ogni email apre una sola scheda: se due persone hanno la stessa email, entra solo una (prima il socio). Ai maggiorenni serve un'email diversa per ciascuno. Il genitore si collega dalla scheda del minore, in «Genitori o tutori».

## Se un socio ha perso il telefono

Nella sua scheda apri **Altro**, sezione «Area soci», e premi **Chiudi i suoi accessi** (serve il permesso di modificare i soci): il socio esce da tutti i dispositivi. I link e i codici già mandati valgono fino alla scadenza; le schede dei figli non cambiano. Il socio può farlo da solo, dal suo profilo, con **Esci da tutti i dispositivi**; **Esci** chiude solo il dispositivo che sta usando.

## Se l'indirizzo non si apre

Se il socio vede una pagina «non trovata», controlla che l'associazione sia attiva e che l'indirizzo sia quello giusto. Se il problema continua, scrivi all'supporto di Associazione Semplice.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-come-entro-nell-area-soci',
        'title' => 'Come entro nell\'area soci',
        'body' => <<<'MD'
> L'indirizzo dell'area soci te lo dà la segreteria della tua associazione.

L'area soci è la tua pagina personale: la tessera, gli eventi, le quote e le ricevute, il tuo profilo. Non serve nessuna password.

## Per entrare

1. Apri l'indirizzo che ti ha dato la segreteria.
2. Scrivi la tua email, quella che hai dato all'associazione, e premi **«Mandami il link»**.
3. Ti arriva un'email con un link e un codice di 6 cifre: apri il link, oppure scrivi il codice nella pagina e premi **«Entra»**.

Link e codice valgono 15 minuti e si usano una volta sola. Se sono scaduti, premi **«Mandami un altro codice»**. Non hai ricevuto niente? Controlla la posta indesiderata e che l'email sia quella giusta.

Non hai un'email registrata? Chiedi alla segreteria un link d'accesso: te lo manda per esempio su WhatsApp, vale 7 giorni e si usa una volta sola.

Dopo il primo ingresso resti collegato su quel telefono o computer: rientri con un nuovo link solo se non lo usi per 30 giorni.

## Cosa trovi

- **Tessera**: mostra il codice all'ingresso.
- **Eventi**: prenoti un posto. Se l'evento è pieno ti metti in lista d'attesa: quando si libera un posto sei prenotato e ricevi un'email. Puoi annullare fino al giorno prima, se non hai già pagato; altrimenti scrivi alla segreteria.
- **Prenota**: se la tua associazione lo offre, prenoti campi o sale.
- **Quote**: quanto devi e quanto hai versato, **«Paga online»** (se l'associazione lo ha attivato) e le ricevute da scaricare.
- **Profilo**: puoi correggere telefono e indirizzo. Per nome, email o codice fiscale scrivi alla segreteria.

## Se perdi il telefono

Entra da un altro telefono o computer, apri **Profilo** e premi **«Esci da tutti i dispositivi»**: nessuno potrà più usare il tuo accesso già aperto. Se non riesci, chiedi alla segreteria di chiudere i tuoi accessi. Per entrare di nuovo ti basta chiedere un nuovo link.

## Se sei un genitore

Entri con la tua email. In alto trovi **«Io»** e il nome dei tuoi figli: un clic per passare dall'una all'altra scheda.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-libro-soci-livelli-e-runts',
        'title' => 'Libro soci, livelli di quota e dati per il RUNTS',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Nella pagina **Soci**, il pulsante con i tre puntini (**«Altri strumenti»**), accanto alla ricerca, apre, tra le altre, tre pagine che servono a tenere in regola l'anagrafica: **Libro soci**, **Livelli e quote** e **Dati per il RUNTS**.

## Il libro soci

Il **«Libro soci»** elenca tutti i soci in ordine di ingresso, con la data della domanda, l'ammissione, l'eventuale cessazione e l'ultima quota. Ospiti e genitori non soci non ci sono. Ogni riga ha un numero e, se c'è, la matricola del socio; i minorenni mostrano il nome del tutore.

Le colonne sono: **N. e matricola**, **Socio** (con il codice fiscale), **Nascita**, **Residenza**, **Tessera** (con il livello), **Ammissione**, **Cessazione** (con il motivo) e **Ultima quota**. In alto scegli **«Tutti»**, **«In carica»** o **«Cessati»**. Chi ha il permesso **«Esportare l'anagrafica»** (si assegna in **Operatori e ruoli**) vede anche **«Stampa (PDF)»** e **«CSV»**. Libro soci e CSV contengono codice fiscale e residenza dei soci: dai questo permesso solo a chi deve davvero averli.

### Il libro di un anno fiscale

Con **«Anno fiscale»** scegli un anno: vedi chi ha avuto una tessera valida per almeno un giorno di quell'anno.

- Anno ancora aperto: è una bozza. La pagina dice che i numeri sono quelli di oggi e possono cambiare.
- Anno chiuso: è definitivo. Alla chiusura il libro viene emesso e bloccato e non cambia più: la pagina lo dice («Libro soci definitivo: emesso e bloccato alla chiusura dell'anno fiscale …, non cambia più»).
- Se riapri l'ultimo anno chiuso, il libro di quell'anno si rifà alla chiusura successiva: in cima trovi le versioni del libro definitivo, con la data e se la versione è **«in uso»** o **«superata dopo una riapertura»**.

Per la chiusura e la riapertura degli anni fiscali vedi [Chiudere l'anno fiscale e leggere il rendiconto](guida:gestionale-chiudere-l-esercizio-e-leggere-il-rendiconto).

## I livelli di socio

In **«Livelli e quote»** crei i livelli dei soci: per esempio un **«Socio junior»** con una quota ridotta. Ogni livello ha:

- **Nome**;
- **Base**: il tipo di socio da cui parte (**Socio fondatore**, **Socio ordinario** o **Socio e volontario**);
- **Quota**: facoltativa. Senza una quota propria vale quella dell'associazione.
- **attivo**: se togli la spunta, il livello non si può più dare a nessuno. Chi lo ha già lo conserva (nella scheda compare come «Nome (non attivo)») e, se il livello ha una quota, la paga ancora al rinnovo;
- **vota**: dice se chi ha quel livello ha diritto di voto in assemblea (è una scelta a parte, non dipende da «attivo»). I minorenni non votano mai. Un livello nuovo vota.

Per ogni livello vedi quanti soci lo hanno. Per creare un livello compili **Nome**, **Base** e, se vuoi, **Quota**, poi **«Crea»**; per cambiarne uno premi **«Salva»** sulla sua riga. Può farlo chi può cambiare le impostazioni dell'associazione. Il livello di un socio si sceglie nella sua scheda e appare nel libro soci accanto alla tessera.

## I dati per il RUNTS

La pagina **«Dati per il RUNTS»** raccoglie i numeri da comunicare al Registro unico del Terzo settore, calcolati da soli dall'anagrafica al 31 dicembre dell'anno che scegli (con **«Anno»**). Il gestionale indica la scadenza per la comunicazione; se è passata, te lo ricorda: «La scadenza del … è passata: se non hai ancora comunicato questi dati, fallo appena puoi.»

Vedi quattro numeri:

- **Soci persone fisiche con diritto di voto**: i maggiorenni alla data, con un tipo di socio che vota;
- **Enti soci con diritto di voto**, con quanti sono della stessa sezione del Registro;
- **Volontari iscritti**: i soci con tipo **«Socio e volontario»**;
- **Lavoratori**: gli utenti con il ruolo **Dipendente**; il gestionale ti invita a controllare che sia il numero giusto.

Sotto trovi l'elenco degli **«Enti soci»**, con **«Stessa sezione»**, **«Altra sezione»** o **«Sezione non indicata»**.

L'avviso sul numero minimo di soci: se oggi i soci con diritto di voto sono sotto il minimo previsto per un'APS o un'ODV, la pagina te lo segnala con il numero di persone e di enti di oggi, e ti ricorda che in quel caso va comunicato al Registro. Se qualcuno ha la tessera scaduta da poco, vedi il suo nome: se rinnova, torna socio. L'avviso guarda i soci di oggi; i numeri nei riquadri sono quelli al 31 dicembre, da copiare nel portale del RUNTS.

Se non sai quale numero comunicare, chiedi al tuo commercialista: il gestionale calcola dai dati che ha, non decide per te. Per i soci che sono enti vedi anche [Aggiungere i soci e importarli da Excel o CSV](guida:gestionale-aggiungere-e-importare-i-soci).
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-certificati-medici-dei-soci',
        'title' => 'Tenere i certificati medici dei soci',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Per le attività sportive puoi tenere traccia del certificato medico di ogni socio: quando scade, chi lo ha, chi no. Il gestionale ti mostra le scadenze e, se vuoi, avvisa il socio per email.

Il certificato è un dato sulla salute, quindi riservato. Lo vedono titolare, amministratori, segreteria e dipendenti; il commercialista no. Ogni volta che qualcuno scarica un file di certificato, resta scritto nel registro delle azioni. I file sono conservati cifrati.

## Registrare un certificato

1. In **Soci** apri la scheda del socio e vai alla scheda **«Certificato»**.
2. Compila il modulo:
   - **Tipo**: non agonistico, agonistico oppure altro;
   - **Rilascio** e **Scadenza**: la scadenza viene proposta a un anno dal rilascio, correggila con quella scritta sul certificato. Il certificato non può scadere prima di essere stato rilasciato;
   - **Medico**: facoltativo;
   - **File (PDF o foto)**: facoltativo, **PDF, JPG o PNG**, fino a 10 MB.
3. Premi **«Registra il certificato»**.

Nella scheda vedi l'elenco dei certificati del socio, con il tipo, il medico e le date. Il certificato valido oggi è in grassetto: è quello, tra i già rilasciati, che scade più tardi. Per aprire il file premi **«Scarica»**.

Se ti sei sbagliato, premi **Togli**: il gestionale chiede «Cancellare questo certificato medico? Il file allegato viene eliminato.» e, se confermi, il certificato e il suo file spariscono. Per rinnovare un certificato non serve toglierlo: registra il nuovo, e il vecchio resta nell'elenco.

Per registrare, togliere e scaricare servono i permessi di modificare i soci e di vedere i dati sulla salute.

## Chi è in regola: l'elenco dei certificati

In **Soci**, dal pulsante con i tre puntini (**«Altri strumenti»**), scegli **«Certificati medici»**. Vedi i soci con la tessera, la scadenza e lo stato:

- **Valido**: in regola;
- **In scadenza**: scade entro il numero di giorni di preavviso (di partenza **30**);
- **Scaduto**;
- **Mancante**: nessun certificato già rilasciato.

In alto i pulsanti **«Tutti»**, **«In scadenza»**, **«Scaduti»**, **«Mancanti»** e **«Validi»** filtrano l'elenco. Cliccando sul nome apri la scheda. Questa pagina mostra solo nomi e date: i dati del certificato si aprono dalla scheda.

L'elenco comprende i soci in corso (fondatori, ordinari e volontari): i soci usciti non compaiono.

## Il preavviso

Quanti giorni prima della scadenza un certificato risulta «in scadenza» si decide nelle **«Impostazioni del tesseramento»** (campo **«Preavviso certificati (giorni)»**, da 1 a 365), in fondo alla pagina **Nuova scheda socio**. Solo il titolare e gli amministratori possono modificarlo.

## I promemoria per email

Se li hai attivati, il gestionale manda un'email quando il certificato sta per scadere e un'altra quando è scaduto, una sola volta per ogni scadenza. Riceve l'email il socio (se ha un indirizzo); per un minorenne va al genitore o tutore. I soci usciti non ricevono nulla.

I promemoria valgono anche per le tessere in scadenza e si accendono o spengono in **Domande di adesione → «Domande online e promemoria»** (vedi [Ricevere ed esaminare le domande di adesione online](guida:gestionale-domande-di-adesione-online)).

Chi non ha mai avuto un certificato registrato non riceve nessun promemoria: compare solo come **«Mancante»** nell'elenco.

## Portare i certificati fuori dal gestionale

Nella copia di sicurezza puoi includere anche i certificati medici, se hai il permesso di vederli: vedi [Copie di sicurezza e documenti da consegnare](guida:gestionale-esportare-i-dati). Nella demo il caricamento dei file è spento.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Soci', 'slug' => 'gestionale-rinnovare-una-tessera',
        'title' => 'Rinnovare una tessera e incassare la quota',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Dalla scheda del socio, nel riquadro **Tesseramento** della scheda **Tessera**, premi **«Incassa e rinnova»**: l'incasso va in prima nota con la ricevuta, e la tessera viene rinnovata. La tessera segue l'anno fiscale: se l'anno comincia a gennaio è la «Tessera 2026» e scade il 31/12; se comincia in un altro mese è, per esempio, la «2025/2026». L'anno della tessera lo decide il gestionale in base alla data dell'incasso. Se storni quell'incasso, la tessera non vale più; dopo lo storno puoi ripetere la quota.

## Se il socio ha già la tessera

Se il socio ha già la tessera di quell'anno, il gestionale non registra la quota una seconda volta e ti dice, per esempio, «Il socio ha già la tessera 2026.». Non viene registrato nulla: né incasso, né ricevuta, né tessera.

## Se il socio ha già pagato online

Se il socio paga online una quota che risulta già incassata, il gestionale non la registra una seconda volta e segna il pagamento «da controllare». Il socio vede «Pagamento ricevuto: la segreteria lo sta controllando.». Se nello stesso pagamento ci sono altre voci (per esempio un'attività), quelle si registrano.

I soldi sono arrivati sul conto Stripe o PayPal dell'associazione, ma per quella quota non ci sono né movimento né ricevuta nel gestionale. Cosa fare:

1. Apri **Pagamenti online**, sezione **«Pagamenti dei soci»**, e leggi il motivo, scritto in rosso sotto il nome nella colonna Voci.
2. Se è la quota già pagata, premi **rimborsa** e scrivi quanto restituire: tutto o una parte (minimo 0,50 €), anche in più volte.
3. Scrivi la tua password e il motivo del rimborso: il motivo arriva al socio così com'è, quindi scrivilo chiaro e gentile.
4. Dopo l'invio compare «Rimborso di X,XX € inviato al socio. Il socio è stato avvisato per email.» e sotto lo stato leggi «restituiti X,XX €».
5. Stripe o PayPal di solito non restituiscono la loro commissione: chiedi al tuo commercialista se e come registrarla.
6. Premi **controllato** accanto allo stato: segna solo che hai visto e sistemato, non registra e non rimborsa nulla. Il pulsante lo vede chi ha il permesso di contabilità.

I soldi tornano sul metodo di pagamento del socio e il rimborso non si può annullare. Non vedi **rimborsa**? Serve il permesso «Emettere rimborsi»: chiedilo a chi amministra il gestionale. Se il pagamento era già stato registrato, vedi sotto.

Il socio riceve un'email con oggetto «Nome associazione · Rimborso del tuo pagamento», con importo, data del pagamento, motivo e tempi di accredito (in genere pochi giorni lavorativi, fino a 10); in fondo ci sono i contatti della segreteria, se li hai inseriti nei Dati dell'ente. Se il socio non ha un'email, il gestionale te lo dice: «Il socio non ha un'email: avvisalo tu.»

## Restituire un pagamento già registrato

Se il pagamento era già stato registrato (c'è la ricevuta in Prima nota e la tessera), premi **«rimborsa e storna»**. In un gesto solo:

- restituisce tutto il pagamento;
- storna le ricevute in Prima nota;
- annulla le tessere collegate.

Ti chiede la password e il motivo del rimborso, che il socio leggerà nell'email. Restituisce sempre tutto il pagamento: qui non si può restituire solo una parte. Non si può annullare. A operazione finita compare «Rimborsato e stornato: ricevute e tessere annullate. Il socio è stato avvisato per email.».

Se l'anno fiscale dell'incasso è chiuso, l'operazione non parte: né rimborso né storno. Il gestionale dice: «Non si può stornare la ricevuta N/AAAA: l'anno fiscale AAAA è chiuso, va riaperto. Il rimborso non è stato fatto.» Se il rimborso non riesce, non cambia nulla: ricevute e tessere restano come prima. Caso raro: se il rimborso è stato fatto ma lo storno in prima nota no, il gestionale lo dice e ti chiede di stornarlo a mano dalla prima nota. Serve lo stesso permesso **«Emettere rimborsi»**. Il rimborso parziale c'è solo per i soldi non registrati, con «rimborsa».

Se il pagamento comprendeva una prenotazione di una risorsa o l'iscrizione a un'attività o a un evento, quella voce resta valida e torna da pagare: il gestionale non la annulla. Lo dice nella conferma e a operazione finita: «La prenotazione resta valida e torna da pagare: se non serve più, annullala dalla scheda della risorsa.» oppure «L'iscrizione resta e il contributo torna da pagare: se non serve più, togli l'iscrizione dalla scheda dell'attività.» Anche l'email al socio lo spiega: «La tua prenotazione resta attiva e risulta da pagare: se non ti serve più, avvisa la segreteria.» (o «La tua iscrizione resta attiva e il contributo risulta da pagare: …»). Per le quote associative non c'è questa riga, perché la tessera si annulla.

## Se il gestore dei pagamenti non risponde

Se Stripe o PayPal non rispondono mentre fai un rimborso, il gestionale non sa se è partito. Non storna nulla e ti dice di controllare sul pannello di Stripe o PayPal e, se il rimborso non risulta, di ripetere lo stesso rimborso con lo stesso importo: non viene fatto due volte. Se provi con un importo diverso, il gestionale ti ricorda che c'è un rimborso di cui non si sa l'esito e ti chiede di ripetere quello stesso importo, senza cambiarlo.

## Se l'importo pagato è diverso

Se l'importo incassato è diverso da quello atteso, il motivo è «Incassati X invece di Y: da controllare» e in quel caso nessuna voce viene registrata: leggi il motivo nella stessa pagina e premi «controllato» quando hai sistemato.

MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-registrare-un-incasso-e-una-spesa',
        'title' => 'Registrare un incasso e una spesa',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Tutti i movimenti stanno nella **Prima nota**: l'elenco di entrate e uscite in ordine di data.

## Registrare

1. Dal pulsante **Nuovo** (o dalla **Prima nota**) scegli **«Registra un incasso»** o **«Registra una spesa»**.
2. Scegli il conto, l'importo, la data e, se serve, l'attività collegata. Puoi dividere il movimento in più voci con **«Aggiungi un'altra voce»** e **«Togli questa voce»**.
3. Salva. L'incasso dà la ricevuta; con **«Invia per email»** la mandi alla persona. Se l'associazione ha la partita IVA, ogni voce ha anche la sua aliquota IVA (vedi [Impostare l'IVA](guida:gestionale-iva-e-aspetto-fiscale)).

### Perché non riesco a inviare la ricevuta per email?

Se mancano l'email e il telefono della segreteria, **Invia per email** non parte: inseriscili in **Configurazione guidata**, passo **Dati dell'ente** (se non amministri, chiedilo a chi amministra). Intanto scarica la ricevuta e consegnala a mano; fai lo stesso se chi ha pagato non ha un'email. Gli invii automatici, come il link di accesso all'area soci e l'avviso di rimborso, partono comunque.

### La ricevuta non cambia più

Il PDF di una ricevuta si crea una volta sola, quando viene emessa, e non cambia più, nemmeno se poi cambiano i dati del socio o quelli dell'associazione. Quando la scarichi o la spedisci è sempre lo stesso file: il gestionale controlla che sia quello emesso.

Fanno eccezione le ricevute più vecchie, nate senza il PDF conservato: nella pagina «Verifica delle ricevute» hanno lo stato «Da fissare» e il loro PDF si può rifare solo se da allora non è cambiato nulla.

### Perché una ricevuta non si scarica?

- Se sei tu a scaricarla e il file manca o non coincide con quello emesso, il gestionale non la rifà di nascosto e ti avvisa; un amministratore può controllare da **«Verifica delle ricevute»**, come spiegato qui sotto. Se non si riesce a rifarla, scrivi all'supporto di Associazione Semplice.
- Se il socio la scarica dall'area soci e c'è lo stesso problema, legge: «Al momento questa ricevuta non è disponibile. Scrivi alla segreteria.»; i contatti della segreteria sono in fondo alla pagina.
- Una ricevuta annullata non si scarica più: il file emesso resta conservato, ma non è più disponibile.

### La pagina «Verifica delle ricevute»

La trovi in **Gestione → Verifica delle ricevute** (sul telefono: **Altro**), ed è solo per gli amministratori. Scegli l'**«Anno fiscale»** e vedi l'elenco delle ricevute con il loro stato:

- «A posto»: il file è quello emesso;
- «Da fissare»: ricevuta più vecchia, non ancora fissata; il pulsante **Fissa i prossimi PDF** le fissa a gruppi (se ne restano, premilo ancora);
- «Mancante» e «Alterato»: il file non c'è più o non coincide con quello emesso. Il pulsante **Rifai il PDF**, dopo una conferma, lo rifà dai dati salvati all'emissione;
- «Annullata»: il file emesso resta conservato ma non si scarica più.

Dopo **Rifai il PDF** il gestionale dice se il nuovo file è identico a quello emesso oppure è stato rifatto con gli stessi dati ma con testi o grafica aggiornati. Le ricevute più vecchie si rifanno solo se il risultato è identico: altrimenti non cambia nulla e conviene scrivere al supporto di Associazione Semplice. L'operazione resta scritta nel registro delle azioni.

## Cercare e filtrare

In **Prima nota** filtra per conto, per attività e per periodo, poi **«Mostra i movimenti»**. **«Esporta l'anno fiscale (CSV)»** scarica i movimenti; **«Importa da Excel o CSV»** li carica: vedi [Importare la prima nota da Excel o CSV](guida:gestionale-importare-la-prima-nota).

## Correggere un errore

Un movimento sbagliato non si cancella: si storna con **«Storna il movimento»**. Scrivi perché: il movimento resta in elenco ma non conta più.

Per spostare soldi da un conto all'altro (per esempio contanti versati in banca) usa **Giroconto tra conti**, dal pulsante con i tre puntini della Prima nota: non è né un incasso né una spesa.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-iva-e-aspetto-fiscale',
        'title' => 'Impostare l\'IVA, se l\'associazione ha la partita IVA',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Questa guida serve solo se l'associazione ha la partita IVA. Senza, incassi e spese si registrano con il loro importo e dell'IVA non compare nulla, né in prima nota né sulle ricevute.

## Se non hai la partita IVA

In **Aspetto fiscale** trovi solo un promemoria: «Se l'associazione ha la partita IVA, indicala in Altro › Configurazione guidata › Dati dell'ente: qui compariranno le aliquote.»

## Se hai la partita IVA

Scrivila in **Configurazione guidata**, passo **Dati dell'ente** (vedi [Primo accesso e configurazione guidata](guida:gestionale-primo-accesso-e-configurazione-guidata)). Poi apri **Gestione → Aspetto fiscale** (la vedi se puoi cambiare le impostazioni; sul telefono parti da **Altro**) e scegli:

- **«Aliquote IVA che usi»**: i numeri separati da virgola. Di partenza sono **22, 10, 5, 4**. **«IVA esente»** c'è sempre, anche se non la scrivi.
- **«I prezzi che inserisci sono»**: **«finali, con l'IVA compresa»** (la scelta di partenza) oppure **«senza IVA: l'IVA si aggiunge»**. Con i prezzi senza IVA, sotto l'importo vedi subito **«Totale con IVA»**, cioè quanto pagherà chi paga.
- **«Aliquota della quota associativa»**: **IVA esente** oppure una delle aliquote che hai scelto. Di partenza è **IVA esente**.

Premi **«Salva»**.

## In prima nota

Con la partita IVA, ogni voce di incasso e ogni spesa ha la sua aliquota IVA: il gestionale propone il **22%** e tu scegli quella giusta. Le scelte sono le aliquote che hai indicato più:

- **«IVA esente»**;
- **«Fuori campo IVA»**;
- **«Reverse charge»**, solo sulle spese: la spesa si registra senza IVA esposta. Non si può scegliere insieme a un'aliquota.

Se non sai quale voce usare, chiedi al tuo commercialista: il gestionale registra quella che scegli, non decide per te.

L'IVA compare nel dettaglio del movimento. Sulla ricevuta in PDF vedi, per ogni aliquota, l'imponibile e l'IVA; con l'aliquota «IVA esente» la riga dice «di cui IVA esente». Se provi a usare un'aliquota che non hai scelto in **Aspetto fiscale**, il gestionale ti dice che non è tra quelle scelte.

## Se togli un'aliquota dall'elenco

Se togli un'aliquota dall'elenco, i movimenti già registrati con quella aliquota non cambiano. Se togli l'aliquota usata per la quota associativa, la pagina non si salva e ti dice: «Prima cambia l'aliquota della quota associativa: deve essere «IVA esente» o una di quelle dell'elenco. I movimenti già registrati restano come sono.»

## IVA su attività, corsi ed eventi

Con la partita IVA, nella scheda di un'attività (un corso o un evento) trovi la casella **«Con IVA»**:

- senza la spunta, gli incassi di quell'attività si registrano come **«Fuori campo IVA»**;
- con la spunta, scegli dal menu **«IVA esente»** oppure una delle aliquote che hai indicato in Aspetto fiscale («IVA 22%», «IVA 10%»…).

Sotto il menu la scheda ti ricorda come leggere gli importi **«Importo per i soci»** e **«Importo per gli ospiti»**:

- se in Aspetto fiscale hai scelto i prezzi senza IVA: «Gli importi che scrivi sono senza IVA: chi paga vede l'importo con l'IVA aggiunta.» Per esempio, un corso da **100,00 €** con IVA 22%: chi paga vede **122,00 €**;
- se hai scelto i prezzi finali: «Gli importi che scrivi sono finali, con l'IVA compresa.»

Gli incassi di corsi, eventi e prenotazioni nascono in prima nota con l'aliquota dell'attività. Se non sai se un'attività va con IVA, esente o fuori campo, chiedi al tuo commercialista.

Il gestionale ti avvisa se qualcosa non torna:

- se provi a togliere da Aspetto fiscale un'aliquota usata da qualche attività: «Alcune attività usano l'aliquota X%: prima cambia l'IVA di quelle attività, poi toglila dall'elenco.»;
- se nei Dati dell'ente spunti «L'associazione non ha la partita IVA» e ci sono attività con l'IVA: «Hai N attività con l'IVA: senza partita IVA l'IVA non si applica più a nessuna. I movimenti già registrati non cambiano.»;
- in scheda, un avviso rosso: «L'aliquota di questa attività non è più nell'elenco di Aspetto fiscale: scegline un'altra.» oppure «Senza partita IVA l'IVA non si applica: gli incassi di questa attività si registrano senza IVA.»

## IVA su risorse e prenotazioni di spazi

Come funzionano le risorse è spiegato in [Creare le risorse e gestire le prenotazioni di spazi](guida:gestionale-risorse-e-prenotazioni). Con la partita IVA, anche nella scheda di una risorsa (un salone, un campo, un'aula…) trovi la casella **«Con IVA»**, che funziona come quella delle attività:

- senza la spunta: «Senza la spunta, gli incassi di questa risorsa si registrano come «Fuori campo IVA».»;
- con la spunta, scegli **«IVA esente»** oppure una delle aliquote che hai indicato in Aspetto fiscale («IVA 22%», «IVA 10%»…).

Sotto il menu vedi come leggere gli importi: con i prezzi senza IVA, «Gli importi che scrivi sono senza IVA: chi paga vede l'importo con l'IVA aggiunta.»; con i prezzi finali, «Gli importi che scrivi sono finali, con l'IVA compresa.»

Con i prezzi senza IVA, chi prenota vede e paga il prezzo con l'IVA aggiunta, in elenco, nella griglia, nell'anteprima e nell'area dei soci. Per esempio, una sala da **50,00 € l'ora** con IVA 22%: chi prenota vede **61,00 €**. Una prenotazione già fatta si incassa con l'aliquota e l'importo di quando è stata creata, anche se poi cambi l'IVA della risorsa. Se non sai quale voce scegliere, chiedi al tuo commercialista.

Il gestionale ti avvisa se qualcosa non torna:

- se provi a togliere da Aspetto fiscale un'aliquota usata da qualche risorsa: «Alcune risorse usano l'aliquota X%: prima cambia l'IVA di quelle risorse, poi toglila dall'elenco.»; se sono usate anche da qualche attività, il messaggio dice «Alcune attività o risorse usano l'aliquota X%: prima cambia l'IVA di quelle attività o risorse, poi toglila dall'elenco.»;
- se l'aliquota è usata da prenotazioni non ancora finite: «Alcune prenotazioni non ancora finite usano l'aliquota X%: togli l'aliquota dall'elenco quando saranno finite o annullate.»;
- in scheda, un avviso rosso: «L'aliquota di questa risorsa non è più nell'elenco di Aspetto fiscale: scegline un'altra.» oppure «Senza partita IVA l'IVA non si applica: gli incassi di questa risorsa si registrano senza IVA.»
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-importare-la-prima-nota',
        'title' => 'Importare la prima nota da Excel o CSV',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Se finora hai tenuto la contabilità in un foglio Excel, non devi riscriverla: la carichi da un file e il gestionale registra i movimenti in **Prima nota**. **Prima vedi sempre l'anteprima: nulla si salva finché non confermi.**

Chi può farlo: chi ha il permesso «Registrare in prima nota» (titolare, amministratori, segreteria e dipendenti; si assegna in **Operatori e ruoli**).

## Come si fa

1. In **Prima nota** apri il pulsante con i tre puntini (**«Altri strumenti»**) e scegli **«Importa da Excel o CSV»**.
2. Scegli il file: `.xlsx` o `.csv`, al massimo 5 MB e **10.000 movimenti** (se sono di più, dividilo in più file).
3. Se nel file ci sono righe senza il conto, scegli il **«Conto per le righe senza conto»**. Se non lo scegli, quelle righe risultano errori.
4. Premi **«Mostra l'anteprima»**.
5. Controlla l'anteprima e premi **«Importa N movimenti»**. A fine importazione trovi **«Import completato»** con quanti movimenti sono stati registrati e il collegamento **«Vai alla prima nota»**.

## Come deve essere fatto il file

Nella prima riga ci sono i nomi delle colonne. Servono:

- **Data**;
- **Importo**, oppure **Entrata** e **Uscita** in due colonne separate.

Sono facoltative: **Tipo, Conto, Modalità, Voce, Attività, N. tessera, Persona, Descrizione, Competenza, Riferimento**. Il gestionale riconosce anche i nomi simili (per esempio «Causale» per la voce o «Socio» per la persona).

- Le date vanno bene come `15/01/2025` o `2025-01-15`; gli importi come `1.234,56` o `1234.56`.
- Con la sola colonna **Importo**, un importo negativo è una uscita, uno positivo un'entrata (oppure usa la colonna **Tipo**).
- Le righe vuote o quelle di totale (senza data e senza importo) vengono ignorate.
- **Il file che esporti da Prima nota con «Esporta l'anno fiscale (CSV)» si ricarica così com'è.**

## Cosa succede con i dati del file

- **Conti**: se un conto non esiste, si crea (con saldo iniziale zero). L'anteprima ti dice quali conti nuovi nascono.
- **Voci, persone, attività**: se il nome non si riconosce, il movimento si importa lo stesso con una voce generica, oppure senza persona o senza attività; nella colonna **Note** dell'anteprima vedi l'avviso. Con **N. tessera** o **Persona** il movimento si collega alla scheda del socio.
- **Modalità** non riconosciuta: si usa «Altro».
- **Doppioni**: un movimento uguale a uno già in prima nota (stessa data, conto, importo, descrizione e riferimento) viene saltato. Puoi quindi ricaricare lo stesso file senza duplicare i movimenti.
- **Giroconti**: due righe con la stessa data e lo stesso importo, una in uscita da un conto e una in entrata su un altro, diventano un giroconto. Se manca la riga corrispondente, quella riga non si importa: registrala da **Giroconto tra conti**.
- **Ricevute**: non se ne emettono. I movimenti importati sono storici, come se li avessi registrati a mano senza ricevuta.

## L'anteprima

In alto vedi i conteggi: movimenti nuovi, giroconti, doppioni, saltati, con errori, il periodo coperto e il totale di entrate e uscite. Sotto, riga per riga, l'esito (nuovo, giroconto, doppione, saltato, errore) e le note. Le righe con errori non si importano: correggile nel file e ricaricalo; i doppioni verranno saltati e si aggiungerà solo il resto.

Se una riga viene rifiutata durante l'importazione, le altre si registrano comunque e il motivo compare sotto **«Righe non importate»**.

## Prima di importare anni passati

Se il file contiene movimenti di un anno fiscale già chiuso, quell'anno non accetta nuovi movimenti. Le righe di quell'anno non si importano e compaiono sotto **«Righe non importate»** con il messaggio «L'anno fiscale … è chiuso: per modificarlo va riaperto.». Le righe degli altri anni si importano lo stesso. Per importare anche quelle dell'anno chiuso, [riapri l'anno fiscale](guida:gestionale-chiudere-l-esercizio-e-leggere-il-rendiconto) e ricarica lo stesso file: i movimenti già entrati vengono saltati come doppioni e si aggiunge solo il resto.

Controlla anche i saldi iniziali dei conti. L'importazione registra solo movimenti: il saldo iniziale lo imposta solo per i conti nuovi, a zero, e non cambia quello dei conti già esistenti. Il saldo attuale invece cambia, perché i movimenti importati contano: se importi anni passati verifica che torni con la tua banca e la tua cassa.

## Correggere un'importazione sbagliata

I movimenti importati si correggono come gli altri: non si cancellano, si stornano uno per uno dalla Prima nota (vedi [Registrare un incasso e una spesa](guida:gestionale-registrare-un-incasso-e-una-spesa)). L'importazione viene scritta nel registro delle azioni.

**Nella demo il caricamento dei file è spento**: l'importazione si prova sul gestionale vero.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-conti-e-giroconti',
        'title' => 'Creare i conti (cassa, banca, PayPal) e fare un giroconto',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

In **Conti** dichiari dove stanno i soldi dell'associazione: la cassa, il conto in banca, il conto PayPal. Ogni incasso e ogni spesa passa da uno di questi.

1. Premi **«Nuovo conto»**, dagli un nome e indica il saldo iniziale.
2. Per un POS puoi indicare la commissione: a ogni incasso il gestionale registra da solo la spesa in prima nota.
3. Premi **«Crea il conto»**.

Per spostare soldi tra due conti usa il **Giroconto tra conti** dalla **Prima nota**.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Cassa e conti', 'slug' => 'gestionale-chiudere-l-esercizio-e-leggere-il-rendiconto',
        'title' => 'Chiudere l\'anno fiscale e leggere il rendiconto',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

## Il rendiconto per cassa

Il **Rendiconto** mostra entrate e uscite dell'anno per voce, nello schema del **Mod. D** degli enti del Terzo settore: è il documento da portare in assemblea e al commercialista. In fondo vedi l'avanzo (o disavanzo) dell'anno fiscale. Con **«Stampa o salva in PDF»** lo ottieni in un file.

Prima della chiusura il rendiconto è una bozza: i numeri sono quelli di oggi e possono cambiare. Alla chiusura dell'anno fiscale diventa definitivo: emesso e bloccato, non cambia più.

## Chiudere l'anno fiscale

Nella stessa pagina vedi inizio e fine dell'anno fiscale.

1. Quando il rendiconto è approvato, premi **Emetti il rendiconto definitivo** (con l'anno). Da quel momento non si registrano né si stornano movimenti con date di quell'anno.
2. Per correggere qualcosa premi **Riapri l'anno fiscale**, scrivi il motivo e l'anno per confermare. Il motivo resta registrato.

Se le ricevute di quell'anno sono nell'archivio, prima rimettile con **Rimetti i file** in **Copie di sicurezza**: altrimenti la riapertura viene rifiutata. Se riapri e richiudi l'anno, nasce una nuova versione del rendiconto; le precedenti restano elencate.

## Rendiconti pubblicati alla chiusura

La sezione «Rendiconti pubblicati alla chiusura» elenca i rendiconti definitivi: un anno chiuso si legge sempre da lì, anche se poi voci e conti cambiano. Per ogni versione vedi la data e se è «in uso» o «superata dopo una riapertura».

> Chiudi l'anno fiscale solo dopo aver controllato la prima nota e il rendiconto.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Attività', 'slug' => 'gestionale-risorse-e-prenotazioni',
        'title' => 'Creare le risorse e gestire le prenotazioni di spazi',
        'body' => <<<'MD'
> Vale per il gestionale online. Le risorse sono un pacchetto in più (**«Risorse»**) rispetto al piano: si aggiungono da **Abbonamento e pacchetti**.

Le risorse sono ciò che l'associazione mette a disposizione e si prenota: un campo, una sala, un posto barca, una postazione. Le trovi in **Risorse**.

## I tre modi di prenotare

Quando crei una risorsa scegli **«Si prenota»**:

- **A ore**: la giornata è divisa in fasce (**«Minuti per fascia»**) tra **«Apre alle»** e **«Chiude alle»**. Puoi dare tariffe diverse per giorni e orari, ammettere ospiti con un supplemento e proporre servizi aggiuntivi.
- **A giornata**: si prenota il giorno intero.
- **A stagione**: una persona dell'anagrafica ha la risorsa per un periodo, con una lista d'attesa. Le stagionali le assegna solo la segreteria.

Nell'elenco vedi, per ogni risorsa, come si prenota, la tariffa e lo stato di oggi. Ogni tipo ha un massimo di risorse attive (lo vedi nelle tre caselle in alto: «… su …»). Se un tipo non è nel tuo pacchetto, il gestionale lo dice: «Il pacchetto Risorse dell'associazione non comprende risorse …: puoi aggiungerle dal portale.»

## Creare una risorsa

1. In **Risorse** premi **«Nuova risorsa»**.
2. Scrivi il nome (per esempio «Campo 1», «Sala feste», «Posto barca 12») e scegli **«Si prenota»**.
3. Imposta le tariffe: **«Tariffa soci»** (0 = gratuita) e **«Tariffa non soci»** (se la lasci vuota vale come quella dei soci). **Chi ha la tessera valida paga la tariffa soci.**
4. Per le risorse a ore, scegli gli orari e i minuti per fascia, quante fasce di seguito si possono prenotare e l'anticipo massimo in giorni (0 = nessun limite; vale per le prenotazioni dei soci).
5. Scegli la **«Voce di entrata»** in cui finiranno gli incassi (di partenza «Altre entrate») e, se hai la partita IVA, la casella **«Con IVA»** (vedi [Impostare l'IVA](guida:gestionale-iva-e-aspetto-fiscale)).
6. Salva.

**Tariffe a fasce**: una per riga, con giorni (tutti, feriali, weekend), orario e prezzo per fascia; per esempio «feriali 18:00-22:00 15,00». Fuori dalle fasce vale la tariffa soci. **Servizi aggiuntivi**: uno per riga, nome e prezzo («Aperitivo 5,00»): si scelgono quando si prenota.

## Prenotare dalla segreteria

Nella scheda della risorsa scegli **«Chi prenota»**: una persona dell'anagrafica oppure, per un esterno, scrivi il **«Nome di chi prenota»** (per esempio «Torneo» o «azienda»); non serve la scheda. L'esterno paga la tariffa non soci, se l'hai impostata. Per le prenotazioni a ore puoi ripetere **«Ogni settimana per»** fino a 52 settimane; per ogni prenotazione puoi aggiungere ospiti, servizi e note.

Il **«Calendario»** (in alto nella pagina Risorse) mostra le fasce di oggi, i giorni liberi dei prossimi 14 e le stagioni.

## Incassare una prenotazione

Ogni prenotazione mostra **«dovuto … · pagato …»**. Premi **«Incassa»**, controlla importo, data, conto e modalità e premi **«Registra l'incasso»**: va in prima nota, con ricevuta se chi paga è in anagrafica. Serve il permesso di modificare le entrate in prima nota, oltre a quello sulle risorse.

## I soci prenotano dall'area soci

Nell'area soci, in **Prenota**, il socio con accesso sceglie il giorno e tocca una fascia libera, oppure prenota il giorno intero. Il prezzo è quello dei soci con la tessera valida. Vede «Le tue prenotazioni», con «da pagare» e il pulsante **Annulla**. Se l'associazione ha attivato i pagamenti online (vedi [Pagamenti online dei soci](guida:gestionale-pagamenti-online-dei-soci)), il socio paga da lì: l'incasso entra in prima nota con la ricevuta e la prenotazione risulta pagata.

- Dal web prenotano solo i soci con accesso, e solo risorse a ore e a giornata: mai le stagionali. I non soci non prenotano dal web: li prenota la segreteria.
- Per ogni tipo, l'opzione **«web»** del pacchetto accende la prenotazione dall'area soci.
- Il socio può annullare da sé soltanto una prenotazione futura e non pagata; se è pagata o già iniziata vede «scrivi alla segreteria».

## Annullare una prenotazione e restituire i soldi

Sono due gesti distinti: annullando una prenotazione già pagata i soldi non tornano da soli al socio e l'incasso resta in prima nota.

Dopo «rimborsa e storna» la prenotazione c'è ancora e risulta da pagare: se il socio non userà la risorsa, annullala. Il gestionale lo dice nella conferma e a operazione finita: «La prenotazione resta valida e torna da pagare: se non serve più, annullala dalla scheda della risorsa.» Anche l'email al socio lo spiega: «La tua prenotazione resta attiva e risulta da pagare: se non ti serve più, avvisa la segreteria.»

- Se la prenotazione è stata pagata online dall'area soci, apri **Pagamenti online** e usa **«rimborsa e storna»**: i soldi tornano sul metodo di pagamento del socio, l'incasso in prima nota viene stornato e la prenotazione, che resta valida, torna **«da pagare»**; se non vuoi più la prenotazione, annullala tu. Chiede il motivo, che il socio legge. Serve il permesso **«Emettere rimborsi»**. Se l'anno fiscale è chiuso, lo storno non si può fare e non si rimborsa nulla. Come funziona è spiegato in [Rinnovare una tessera e incassare la quota](guida:gestionale-rinnovare-una-tessera), sezione «Restituire un pagamento già registrato».
- Se è stata pagata in un altro modo (contanti, bonifico…): storni l'incasso in prima nota e restituisci i soldi fuori dal programma.

## Bloccare una risorsa

Con **«Blocca la risorsa»** indichi il periodo (**«Dal»**, **«Al»**) e il motivo (manutenzione, torneo, chiusura). Non si blocca un periodo con prenotazioni: annullale prima.

## Stagionali e lista d'attesa

Per una risorsa a stagione, la segreteria assegna una persona per un periodo. Chi è in **«Lista d'attesa»** si aggiunge con **«Aggiungi in lista»**; quando la risorsa viene assegnata a chi è in lista, esce dalla lista da sola.

## Permessi

Si assegnano in **Operatori e ruoli**. Le risorse usano i permessi dell'anagrafica:

- **«Vedere l'anagrafica»**: vedi **Risorse** e il calendario;
- **«Modificare l'anagrafica, tesseramento, attività»**: crei e modifichi le risorse, prenoti, blocchi;
- per incassare una prenotazione servono anche **«Registrare in prima nota»** e **«Registrare gli incassi»**;
- per i rimborsi: **«Emettere rimborsi»**.

## Archiviare una risorsa

Una risorsa che non usi più si archivia; le archiviate si vedono con **«Vedi le archiviate»**.

## Cosa non si può fare

- annullando una prenotazione pagata i soldi non tornano da soli al socio: il rimborso è un gesto a parte;
- la prenotazione online dei non soci;
- la prenotazione delle stagionali dal web;
- pacchetti o crediti prepagati per le risorse;
- le ripetizioni settimanali dall'area soci (le fa solo la segreteria);
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Attività', 'slug' => 'gestionale-creare-un-corso-o-un-evento',
        'title' => 'Creare un corso o un evento',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

In **Attività** trovi i corsi e gli eventi dell'anno delle attività: ognuno raccoglie i suoi incassi e le sue spese.

1. Premi **«Nuova attività»** (o **Nuovo → Nuova attività**).
2. Compila nome, importo per i soci e importo per gli ospiti. Per un corso scegli i giorni delle lezioni: il mese è dovuto dalla prima lezione del mese.
3. Facoltativi: il referente (un socio volontario) e un listino (uno per riga: nome e prezzo; per un carnet «x» e gli ingressi). Senza listino vale l'importo per i soci o per gli ospiti.
4. Con il pacchetto **Risorse** puoi indicare dove si svolge; più attività sulla stessa risorsa ne condividono il calendario.

Se l'associazione ha la partita IVA, nella scheda trovi anche la casella **«Con IVA»**: vedi [Impostare l'IVA](guida:gestionale-iva-e-aspetto-fiscale).

Nella scheda vedi gli iscritti, il registro presenze e i carnet con ingressi ancora da usare. Con **«Chiudi l'iscrizione»** non si accettano altre iscrizioni. Il **Calendario** mostra tutte le date: vedi [Consultare il calendario e registrare le presenze](guida:gestionale-calendario-e-presenze).
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Attività', 'slug' => 'gestionale-calendario-e-presenze',
        'title' => 'Consultare il calendario e registrare le presenze',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Due strumenti ti dicono cosa c'è in programma e chi c'era: il **Calendario** delle attività e il **Registro presenze** di ogni attività.

## Il calendario

Lo apri dal menu, in **Attività e risorse → Calendario**. Mostra le lezioni dei corsi, gli eventi e le date con prenotazione, tranne quelle delle attività archiviate.

- In alto scegli la vista: **Giorno**, **Settimana** (parte dal giorno scelto e va avanti di sette giorni) o **Mese**. Di partenza vedi la settimana.
- Le frecce **←** e **→** spostano di un giorno, di sette giorni o di un mese, secondo la vista. Con il campo data salti a un giorno preciso.
- Ogni riga riporta l'orario (per le date con prenotazione), il nome dell'attività (cliccandolo la apri), il tipo, il luogo e, per le date con prenotazione, quanti sono prenotati su quanti posti.
- Il collegamento **«presenze»** ti porta al registro di quel giorno.

Questo calendario mostra le attività. Le prenotazioni degli spazi hanno un calendario a parte, in **Risorse**: vedi [Creare le risorse e gestire le prenotazioni di spazi](guida:gestionale-risorse-e-prenotazioni).

## Il registro presenze

Si apre dalla scheda dell'attività (**«Registro presenze»**), dal calendario (**«presenze»**) o dalla pagina di una data.

1. Scegli il mese con le frecce **←** e **→**.
2. Tocca il giorno della lezione: i giorni già registrati hanno una spunta ✓. Se in quel mese l'attività non ha lezioni, la pagina te lo dice.
3. Vedi l'elenco di chi è atteso. Togli la spunta a chi è assente: di partenza sono tutti presenti. Con **«tutti presenti»** e **«nessuno»** cambi tutto in un colpo.
4. Premi **«Registra le presenze»**. Compare il riepilogo, per esempio «Presenze registrate: 8 su 10.»

Chi è atteso dipende dal tipo di attività:

- **Corsi**: gli iscritti nel mese di quella lezione (dal mese di inizio a quello di fine dell'iscrizione);
- **Eventi**: gli iscritti;
- **Eventi con prenotazioni**: chi ha la prenotazione confermata per quella data. Chi è in lista d'attesa non compare.

Cose da sapere:

- Le presenze si registrano a lezione fatta, non in anticipo. Per le date future l'elenco c'è, ma i pulsanti sono spenti: «Si registrano a lezione fatta.»
- Si possono registrare solo le date in cui l'attività ha una lezione.
- Se registri di nuovo lo stesso giorno, le presenze sostituiscono quelle di prima: puoi correggere un errore riaprendo il giorno.
- In fondo alla pagina c'è il **«Riepilogo del mese»**: per ogni persona un pallino pieno (presente) o vuoto (assente) per ogni lezione e il totale, per esempio «7/9».
- Ogni registrazione resta scritta nel registro delle azioni.

Per registrare le presenze servono due caselle insieme in **Operatori e ruoli**: **«Modificare l'anagrafica, tesseramento, attività»** e **«Gestire i corsi»**. Valgono anche per gli eventi: «Gestire gli eventi» da solo non basta. Per vedere il registro e il riepilogo basta **«Vedere l'anagrafica»**: chi ha solo quella casella non può modificare nulla.

Per creare le attività e iscrivere le persone vedi [Creare un corso o un evento](guida:gestionale-creare-un-corso-o-un-evento).
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Pagamenti', 'slug' => 'gestionale-pagamenti-online-dei-soci',
        'title' => 'Pagamenti online dei soci con Stripe o PayPal',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_media}, {piano_grande}

I soci pagano dall'area soci con il conto Stripe o PayPal dell'associazione: i soldi arrivano direttamente all'associazione. Ogni pagamento va da solo in prima nota con la ricevuta.

1. Apri **Pagamenti online**.
2. Scegli il conto della prima nota per gli incassi online: di solito un conto «Stripe» o «PayPal» di tipo banca: quando i soldi arrivano sul conto corrente, registri un giroconto.
3. Per **Stripe (carte)** inserisci la **Chiave segreta** e il **Segreto del webhook**, poi nel pannello di Stripe aggiungi il webhook con l'indirizzo indicato nella pagina.
4. Per **PayPal** inserisci le chiavi e, quando sei pronto, passa alla modalità reale.
5. Premi **Prova le chiavi**: la pagina ti dice se funzionano.

In fondo, in «Pagamenti dei soci», trovi ogni pagamento con il suo stato.

> Le chiavi sono segrete: non mandarle per email e non condividerle.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Gestione', 'slug' => 'gestionale-il-tuo-profilo-e-la-verifica-in-due-passaggi',
        'title' => 'Il tuo profilo e la verifica in due passaggi',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Questa guida è per chi lavora nel gestionale: nome, email, password, verifica in due passaggi e dispositivi collegati. I soci entrano invece dall'[area soci](guida:gestionale-come-entro-nell-area-soci).

## Dove si trova

Apri il menu **«Il tuo account»**: sul computer premi il tuo nome nel menu a lato, sul telefono premi **Altro** e scegli **«Il tuo profilo»**. Nello stesso menu c'è **«Sicurezza»**, che porta alla verifica in due passaggi.

## Nome e password

- **Nome**: scrivi nome e cognome e premi **«Salva il nome»**.
- **Password**: inserisci la password attuale, la nuova e ripetila, poi **«Cambia password»**.
- Se non ricordi la password, nella pagina di accesso usa **«Password dimenticata?»**.

## Cambiare l'email di accesso

L'email cambia solo quando apri il link che ti arriva al nuovo indirizzo. Finché non lo apri, per entrare vale ancora quella di prima.

1. Nel profilo, in **«Email di accesso»**, scrivi la nuova email.
2. Scrivi la tua password, per confermare, e premi **«Cambia email»**.
3. Il gestionale ti avvisa: «È stato mandato un link al nuovo indirizzo: l'email cambia solo quando lo apri. Il link vale 60 minuti.»
4. Apri la posta del nuovo indirizzo e premi **«Conferma l'indirizzo»** nell'email «Conferma il nuovo indirizzo email». Fallo mentre sei già entrato nel gestionale con il tuo account: il link funziona solo per la stessa persona che l'ha chiesto.
5. Torni nel profilo con il messaggio «Email aggiornata.»

Cose da sapere:

- Il link vale 60 minuti. Se è scaduto o non è valido, compare «Il link non è valido o è scaduto: chiedi un nuovo cambio email dal tuo profilo.»: ripeti la richiesta.
- Al vecchio indirizzo arriva un'email di avviso, «Richiesta di cambio email», con l'indirizzo nuovo nascosto in parte. Se non l'hai chiesto tu, cambia subito la password dal profilo: finché nessuno apre il link, la tua email non cambia.
- Se la nuova email non arriva, controlla la posta indesiderata e di aver scritto bene l'indirizzo. Se l'indirizzo è già usato da un'altra persona, il link non arriva e l'email non cambia: per sicurezza il gestionale non lo dice.
- Per cambiare l'email di un socio (non del tuo accesso) si usa la sua scheda, non il profilo.

## La verifica in due passaggi

A ogni accesso, oltre alla password, serve un codice di 6 cifre generato da un'app sul telefono (per esempio Google Authenticator, Microsoft Authenticator o 1Password). Protegge i dati dei soci anche se qualcuno scopre la tua password. Per chi lavora nel gestionale è obbligatoria: una volta attivata non la puoi disattivare. Se perdi il telefono e i codici, usa il ripristino via email (vedi sotto).

**Attivarla:**

1. Da **«Sicurezza»** (o dal profilo, **«Attivala»**) premi **«Attiva la verifica»**. Il gestionale può chiederti di riscrivere la password.
2. **Inquadra il QR** con l'app. Se non riesci, inserisci a mano la chiave scritta sotto.
3. Scrivi il codice di 6 cifre che vedi nell'app e premi **«Conferma»**.
4. Compaiono i codici di recupero: salvali subito in un posto sicuro (per esempio in un gestore di password). Non vengono mostrati di nuovo.

**Ai prossimi accessi** scrivi la password e poi il codice dell'app. Se non hai il telefono, premi **«Non hai il telefono? Usa un codice di recupero»** e scrivine uno: ogni codice vale una sola volta.

**Gestirla** (dal profilo, **«Gestisci»**):

- **Genera nuovi codici di recupero**: i vecchi non valgono più.

Conserva i codici di recupero in un posto sicuro: ti evitano di dover fare quello che segue.

### Ho perso il telefono e i codici di recupero

Puoi togliere la verifica da solo, con un link che ti arriva per email:

1. Entra con email e password. Arrivi alla schermata del codice a 6 cifre.
2. Premi **«Ho perso il telefono e i codici di recupero»**. Parte subito, senza altre conferme.
3. Compare il messaggio «Se serve, è partita un'email all'indirizzo del tuo account: apri il link entro 15 minuti.» È sempre uguale, anche se non è partita nessuna email.
4. Apri l'email «Togli la verifica in due passaggi» e premi **Togli la verifica**.
5. Nella pagina che si apre premi di nuovo **Togli la verifica**: solo allora viene tolta (aprire il link non basta). Con **Torna all'accesso** rinunci.
6. Torni alla schermata di accesso con il messaggio «La verifica in due passaggi è stata tolta. Accedi con la password e riattivala subito con il telefono nuovo.»
7. Entra con la password e riattiva subito la verifica dalla pagina della verifica in due passaggi, con un nuovo QR da inquadrare con il telefono nuovo. Il gestionale ti dà nuovi codici di recupero: quelli vecchi non valgono più. Salvali subito.

Cose da sapere:

- Il link vale 15 minuti e una volta sola. Se è già stato usato, è scaduto o non è valido, torni alla schermata di accesso con «Il link non è valido o è scaduto: dalla schermata del codice chiedine uno nuovo.»
- Se non ricevi l'email, controlla la posta indesiderata. L'email parte solo se la password è giusta e va all'indirizzo del tuo account (se l'hai cambiato, quello nuovo).
- I link si possono chiedere al massimo 2 al giorno e 6 al mese. Oltre il limite compare «Hai già chiesto troppi link: scrivi al supporto di Associazione Semplice per sbloccare l'accesso.»: lo sblocco lo fa il supporto, su tua richiesta.
- Appena la verifica è tolta ti arriva una seconda email, «La verifica in due passaggi è stata tolta». Se non l'hai tolta tu, cambia subito la password dal profilo. Se l'ha tolta il supporto su tua richiesta, l'email lo dice.
- Se non sei stato tu a chiedere il link, ignora l'email: non succede nulla e il tuo accesso resta protetto.

## I dispositivi collegati

In fondo al profilo vedi i dispositivi da cui sei entrato, con sistema, browser, indirizzo e ultimo uso, e quale è **«questo dispositivo»**. Se ce n'è più di uno, per scollegare gli altri scrivi la tua password e premi **«Scollega gli altri dispositivi»**: questo resta collegato. Usalo se hai perso un telefono o hai lasciato il tuo account aperto su un computer che non è tuo.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Gestione', 'slug' => 'gestionale-operatori-e-ruoli',
        'title' => 'Invitare gli operatori e scegliere i ruoli (anche per il commercialista)',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande} (il numero di operatori dipende dal piano)

In **Operatori e ruoli** decidi chi entra nel gestionale e cosa può fare.

## Invitare un operatore

Nella sezione **Operatori** scrivi nome ed email, scegli il ruolo e premi **«Invita»**: la persona riceve un'email ed entra con il suo account Associazione Semplice, lo stesso per portale, supporto e gestionale. Se la persona ha già un accesso a questa associazione, l'invito non ne cambia il ruolo: il gestionale dice «Questa persona ha già un accesso a questa associazione: il ruolo si cambia dall'elenco degli operatori.»

## I ruoli

- **Ruoli fissi**: Titolare, Amministratore, Segreteria, Dipendente e Commercialista. L'abbonamento resta al titolare; impostazioni e operatori a titolare e amministratori.
- **Commercialista**: legge la contabilità e l'elenco dei soci, senza modificare nulla; non vede i certificati medici né i dati riservati delle schede.
- **Ruoli dell'associazione**: con **«Nuovo ruolo»** crei ruoli per segretari, consiglieri, tesorieri… scegliendo i permessi.

Cambi il ruolo di un operatore dal menu accanto al suo nome.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Gestione', 'slug' => 'gestionale-il-registro-delle-azioni',
        'title' => 'Il registro delle azioni: cosa si scrive e come leggerlo',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande}

Il gestionale tiene un registro delle azioni: un diario in cui si scrive cosa è successo, quando e chi l'ha fatto. Serve a ritrovare chi ha toccato un dato e a dimostrare, per esempio a un revisore, che le cose sono andate come dici.

Non c'è una pagina per leggerlo dentro il gestionale: il registro si scarica con la copia di sicurezza, come tabella da aprire con Excel.

## Cosa si registra

Ogni riga è un'azione. Tra le più comuni:

- **Accessi**: entrata e uscita di operatori e soci, tentativi falliti, password reimpostata, verifica in due passaggi (attivata, codice sbagliato, codice di recupero usato, nuovi codici generati);
- **Soci**: modifica di una scheda, importazione dell'elenco, tutori collegati o scollegati, tessere assegnate o revocate a mano, domande di adesione;
- **Prima nota**: incassi, spese, giroconti, movimenti stornati, importazioni;
- **Ricevute**: emesse, inviate, rifatte o fissate;
- **Pagamenti online**: creati, falliti, rimborsati, controllati;
- **Attività**: date aggiunte, cambiate o annullate, prenotazioni, liste d'attesa, presenze registrate;
- **Anni fiscali**: creati, chiusi, riaperti, cambio del mese di inizio;
- **Dati riservati**: ogni lettura di un file sulla salute (per esempio il download di un certificato medico), le esportazioni, i download della copia di sicurezza, l'archivio degli anni chiusi;
- **Configurazione guidata**: completata o rimandata.

Di solito non si copia il contenuto dei dati: si scrive che l'azione c'è stata, con i pochi dettagli utili (per esempio quali campi di una scheda sono cambiati).

## Chi può averlo

Il registro è nell'esportazione per chi ha il permesso **«Vedere il registro delle azioni»**: titolare e amministratori. Gli altri operatori non lo ricevono. Per scaricarlo apri **Copie di sicurezza** (vedi [Copie di sicurezza e documenti da consegnare](guida:gestionale-esportare-i-dati)): nell'archivio ZIP, nella parte **«Dati per il gestionale»**, trovi il file `registro-delle-azioni.csv`.

## Come si legge

Il file si apre con Excel o con un foglio di calcolo. Ogni riga ha queste colonne:

| Colonna | Cosa contiene |
|---|---|
| **N.** | il numero progressivo dell'azione |
| **Quando** | data e ora |
| **Azione** | il codice dell'azione (vedi sotto) |
| **Oggetto** e **Id** | su cosa è stata fatta (il tipo di dato e il suo identificativo) |
| **Chi** e **Id di chi** | chi l'ha fatta: operatore, socio oppure sistema (per le azioni automatiche), e il suo identificativo |
| **Dettagli** | pochi dati utili sull'azione |
| **Sigillo** | il sigillo della riga (vedi sotto) |

I codici sono scritti in inglese, con un punto in mezzo. I più frequenti:

| Codice | Cosa significa |
|---|---|
| `auth.login` / `auth.logout` | un operatore è entrato / uscito |
| `auth.failed` | accesso non riuscito |
| `two_factor.recovery_code_used` | è stato usato un codice di recupero |
| `person.updated` | è stata modificata una scheda |
| `people.imported` | è stato importato un elenco di soci |
| `ledger.income` / `ledger.expense` | incasso / spesa registrati |
| `ledger.transfer` | giroconto |
| `ledger.voided` | movimento stornato |
| `ledger.imported` | prima nota importata da un file |
| `receipt.sent` | ricevuta inviata |
| `payment.refunded` | pagamento online rimborsato |
| `fiscal_year.closed` / `fiscal_year.reopened` | anno fiscale chiuso / riaperto |
| `attendance.saved` | presenze registrate |
| `health.read` | è stato aperto un file sulla salute |
| `export.done` | è stata fatta un'esportazione |
| `backup.downloaded` | è stata scaricata una copia di sicurezza |

Se trovi un codice che non è in elenco, scrivi al supporto.

Per trovare chi ha toccato un dato, nel foglio filtra la colonna **Azione** (per esempio `ledger.voided`) o ordina per **Quando**.

## Per quanto tempo si conserva

Il registro conserva le azioni dell'anno in corso e di tutto l'anno precedente. Si conta l'anno solare (da gennaio a dicembre), non l'anno fiscale. Il 1° gennaio si cancellano le azioni più vecchie: per esempio il 1° gennaio 2028 si cancellano quelle del 2026. Quindi le azioni restano da uno a due anni. Se ti servono per più tempo, scarica prima la copia di sicurezza e conservala tu.

## Il sigillo

Ogni riga ha un sigillo (una sequenza di lettere e numeri) che dipende anche dalla riga precedente: sono legate a catena. Se qualcuno cancellasse o cambiasse una riga, la catena non tornerebbe più e il controllo se ne accorge. Ogni notte il gestionale controlla da solo che la catena sia intatta. Per te significa che il registro non si può correggere di nascosto, nemmeno da chi lavora nel gestionale.

Le azioni vengono scritte in automatico: nessuno può aggiungere, togliere o modificare righe a mano. Anche quando le righe più vecchie vengono cancellate il 1° gennaio, il controllo della catena continua a funzionare sulle righe rimaste.
MD,
    ],
    [
        'topic' => 'gestionale', 'section' => 'Gestione', 'slug' => 'gestionale-esportare-i-dati',
        'title' => 'Copie di sicurezza e documenti da consegnare',
        'body' => <<<'MD'
> Vale per il gestionale online. Disponibile in: {piano_piccola}, {piano_media}, {piano_grande} (non durante la prova)

La pagina **Copie di sicurezza** (menu **Gestione**, o dalla Home) ti dà tutti i dati dell'associazione in un archivio ZIP. La vedono solo il titolare e gli amministratori.

## Cosa contiene

La copia ha due parti:

- **«Dati per il gestionale»**: servono per riportare i dati nel plugin o nel gestionale Associazione Semplice. La prima nota e l'elenco dei soci si possono ricaricare con l'importazione da Excel o CSV.
- **«Documenti leggibili»**: PDF e tabelle che si aprono senza nessun programma speciale, da consegnare a chi subentra o al commercialista. I PDF si aprono con qualsiasi lettore, le tabelle con Excel, LibreOffice o simili.

Aprendo lo ZIP trovi questa struttura:

- LEGGIMI.txt: una breve spiegazione
- **Dati per il gestionale**
- **Documenti leggibili**
  - Anno fiscale 2026 (una cartella per ogni anno fiscale)
    - Rendiconto.pdf
    - Prima nota.csv
    - **Libro soci.pdf** e **Libro soci.csv**
    - **Ricevute**: un PDF per ogni ricevuta, per esempio «Ricevuta 12-2026 - Rossi Maria.pdf»
    - Ricevute non incluse.txt, solo se qualche ricevuta non si è potuta mettere nella copia
  - **Certificati medici**, solo se lo hai chiesto (vedi sotto)

Il file scaricato si chiama «copia-di-sicurezza-» seguito dal nome dell'associazione e dalla data (con l'anno, se la copia è di un solo anno fiscale).

## Come si scarica

1. Apri **Copie di sicurezza**.
2. In **«Documenti leggibili di»** scegli **«tutti gli anni fiscali»** oppure un **«anno fiscale»** (per esempio 2026).
3. Premi **Scarica copia di sicurezza**, scrivi la password e premi di nuovo **Scarica copia di sicurezza**.

La copia richiede qualche minuto e il download parte da solo: non chiudere la pagina.

Se l'associazione ha molti documenti, la copia unica non parte e il gestionale ti dice: «Questa associazione ha molti documenti: una copia unica rischia di non finire. Scegli un anno fiscale e scarica una copia per anno.»

## Le ricevute che mancano

Se il PDF di una ricevuta manca o risulta diverso da quando è stato emesso, quella ricevuta non entra nella copia e compare nel file **«Ricevute non incluse.txt»**. Si controllano in **Gestione → Verifica delle ricevute** (vedi [Registrare un incasso e una spesa](guida:gestionale-registrare-un-incasso-e-una-spesa)).

## I certificati medici

Di partenza non ci sono. Se hai il permesso sui dati sulla salute, vedi la casella **«Includi i certificati medici (dati sulla salute) in chiaro»**: spuntandola, nella cartella **Certificati medici** trovi un file per ogni certificato (nome «Cognome Nome - tipo - scade» con la data). Il gestionale avvisa: «Sono dati sulla salute, in chiaro, senza protezione: conservali con cura e consegnali solo a chi ne ha titolo.»

## Attenzione ai dati personali

La copia contiene dati personali: conservala con cura. Resta traccia di chi la scarica.

## L'archivio degli anni chiusi (facoltativo)

Nella pagina **Copie di sicurezza**, il titolare e l'amministratore trovano la sezione **«Archivio degli anni chiusi»**. È una funzione facoltativa, per tenere in ordine gli anni vecchi: un anno fiscale chiuso si può archiviare, e le sue ricevute in PDF si raccolgono in un solo file cifrato, conservato nel gestionale, al posto dei file singoli. I dati restano dove sono: l'archivio contiene solo file. Per te non cambia nulla: le ricevute si scaricano come sempre. I certificati medici non vanno in archivio.

Per ogni anno fiscale vedi uno di questi stati:

- **«Da archiviare»**: c'è il pulsante **«Archivia l'anno»**.
- **«Archiviato il» con la data**: c'è il pulsante **«Rimetti i file»**, per tornare indietro quando vuoi.
- «Non ancora: si archiviano solo gli anni fiscali prima dell'ultimo anno chiuso.»

I file singoli vengono tolti solo dopo che l'archivio è stato controllato: se qualcosa non torna, non cambia nulla.

Archiviare non sostituisce la copia di sicurezza. Resta consigliato **«Scarica copia di sicurezza»**, e la copia contiene comunque anche gli anni archiviati.

In **Gestione → Verifica delle ricevute** le ricevute di un anno archiviato hanno lo stato **«In archivio»**. Per rifare il PDF di una ricevuta di un anno archiviato, devi prima rimettere i file di quell'anno.

Se l'archivio di un anno non si apre, nella pagina compare l'errore e, sotto, il pulsante **«Ricostruisci dai dati dell'emissione»** (con una conferma): il gestionale rifà le ricevute di quell'anno dai dati con cui sono state emesse. Al termine leggi «Anno fiscale 2025: N ricevute ricostruite dai dati dell'emissione. L'operazione è scritta nel registro delle azioni.» (con il tuo anno e il numero di ricevute).

Ogni settimana il gestionale controlla da solo che gli archivi si aprano.

Se l'archiviazione non parte, il gestionale dice il motivo: di solito una ricevuta senza PDF valido, da controllare in **Verifica delle ricevute**, oppure un'altra operazione sulle ricevute ancora in corso (riprova dopo qualche minuto). Un anno con più di 2.500 ricevute non si archivia: scrivi al supporto.

## Se cambi programma o chiudi l'abbonamento

Scarica la copia di sicurezza prima. Quando la prova finisce senza un piano, per 7 giorni puoi ancora consultare i dati ma non modificarli né scaricarli. Quando un piano a pagamento non viene rinnovato, l'accesso viene sospeso: i dati restano custoditi, ma finché l'accesso è sospeso non puoi scaricare nulla. Per questo conviene fare la copia prima della scadenza. Nei **«Documenti leggibili»** trovi quello che serve da consegnare a chi subentra: rendiconto, prima nota, libro soci e ricevute di ogni anno fiscale.
MD,
    ],
];
