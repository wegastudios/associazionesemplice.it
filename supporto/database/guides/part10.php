<?php

// Guide: assemblee e deleghe, bilancio preventivo, rimborsi ai volontari (Pro, blocco D).
return [
    [
        'topic' => 'plugin-pro', 'section' => 'Registri', 'slug' => 'assemblee-e-deleghe',
        'title' => 'Convocare l\'assemblea e gestire presenze e deleghe',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale} (non nel plugin gratuito)

Con le assemblee convochi i soci, registri chi c'è, gestisci le deleghe, controlli il **numero legale** e ottieni il verbale già compilato. Lo trovi in **Registri → Assemblee**. Lo usano segreteria, presidente, vicepresidente e amministratori. Senza Pro, o con la licenza non in regola, la voce sparisce; i dati restano.

## Creare e convocare

1. Premi **Nuova assemblea**.
2. Scegli il tipo (**ordinaria** o **straordinaria**) e scrivi il titolo.
3. Indica data e ora della **prima convocazione**, la **seconda convocazione** (facoltativa) e il **luogo**.
4. Scrivi l'**ordine del giorno**: senza, non si può convocare.
5. Controlla il **numero legale** (vedi sotto) e salva.
6. Premi **Convoca e avvisa i soci**. Ogni socio con diritto di intervenire riceve un'email con data, luogo e ordine del giorno.

L'avviso parte **una sola volta per persona**. Con **Rimanda l'avviso a chi non l'ha ricevuto** lo mandi agli altri. Se la riunione è fra meno di otto giorni compare un avviso: controlla che il **preavviso previsto dallo statuto** sia rispettato.

## Chi ha diritto di intervenire

I soci con la **tessera in regola**, non sospesi e non usciti. Tutori e ospiti non sono soci.

## Le deleghe

- Il socio sceglie un altro socio dalla sua **area riservata**, sezione **«Assemblee»** (anche con lo shortcode `[associazionesemplice_assemblee]`).
- Oppure la segreteria registra una **delega scritta** ricevuta.

Le regole: ogni socio può dare **una sola delega** per assemblea e riceverne **al massimo 3** (5 se i soci sono almeno 500). Chi riceve una delega non può delegare, e chi ha delegato non può riceverne. Non si delega a chi non è socio in regola.

## Presenze e numero legale

1. La segreteria spunta i soci **presenti**.
2. Il numero legale conta i presenti **più le deleghe di chi è presente**. Chi ha delegato ma viene di persona conta una volta sola.

Il numero legale si scrive **assemblea per assemblea**, in percentuale degli aventi diritto. Di partenza è 50% in prima e 0% in seconda per l'ordinaria, 75% e 50% per la straordinaria. Il 50% significa «metà più uno». **Lo statuto può prevedere altro: controlla e correggi.**

## Chiudere e creare il verbale

1. Premi **«Chiudi: svolta in prima (o seconda) convocazione»**. Servono almeno una presenza e il numero legale raggiunto. Dopo la chiusura l'assemblea **non si modifica più**.
2. Premi **Crea il verbale**: nasce il verbale in **Registri → Verbali**, già compilato con presenti, rappresentati per delega, numero legale e ordine del giorno. Resta da scrivere lo svolgimento. C'è un verbale per assemblea.
3. **Foglio presenze (CSV)** scarica l'elenco di tutti i soci con presente, delegato o assente.

## Privacy

L'esportazione dei dati di una persona comprende presenze e deleghe.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Conti e fondi', 'slug' => 'bilancio-preventivo',
        'title' => 'Preparare il bilancio preventivo e confrontarlo con la prima nota',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

Il preventivo ti fa scrivere quanto prevedi di incassare e spendere nell'anno e vedere, voce per voce, come va rispetto ai movimenti reali. Si trova in **Contabilità → Preventivo**. Senza Pro, o con la licenza non in regola, la voce sparisce; i dati restano.

## Creare il preventivo

1. Premi **Nuovo preventivo** e scegli l'**anno** (ne hai uno per anno) e il titolo.
2. Nella tabella scrivi l'**importo previsto** per ogni categoria di entrata e di uscita. Le righe vuote non si salvano.
3. In alternativa premi **Parti dall'anno prima**: copia le voci dal preventivo o dal consuntivo dell'anno precedente, con una **variazione in percentuale**. Attenzione: sostituisce le voci già scritte.

## Leggere il confronto

Per ogni voce vedi il **preventivo**, il **consuntivo** (dalla prima nota: giroconti e movimenti annullati non contano), la differenza a favore, la percentuale e lo **stato**:

- **nei limiti**;
- **quasi esaurita** (dal 90%);
- **oltre il preventivo**;
- **non prevista**.

In fondo trovi i totali e il risultato.

## Approvare

1. Premi **Segna come approvato**.
2. Indica la **data** e, se vuoi, l'**assemblea** che lo ha approvato, scegliendola tra quelle registrate (vedi [Convocare l'assemblea](guida:assemblee-e-deleghe)).

