<?php

// Guide del plugin 1.3.23 (free) / 1.3.10 (Pro): Dati per il RUNTS, soci che sono enti, utente «Dipendente».
return [
    [
        'topic' => 'altro', 'section' => 'Registri', 'slug' => 'dati-per-il-runts',
        'title' => 'Dati per il RUNTS: i numeri al 31 dicembre',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

La pagina **Registri → Dati per il RUNTS** raccoglie i numeri da copiare nel portale del Registro unico del Terzo settore. Il programma **non invia nulla** al RUNTS: ti dà i numeri, tocca a te scriverli nel portale.

## Cosa trovi

Scegli l'anno e premi **Mostra**: i numeri valgono al **31 dicembre** di quell'anno, anche se il tuo anno fiscale inizia in un altro mese. Conta come socio chi quel giorno aveva la tessera valida (e i fondatori già ammessi), esclusi quelli già usciti. È lo stesso criterio del libro soci.

- **Soci persone fisiche con diritto di voto**: vota chi era maggiorenne a quella data e ha diritto di voto (con {piano_contabile} e {piano_fiscale} dipende dal livello di socio). Se a qualcuno manca la data di nascita, il programma lo conta come maggiorenne e te lo segnala: completa le schede per un numero sicuro. I numeri usano le impostazioni di voto **di oggi**: se le hai cambiate dopo il 31 dicembre, ricontrolla.
- **Soci che sono enti**: il numero, con l'elenco di denominazione e codice fiscale e se sono iscritti al RUNTS nella tua stessa sezione (vedi [Soci che sono enti](guida:soci-che-sono-enti)).
- **Volontari iscritti nel registro dei volontari**: il programma non tiene quel registro. Il numero lo scrivi tu in **Impostazioni → Dati dell'ente** (con l'anno di riferimento); a titolo di aiuto la pagina mostra quanti soci di tipo «Socio e volontario» c'erano.
- **Lavoratori**: sono gli utenti **«Dipendente»** in servizio a quella data (vedi [Il dipendente e i suoi permessi](guida:il-dipendente-e-i-suoi-permessi)).

## Scadenze e minimo di soci

Sotto i numeri trovi il **promemoria del 30 giugno**: entro quella data si aggiornano nel portale del RUNTS i dati al 31 dicembre. Se non è cambiato nulla rispetto all'ultima volta, di norma non devi fare niente.

Per le **ODV e le APS** c'è anche il controllo del **numero minimo di soci**: un avviso compare se si scende sotto il minimo. Per attivarlo indica la sezione del RUNTS in **Dati dell'ente**.

I numeri sono un aiuto: **controllali prima di riportarli nel portale**.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'soci-che-sono-enti',
        'title' => 'Soci che sono enti (associazioni, società, enti pubblici)',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Un socio può essere un'altra associazione, una società o un ente pubblico, non solo una persona.

## Inserirlo a mano

1. Apri la scheda di un nuovo socio (**Rubrica soci**).
2. Nel campo **«Il socio è»** scegli **«Un ente (associazione, società, ente pubblico…)»**.
3. Scrivi la **denominazione** e il **codice fiscale** dell'ente.
4. Alla domanda **«È iscritto al RUNTS nella vostra stessa sezione?»** rispondi Sì, No o «Non lo so». Serve al controllo del numero minimo di soci (vedi [Dati per il RUNTS](guida:dati-per-il-runts)).

Un ente non ha nome e cognome: il nome resta vuoto.

## Importarli da CSV

Nel file di [importazione dei soci](guida:importare-i-soci-da-excel-o-csv) aggiungi la colonna **Tipo soggetto** (*persona* oppure *ente*), la **Denominazione** al posto di Nome e Cognome, il **Codice fiscale** dell'ente (11 cifre, oppure 16 caratteri) e, se serve, **Stessa sezione RUNTS** (sì o no). Per un ente basta uno tra Codice fiscale, Email, Cellulare e Numero tessera.

## Dove compaiono

Nel **libro soci** gli enti hanno la colonna **«Tipo»** e nei **Dati per il RUNTS** sono contati a parte. Un ente non è una persona fisica: il diritto di voto delle persone e il numero dei maggiorenni non lo riguardano.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'il-dipendente-e-i-suoi-permessi',
        'title' => 'Il dipendente e i suoi permessi',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Il **Dipendente** è un tipo di utente a parte: è un utente del sito che **parte con gli stessi permessi della segreteria**, ma **non è un socio**. Non è nel libro soci, non ha la tessera e non conta per le assemblee. Si gestisce da **Rubrica soci → Dipendenti**.

## Creare un dipendente

1. Vai in **Rubrica soci → Dipendenti**.
2. Nel modulo in fondo scrivi **nome**, **cognome** e **email**; facoltativi il **primo e l'ultimo giorno di servizio**.
3. Salva: al dipendente arriva l'**invito per scegliere la password**. Se l'email non parte, controlla la posta del sito e usa **«Rimanda l'invito»**.

Un dipendente nuovo ha **tutti i permessi**.

## Togliere dei permessi, area per area

Per ogni dipendente puoi **togliere la spunta** a una o più delle **15 aree** qui sotto. Togliendola, le pagine e le azioni di quell'area si chiudono: nel menu e nelle schede compaiono solo le pagine che il dipendente può aprire, e chi prova a fare ciò che non può legge «Non puoi farlo con il tuo accesso: ti è stato tolto il permesso «…». Se ti serve, chiedi al presidente, al vicepresidente o a un amministratore del sito di ridartelo.»

Possono farlo **solo gli amministratori del sito e il presidente e il vicepresidente con la tessera in regola** (soci attivi, con la carica indicata nella scheda e collegati al loro utente del sito): la segreteria, il dipendente stesso e i soci non vedono nemmeno la pagina. Ogni cambio resta scritto nel registro azioni, e dalla pagina puoi vedere chi ha tolto o dato permessi, e quando.

| Area | Che cosa apre | Chiede anche |
|---|---|---|
| **Anagrafica soci** | Rubrica e scheda persona, libro soci, dati per il RUNTS, affiliazioni, tessere, polizze, registro dei volontari. Creare, modificare ed eliminare soci, approvare le domande di adesione, sospendere o riattivare. | Per esportare l'elenco soci serve anche «Importa ed esporta dati». |
| **Certificati medici** | Elenco completo dei certificati, caricarli e toglierli, esportarli in CSV. | Vedi la regola più sotto. |
| **Corsi** | Elenco, scheda, iscrizioni, lezioni, presenze, registro presenze, calendario. | Con Eventi condivide l'elenco «Corsi ed eventi» e il calendario: si aprono se c'è almeno uno dei due. |
| **Eventi** | Elenco, scheda, prenotazioni, lista d'attesa, check-in, staff, richieste di evento, pagina «Prenota». | Come Corsi. |
| **Risorse prenotabili** | Sale e attrezzi: schede, prenotazioni, blocchi, assegnazioni, occupazione. | L'incasso di una prenotazione resta di «Incassi». |
| **Incassi** | Nuovo incasso, cassa per più persone, vendita crediti, controllo dei pagamenti online, ricevute, allegati. | Per scegliere chi paga serve l'Anagrafica, o la ricerca minima (vedi sotto). |
| **Registrazione delle spese** | Nuova spesa, collegare o togliere una spesa da un fondo, allegati. | Come Incassi. |
| **Emissione dei rimborsi** | Rimborsi ai volontari (richiesta, approvazione, pagamento, file e CSV) e rimborsi dei pagamenti online. | — |
| **Visualizzazione di fondi, casse e conti** | Cassa, prima nota, conti e fondi, giroconti, bilancio provvisorio, contabilità, report, rendiconto, 5 per mille, preventivo, ricevuta sostitutiva, conteggio di cassa, annullamenti. In Bacheca i saldi. | Importare la prima nota chiede anche «Importa ed esporta dati» e «Anagrafica soci». |
| **Comunicazioni** | Avvisi, newsletter, SMS e promemoria ai soci; rinvio delle email e degli SMS non partiti. | Gli avvisi che partono da altre aree (convocare un'assemblea, annunciare una votazione o una convenzione) chiedono anche questa area. |
| **Documenti dell'associazione** | Archivio dei documenti (statuto, moduli): elenco, caricare, modificare, eliminare. | — |
| **Assemblee, verbali e votazioni** | Convocazione, presenze, deleghe, chiusura, verbali, votazioni; verbali e votazioni in PDF e CSV. | Convocare e annunciare una votazione mandano un avviso: serve anche «Comunicazioni». |
| **Inventario e prestiti** | Beni e prestiti: schede, file allegati, registrare prestito e rientro, elenchi in CSV. In Bacheca i prestiti scaduti. | — |
| **Convenzioni** | Schede, categorie, archivio, elenco in CSV. In Bacheca quelle in scadenza. | Annunciare una convenzione ai soci chiede anche «Comunicazioni». |
| **Importa ed esporta dati** | Pagina Esportazioni, panoramica Strumenti, tutte le esportazioni in CSV. | Esportare i soci chiede anche «Anagrafica soci»; prima nota, rendiconto e report chiedono anche «Fondi, casse e conti». Importare da Excel o CSV chiede anche «Anagrafica soci» e «Fondi»; importare da The Events Calendar chiede anche «Corsi» ed «Eventi». |

Qualche regola in più:

- **«Registri»** si apre a chi ha almeno una tra Anagrafica, Assemblee, Inventario, Documenti e Convenzioni. Senza Anagrafica, nel libro soci si legge un messaggio e restano gli altri registri.
- **«Strumenti»** si apre a chi ha «Importa ed esporta dati», Corsi o Eventi, e mostra solo ciò che si può aprire (per esempio il calendario).
- I **pulsanti di esportazione** che trovi in altre pagine funzionano solo con «Importa ed esporta dati». Il registro presenze in PDF o CSV chiede invece Corsi o Eventi.

### Certificati medici senza l'area

Senza «Certificati medici» il dipendente non vede l'elenco completo e non può esportare. Se ha **Corsi** o **Eventi**, vede lo stato e può aprire il certificato **solo degli iscritti alle attività di cui è istruttore e di quelle senza istruttore**; le attività con un altro istruttore non contano. Se al suo accesso non è collegata una persona, vede solo le attività senza istruttore. Ogni apertura di un file resta scritta nel registro azioni.

### Scegliere una persona senza l'Anagrafica

Senza «Anagrafica soci», i campi che scelgono una persona (chi paga, chi iscrivere, cassa per più persone) non mostrano l'elenco dei soci ma una casella **«Cerca per nome o tessera (almeno 3 lettere)»**. I risultati sono al massimo **10** e di ogni persona si vedono solo **nome, cognome (con il numero di tessera) e se la quota è in regola**. Si cerca solo per nome, cognome o numero di tessera: non per email, telefono o codice fiscale. Funziona finché il dipendente ha almeno una tra Incassi, Corsi, Eventi, Spese e Rimborsi.

## Che cosa resta solo agli amministratori

Il dipendente **non lo ha mai**, qualunque spunta abbia: tutte le **impostazioni** (dati dell'ente, soci e quote, aspetto, bonifico, donazioni, tessera e QR, privacy e regolamento, limiti e soglie, testi e lingua, pagamenti online), gli **anni fiscali e le chiusure**, la **copia di sicurezza e il ripristino**, il **registro azioni**, le **integrazioni**, la **procedura guidata**, l'**azzeramento dei dati**, l'**eliminazione definitiva** di attività e iscrizioni, la **cifratura e la cartella riservata**, l'**anonimizzazione dei dati** e la **gestione dei dipendenti**. Restano fuori anche le azioni personali di ciascuno (chiudere un avviso, la guida iniziale, le notifiche del proprio dispositivo) e quelle dell'area soci.

## Chi entra nel pannello

- **Gli amministratori di WordPress hanno tutto** e **non si possono mai togliere né limitare dal plugin**: se un utente è anche amministratore del sito, vede e fa tutto anche con il ruolo Dipendente.
- **Gli altri entrano nel pannello solo** con il ruolo **Segreteria** o **Dipendente** (si assegnano dalla scheda del socio e da **Rubrica soci → Dipendenti**) o come **presidente o vicepresidente in regola**.
- **I ruoli standard di WordPress** (Editore, Autore, Collaboratore, Sottoscrittore) **non danno accesso al plugin**: non c'è più una pagina «Ruoli e accessi» per darglielo.

Se prima qualcuno entrava con un ruolo di WordPress, agli amministratori compare in Bacheca l'avviso **«Alcuni utenti entravano nel pannello con un ruolo di WordPress (per esempio Editore): ora non entrano più. Se servono, dagli il ruolo Segreteria o Dipendente.»**, con l'elenco delle persone e il link **«Apri i dipendenti»**. L'avviso non si chiude da solo: sparisce quando un amministratore apre la pagina **Dipendenti**.

## Periodo di servizio

Senza date il dipendente è sempre in servizio. **Fuori dal periodo** non può usare il pannello di amministrazione del plugin e non conta tra i lavoratori dei [Dati per il RUNTS](guida:dati-per-il-runts).

## Se prima aveva dei permessi tolti

Quando i permessi sono passati all'assegnazione per area, in Bacheca compare a chi può gestire i dipendenti l'avviso **«Rivedi i permessi dei dipendenti: ora si danno per area.»** (solo se esiste almeno un dipendente). Non si chiude da solo: sparisce quando apri la pagina **Dipendenti**. Serve a controllare chi aveva dei permessi tolti prima.

## Se è anche socio

I due accessi restano separati: da dipendente entra con l'utente del sito, da socio con il suo accesso personale all'area soci e una scheda a parte nel libro soci. Per il socio usa un'altra email, oppure l'accesso personale con la stessa email.
MD,
    ],
];
