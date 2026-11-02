<?php

// Guide: anno fiscale, spese e fondi, bilancio provvisorio e verifiche di fine anno (Pro Fiscale, Pro 1.1.38).
return [
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'inizio-dell-anno-fiscale',
        'title' => 'Impostare il mese di inizio dell\'anno fiscale',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale (da Pro 1.1.38, con il plugin gratuito 1.2.47: aggiorna insieme gratuito e Pro; novità fino al Pro 1.1.39)

Per molte associazioni l'esercizio non coincide con l'anno solare. Dal Pro 1.1.38 puoi indicare il **mese di inizio dell'anno fiscale** separatamente da quello dell'anno sociale.

## Dove si imposta

1. Vai in **Impostazioni → Dati e fiscalità** (scheda dell'ente).
2. Nel menu **«L'anno fiscale inizia a»**, accanto a «L'anno sociale inizia a», scegli il mese. Di partenza è **gennaio**, cioè l'anno solare, come prima.
3. Salva.

Lo trovi anche nella [configurazione guidata](guida:completare-la-configurazione-guidata), dentro **«Più dettagli: codice fiscale, anno sociale, sede»**.

Un anno si chiama con **l'anno in cui inizia**: con inizio a luglio, il 2025 va dal 1° luglio 2025 al 30 giugno 2026.

## Cosa segue l'anno fiscale

Seguono il mese scelto l'apertura e la chiusura degli anni, la **prima nota**, i **report**, il **rendiconto**, il **preventivo**, i **fondi per anno** e le **esportazioni e importazioni**.

## Cosa non cambia

Le **tessere** restano per **anno solare** e scadono il 31 dicembre. Restano così anche l'**anno sociale** e il **5 per mille** (anno d'imposta solare).

## Da sapere

- Funziona solo con il **Pro Fiscale**: senza la licenza gli anni tornano solari.
- **Cambiando il mese gli anni si ricreano** per contenere i movimenti: conviene farlo **a inizio esercizio**.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'spese-documentate-e-fondi-dei-rimborsi',
        'title' => 'Spese documentate e fondi: l\'attribuzione automatica',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale (da Pro 1.1.38, con il plugin gratuito 1.2.47: aggiorna insieme gratuito e Pro; novità fino al Pro 1.1.39)

Quando registri una **spesa con scontrino o fattura**, il plugin la attribuisce da solo al fondo dei rimborsi che ha **accantonato ma non ha ancora spese a supporto**. L'attribuzione si vede sempre e si può correggere.

## Registrare una spesa

1. Vai in **Cassa → Nuova spesa** e allega lo scontrino o la fattura.
2. Nella riga **«Fondo da coprire»** lascia **Automatico** (è la scelta di partenza), oppure scegli un fondo preciso o **«Nessun fondo»**. Con «Nessun fondo» la spesa **non viene mai attribuita**, nemmeno quando arrivano i documenti.
3. Salva. Il messaggio dice **a quale fondo e per quanto** la spesa è stata attribuita, oppure che non lo è ancora: succede da sola quando c'è un documento allegato e un fondo con documentazione da coprire.

## Come sceglie il fondo

La regola vale **solo per le spese documentate**, cioè con scontrino o fattura allegati. Per i rimborsi il documento è il file della richiesta.

- Se il beneficiario è un **volontario**, valgono solo i **suoi** fondi.
- Se è un **consigliere** o un altro ruolo (o manca il beneficiario), vale **qualunque fondo** con documentazione scoperta: prima quelli dell'attività della spesa, poi i più vecchi.
- Valgono anche i fondi **già liquidati**.
- La spesa si può **dividere su più fondi** se serve.

## Beneficiario e volontari (da Pro 1.1.39)

- Un **beneficiario scelto esplicitamente** vale sempre.
- Dall'**area soci** non c'è la scelta del beneficiario né del fondo: il beneficiario è **chi registra** la spesa.
- Il **volontario** va **solo sui suoi fondi**. Ciò che supera resta **in credito**. Il credito si chiude da solo con i futuri incassi dei suoi corsi; per sanarlo subito:

  1. vai in **Cassa → Conti e fondi** e trova il fondo del volontario;
  2. apri **«Accantona una somma»** (aggiunge soldi al fondo e li sottrae dalla disponibilità reale);
  3. scrivi l'importo nel campo **«0,00 €»** e premi **«Accantona»**.
- L'elenco **«Volontari in credito»** si trova in **Cassa → Conti e fondi** e in **Bacheca**.
- L'attribuzione automatica si accende e si spegne con l'impostazione **«Attribuzione automatica delle spese ai fondi»** in Limiti e soglie: è **accesa** di partenza.

## Controllare e correggere

In **Contabilità → Bilancio provvisorio** (in breve in **Bacheca**, e per intero anche in **Cassa → Conti e fondi**) trovi:

- **Spese documentate non rimborsate**: ogni riga ha il menu **«— scegli il fondo —»** e il pulsante **Attribuisci**, per attribuire a mano;
- **Fondi accantonati non documentati**, con le colonne Fondo, Stato, Accantonato, Documentato e Da documentare;
- **Attribuzioni recenti**, con il pulsante **Annulla** (chiede conferma).

I primi due elenchi hanno il link **Esporta CSV**. Un'attribuzione annullata resta nell'elenco e la spesa **non viene riattribuita da sola**. Ogni correzione a mano è tracciata nel registro azioni.

L'elenco **«Spese documentate non rimborsate»** considera le spese degli **ultimi 24 mesi**: si cambia con **«Quanti mesi guardare indietro negli elenchi dei fondi»** in Limiti e soglie.

Per i conti e i fondi in generale vedi [Conti, fondi e giroconti](guida:conti-fondi-e-giroconti).

## Il prospetto dei rimborsi per persona

Nella stessa pagina, la sezione **«Rimborsi e spese documentate per persona»** (con la scelta dell'**anno fiscale**) ha le colonne **Persona**, **Rimborsato dai fondi**, **Spese documentate attribuite**, **Differenza** (rimborsato meno documentato) e **Situazione**. I pulsanti sono **Esporta CSV** e **Scarica PDF per il rendiconto**. Se i conti non quadrano, in Bacheca compare un avviso che non blocca nulla (si regola con **«Avviso in Bacheca se rimborsi e spese non quadrano»**).
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'bilancio-provvisorio-e-avvisi-di-bilancio',
        'title' => 'Bilancio provvisorio, avvisi di bilancio e storico mensile',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale (da Pro 1.1.38, con il plugin gratuito 1.2.47: aggiorna insieme gratuito e Pro; novità fino al Pro 1.1.39)

Con il Pro Fiscale hai sempre sott'occhio come sta andando l'anno fiscale.

## Il bilancio provvisorio

Vai in **Contabilità → Bilancio provvisorio**. Il titolo è **«Bilancio dell'anno fiscale …»**. La pagina mostra:

- il bilancio **aggiornato all'ultimo mese concluso**, con il preventivo in proporzione ai mesi conclusi;
- l'andamento del **mese in corso**;
- la **stima di fine anno**;
- la tabella **«Voce per voce, contro il preventivo»**, con Previsto per i mesi conclusi, Consuntivo e Differenza a favore.

Lo stesso riquadro compare in **Bacheca**, **Contabilità**, **Report** e **Preventivo**.

## Gli avvisi di bilancio

Compaiono **solo quando c'è qualcosa che non va**: se tutto è a posto non c'è nessun messaggio verde. In **Bacheca** la riga si può chiudere; il riquadro compare anche in **Cassa**, **Contabilità**, **Report**, **Preventivo** e **Bilancio provvisorio**.

- **«Spese non coperte»**: la stima di fine anno delle uscite supera le entrate di più di una soglia, di partenza **10%**.
- **«Utile eccessivo»**: le entrate superano le uscite di più del **20%** delle uscite.

Non scattano nei **primi 3 mesi** dell'anno fiscale.

## Lo storico mensile

Sempre in **Bilancio provvisorio**, la sezione **«Storico mensile»** ti fa scegliere l'**anno fiscale** e mostra i mesi uno per uno, con **Esporta CSV** (e PDF). A ogni fine mese un lavoro giornaliero **salva una foto** del consuntivo contro il preventivo, che poi **non cambia**. Se ne mancano, il pulsante **«Salva ora le foto dei mesi conclusi mancanti»** le recupera. Non parte nessuna email.

## Le impostazioni

In **Impostazioni → Limiti e soglie**, gruppo **«Bilancio, avvisi e fondi»**:

- **«Avvisi sulla tendenza del bilancio»**: sì o no;
- **«Spese non coperte: soglia»** e **«Utile eccessivo: soglia»**, in percentuale;
- **«Mesi trascorsi prima di fare la stima»**;
- **«Istantanea mensile del bilancio»**;
- **«Attribuzione automatica delle spese ai fondi»** (vedi [Spese documentate e fondi](guida:spese-documentate-e-fondi-dei-rimborsi)).
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'verifiche-di-fine-anno-e-chiusura',
        'title' => 'Verifiche di fine anno fiscale e chiusura dell\'anno',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale (da Pro 1.1.38, con il plugin gratuito 1.2.47: aggiorna insieme gratuito e Pro; novità fino al Pro 1.1.39)

Prima della fine dell'anno fiscale il plugin ti avvisa se ci sono **discrepanze**, per esempio rimborsi senza spese documentate a copertura, e può **fermare la chiusura** finché non le sistemi. L'avviso compare **solo se ci sono discrepanze**, senza messaggi verdi.

## Le due fasi dell'avviso

1. **Preavviso**: parte **3 mesi prima** della fase fissa (di partenza) e si può **chiudere**. La chiusura vale per la settimana corrente: ricompare il lunedì, ed è per utente.
2. **Avviso fisso**: **non si può chiudere**. Di partenza parte dal **mese prima dell'ultimo mese** dell'anno fiscale: con l'anno da gennaio a dicembre il 1° novembre, con l'anno da settembre il 1° luglio.

Lo vedono amministratori, segreteria, tesorieri, presidente e vicepresidente, in **Bacheca** e nelle pagine **Cassa**, **Contabilità**, **Report**, **Preventivo** e **Bilancio provvisorio**.

L'avviso elenca, con il link per correggere:

- fondi non documentati;
- spese documentate non rimborsate;
- rimborsi senza documentazione;
- spese documentate oltre i rimborsi;
- spese con beneficiario non attribuite;
- sbilancio oltre le soglie;
- voci fuori preventivo;
- movimenti senza categoria vera.

## Approvare una discrepanza a mano (da Pro 1.1.39)

Le discrepanze si vedono **una per una**, con **tipo**, **voce** e **importo**. Se una la ritieni accettabile puoi **approvarla a mano**, con una **nota facoltativa**, per portarla al nuovo anno.

1. Vai in **Contabilità → Bilancio provvisorio → «Discrepanze da verificare»**.
2. Nella riga della discrepanza, se vuoi, scrivi il campo **«Nota (facoltativa)»**.
3. Premi **«Approva»** e conferma **«Approvare questa discrepanza e portarla al nuovo anno?»**.

Le approvate restano elencate con **chi e quando** le ha approvate (per esempio «approvata il gg/mm/aaaa da Nome (anno N): nota»). Per **revocare** l'approvazione premi **«Togli l'approvazione»**: la discrepanza torna «da verificare». Se l'importo cambia, l'approvazione **decade da sola**.

- L'approvazione vale **solo per quell'importo**: se l'importo cambia, la discrepanza torna.
- Le discrepanze approvate **non generano avvisi** e **non fermano la chiusura dell'anno**.
- Restano elencate in **Contabilità → Bilancio provvisorio → «Discrepanze da verificare»**.
- Ogni approvazione è tracciata nel registro azioni.

## Chiudere l'anno

1. Vai in **Contabilità → Anni solari** e premi **Chiudi l'anno** (vedi [Aprire e chiudere gli anni solari](guida:aprire-e-chiudere-gli-anni-solari)).
2. Di partenza la chiusura **si ferma** e mostra l'elenco se ci sono rimborsi non documentati.
3. Con l'opzione **«chiedi conferma»** compare l'elenco con una casella da spuntare per procedere.

Dopo la chiusura le regolarizzazioni si fanno da **prima nota**.

## Le impostazioni

In **Impostazioni → Limiti e soglie**, gruppo **«Bilancio, avvisi e fondi»**:

- **«Mesi di preavviso chiudibile prima dell'avviso fisso»**: di partenza 3, **0** = nessun preavviso, massimo 11;
- **«Mesi di avviso fisso delle verifiche di fine anno fiscale»**: di partenza 1, da 1 a 3;
- **«Promemoria email delle verifiche di fine anno fiscale»**: **spento** di partenza; se lo accendi parte una email all'inizio del preavviso e una all'inizio della fase fissa, solo se ci sono discrepanze;
- **«Chiusura dell'anno con rimborsi non documentati»**: **3** = si ferma e mostra l'elenco (di partenza), **2** = chiede conferma con l'elenco, **1** = chiude e resta l'avviso, **0** = nessun controllo;
- **«Tolleranza nella quadratura rimborsi e spese»**: di partenza 100 centesimi, cioè **1,00 €**.
MD,
    ],
];