Un preventivo approvato **non si modifica**; **Riapri** lo riporta in preparazione.

## Esportare e controllare

- **Esporta il confronto (CSV)** scarica la tabella.
- In **Bacheca** un riquadro avvisa quante voci di spesa hanno superato il preventivo approvato dell'anno in corso.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Conti e fondi', 'slug' => 'rimborsi-ai-volontari',
        'title' => 'Gestire i rimborsi spese ai volontari',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

I rimborsi ti fanno gestire le richieste dei volontari dall'approvazione al pagamento, con registrazione in prima nota. Li trovi in **Cassa → Rimborsi**. Senza Pro, o con la licenza non in regola, la voce sparisce; i dati restano.

## Chi chiede il rimborso

Il volontario (o socio) lo chiede dall'**area riservata**, sezione **«Rimborsi spese»** (anche con lo shortcode `[associazionesemplice_rimborsi]`). La segreteria può registrare una richiesta per lui da **Cassa → Rimborsi**. Tutori e ospiti non sono soci e non chiedono rimborsi.

## I tre tipi

- **Spesa documentata**: serve lo scontrino o la fattura, in PDF o foto. **Non ha un tetto**: i limiti qui sotto riguardano solo l'autocertificazione.
- **Autocertificazione**: fino a **10 € al giorno** e **150 € al mese** per volontario (sono i limiti di legge, fissi nel plugin). Vale solo se l'**assemblea ha deliberato** i tipi di spesa.
- **Chilometrico**: compare solo se in **Cassa → Rimborsi → Impostazioni** hai indicato la **tariffa al chilometro**. L'importo si calcola da solo.

## Approvare, rifiutare, pagare

1. In **Cassa → Rimborsi** filtra per stato: da approvare, approvato da pagare, rifiutato, pagato, annullato.
2. Apri la scheda e premi **Approva**, **Rifiuta** (scrivendo il motivo) o **Annulla la richiesta**.
3. Per pagare premi **Segna come pagato e registra in prima nota**, scegli il **conto** e la **data**. Il sito registra un'uscita nella categoria **«Rimborso spese socio/volontario»** e la collega alla richiesta (riferimento RIMB-n). Un rimborso già pagato non si paga due volte.

Il volontario riceve un'email quando il rimborso è approvato, rifiutato o pagato, e può **ritirare la richiesta** finché non è decisa.

## Chi approva e chi paga

Chi approva o paga **non può farlo per un rimborso a proprio nome**. Se nel tuo ente una persona sola gestisce la cassa, l'amministratore può attivare in **Impostazioni** l'opzione **«Chi ha chiesto il rimborso può anche approvarlo e pagarlo»** (spenta di partenza). Le impostazioni le cambia solo l'amministratore.

## Altro

- **Esporta (CSV)** scarica l'elenco.
- In **Bacheca** un riquadro ricorda le richieste da approvare e i rimborsi da pagare.
- L'esportazione dei dati di una persona comprende i suoi rimborsi. Se viene anonimizzata, giustificativi e descrizioni si cancellano; importi e collegamento alla prima nota restano per la contabilità.
MD,
    ],
];
