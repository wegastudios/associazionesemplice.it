<?php

// Guide: anno fiscale, spese e fondi, bilancio provvisorio e verifiche di fine anno ({piano_fiscale}, Pro 1.1.38).
return [
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'inizio-dell-anno-fiscale',
        'title' => 'Impostare il mese di inizio dell\'anno fiscale',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Per molte associazioni l'anno fiscale non coincide con l'anno solare. Puoi indicare il **mese di inizio dell'anno fiscale** in tutte le versioni, separatamente da quello dell'**anno delle attività** (il calendario di corsi ed eventi).

## Dove si imposta

1. Vai in **Impostazioni → Ente → Dati dell'ente** (con {piano_contabile} o {piano_fiscale}: **Impostazioni → Ente e fiscalità → Dati e fiscalità**).
2. Alla domanda **«Da che mese inizia il tuo anno fiscale (bilancio e rendiconto)?»**, che sta sotto **«Da che mese iniziano corsi ed eventi (anno delle attività)?»**, scegli il mese. Di partenza è **gennaio**, cioè l'anno solare.
3. Salva.

Lo scegli anche al primo passo della [configurazione guidata](guida:completare-la-configurazione-guidata), insieme al mese dell'anno delle attività (spunta **«Ho controllato i due mesi»**).

Se per esempio un circolo velico ha le attività da maggio e il bilancio da gennaio a dicembre, imposta **maggio** per l'anno delle attività e **gennaio** per l'anno fiscale.

Un anno si chiama con **l'anno in cui inizia**: con inizio a luglio, il 2025 va dal 1° luglio 2025 al 30 giugno 2026.

## Cosa segue l'anno fiscale

Seguono il mese scelto l'apertura e la chiusura degli anni fiscali, la **prima nota**, i **report**, il **rendiconto**, il **preventivo**, i **fondi per anno**, le **esportazioni e importazioni** e anche la **tessera** dei soci, le **quote** e gli **incassi** (iscrizioni comprese), il **libro soci** e i **rinnovi**.

## Cosa non cambia

L'**anno delle attività** è solo il calendario di corsi ed eventi: ogni attività ne ha uno solo e le sue date devono stare dentro quell'anno. Il **5 per mille** e i **Dati per il RUNTS** restano sull'anno solare (31 dicembre).

## Cosa succede alle tessere se cambi il mese

Quando cambi il mese dell'anno fiscale compare il riquadro **«Cosa succede alle tessere»**: «Le tessere dei soci seguono l'anno fiscale. Cambiando il mese, le tessere degli anni fiscali ancora aperti che hanno un pagamento registrato si riallineano al nuovo anno fiscale: non si accorciano mai e quelle degli anni chiusi non si toccano.» Il riquadro dice anche quante tessere cambiano e quante risultano «sostituite» (restano registrate, i pagamenti non cambiano).

- Si riallineano **solo le tessere con un pagamento registrato**: le allunga fino alla fine dell'anno fiscale in cui scadono, **mai le accorcia**. Gli anni chiusi restano intatti; tessere omaggio, onorarie e a quota zero restano come sono.
- Se due tessere finiscono nello **stesso anno**, resta la più recente e l'altra è **«sostituita»**: si vede solo nella scheda del socio («Sostituita dalla tessera 2026: resta registrata e i pagamenti non cambiano.»), non compare nel libro soci.
- Per cambiare il mese devi mettere la spunta **«Ho letto cosa succede alle tessere e voglio cambiare il mese dell'anno fiscale.»** Senza la spunta il resto dei dati si salva e il mese non cambia: «Il mese dell'anno fiscale non è cambiato: leggi «Cosa succede alle tessere» qui sotto, spunta la conferma e salva di nuovo.»
- Se nessuna tessera cambia, leggi «Nessuna tessera da riallineare.». Altrimenti, a cambio fatto, «Tessere riallineate: N. Tessere sostituite: S.»
- **Prima del cambio** viene salvata una **copia di sicurezza** dei dati, che resta sul sito per **7 giorni**.
- Per **30 giorni** gli amministratori vedono in Bacheca l'avviso **«Le tessere seguono l'anno fiscale»** con il riepilogo e il collegamento **«Vedi il registro azioni»**.

## Da sapere

- Il mese si sceglie **prima di chiudere il primo anno fiscale**: dopo la prima chiusura resta **bloccato**, anche se poi l'anno viene riaperto.
- Le **verifiche prima della chiusura** dell'anno fiscale (per esempio i fondi non rimborsati) e il rendiconto per cassa sono funzioni di {piano_contabile} e {piano_fiscale}.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'spese-documentate-e-fondi-dei-rimborsi',
        'title' => 'Spese documentate e fondi: l\'attribuzione automatica',
        'body' => <<<'MD'
> Disponibile in: {piano_fiscale} (aggiorna insieme gratuito e Pro)

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

## Beneficiario e volontari

- Un **beneficiario scelto esplicitamente** vale sempre.
- Dall'**area soci** non c'è la scelta del beneficiario né del fondo: il beneficiario è **chi registra** la spesa.
- Le spese di un volontario vanno solo sui suoi fondi. La parte che li supera resta in credito e si chiude da sola con i prossimi incassi dei suoi corsi.
- L'elenco **«Volontari in credito»** si trova in **Cassa → Conti e fondi** e in **Bacheca**.
- L'attribuzione automatica si accende e si spegne con l'impostazione **«Attribuzione automatica delle spese ai fondi»** in Limiti e soglie: è **accesa** di partenza.

### Chiudere subito il credito di un volontario

1. Vai in **Cassa → Conti e fondi** e trova il fondo del volontario.
2. Apri **«Accantona una somma»** (aggiunge soldi al fondo e li sottrae dalla disponibilità reale).
3. Scrivi l'importo nel campo **«0,00 €»** e premi **«Accantona»**.

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
> Disponibile in: {piano_fiscale} (aggiorna insieme gratuito e Pro)

Con il {piano_fiscale} hai sempre sott'occhio come sta andando l'anno fiscale.

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
> Disponibile in: {piano_fiscale} (aggiorna insieme gratuito e Pro)

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

## Approvare una discrepanza a mano

Le discrepanze si vedono **una per una**, con **tipo**, **voce** e **importo**. Se una la ritieni accettabile puoi **approvarla a mano**, con una **nota facoltativa**, per portarla al nuovo anno.

1. Vai in **Contabilità → Bilancio provvisorio → «Discrepanze da verificare»**.
2. Nella riga della discrepanza, se vuoi, scrivi il campo **«Nota (facoltativa)»**.
3. Premi **«Approva»** e conferma **«Approvare questa discrepanza e portarla al nuovo anno?»**.

Le approvate restano elencate con **chi e quando** le ha approvate (per esempio «approvata il gg/mm/aaaa da Nome (anno N): nota»). Per **revocare** l'approvazione premi **«Revoca l'approvazione»**: la discrepanza torna «da verificare». Se l'importo cambia, l'approvazione **decade da sola**.

- L'approvazione vale **solo per quell'importo**: se l'importo cambia, la discrepanza torna.
- Le discrepanze approvate **non generano avvisi** e **non fermano la chiusura dell'anno**.
- Restano elencate in **Contabilità → Bilancio provvisorio → «Discrepanze da verificare»**.
- Ogni approvazione è tracciata nel registro azioni.

## Chiudere l'anno

1. Vai in **Contabilità → Anni fiscali** e premi **Chiudi l'anno** (vedi [Aprire e chiudere gli anni fiscali](guida:aprire-e-chiudere-gli-anni-solari)).
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
