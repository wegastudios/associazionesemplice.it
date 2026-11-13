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

Scegli l'anno e premi **Mostra**: i numeri valgono al **31 dicembre** di quell'anno, anche se il tuo esercizio inizia in un altro mese. Conta come socio chi quel giorno aveva la tessera valida (e i fondatori già ammessi), esclusi quelli già usciti. È lo stesso criterio del libro soci.

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

Il **Dipendente** è un tipo di utente a parte: è un utente del sito con gli **stessi permessi della segreteria**, ma **non è un socio**. Non è nel libro soci, non ha la tessera e non conta per le assemblee. Si gestisce da **Rubrica soci → Dipendenti**.

## Creare un dipendente

1. Vai in **Rubrica soci → Dipendenti**.
2. Nel modulo in fondo scrivi **nome**, **cognome** e **email**; facoltativi il **primo e l'ultimo giorno di servizio**.
3. Salva: al dipendente arriva l'**invito per scegliere la password**. Se l'email non parte, controlla la posta del sito e usa **«Rimanda l'invito»**.

## Togliere dei permessi

Per ogni dipendente puoi **togliere la spunta** ai singoli permessi, uno per uno. Solo gli **amministratori, il presidente e il vicepresidente** vedono questa pagina e possono farlo. Ogni cambio resta scritto nel registro azioni, e dalla pagina puoi vedere chi ha tolto o dato permessi, e quando.

## Periodo di servizio

Senza date il dipendente è sempre in servizio. **Fuori dal periodo** non può usare il gestionale e non conta tra i lavoratori dei [Dati per il RUNTS](guida:dati-per-il-runts).

## Se è anche socio

I due accessi restano separati: da dipendente entra con l'utente del sito, da socio con il suo accesso personale all'area soci e una scheda a parte nel libro soci. Per il socio usa un'altra email, oppure l'accesso personale con la stessa email.
MD,
    ],
];
