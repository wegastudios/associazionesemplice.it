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
