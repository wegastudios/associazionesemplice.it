<?php

// Guide: anno fiscale, spese e fondi, bilancio provvisorio e verifiche di fine anno (Pro Fiscale, Pro 1.1.38).
return [
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'inizio-dell-anno-fiscale',
        'title' => 'Impostare il mese di inizio dell\'anno fiscale',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale (da Pro 1.1.38, con il plugin gratuito 1.2.47: aggiorna insieme gratuito e Pro)

Per molte associazioni l'esercizio non coincide con l'anno solare. Dal Pro 1.1.38 puoi indicare il **mese di inizio dell'anno fiscale** separatamente da quello dell'anno sociale.

## Dove si imposta

1. Vai in **Impostazioni → Dati e fiscalità**.
2. Nella riga **«L'anno fiscale inizia a»**, accanto a «L'anno sociale inizia a», scegli il mese. Di partenza è **gennaio**, cioè come prima.
3. Salva.

Lo trovi anche nella [configurazione guidata](guida:completare-la-configurazione-guidata), dentro **«Più dettagli: codice fiscale, anno sociale, sede»**.

## Cosa segue l'anno fiscale

Seguono il mese scelto la **prima nota**, gli **anni**, i **report**, il **preventivo** e il **rendiconto**.

## Cosa non cambia

Le **tessere** restano per **anno solare** e scadono sempre il 31 dicembre. Restano così anche l'**anno sociale** e il **5 per mille**.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'spese-documentate-e-fondi-dei-rimborsi',
        'title' => 'Spese documentate e fondi: l\'attribuzione automatica',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale (da Pro 1.1.38, con il plugin gratuito 1.2.47: aggiorna insieme gratuito e Pro)

Quando registri una **spesa con scontrino o fattura**, il plugin la attribuisce da solo al fondo dei rimborsi che ha **accantonato ma non ha ancora spese a supporto**. L'attribuzione si vede sempre e si può correggere.

## Registrare una spesa

1. Vai in **Cassa → Nuova spesa** e allega lo scontrino o la fattura.
2. Nella riga **«Fondo da coprire»** lascia **Automatico**, oppure scegli **Nessun fondo** o un fondo preciso (compare come «da documentare …»).
3. Salva. Il messaggio dopo il salvataggio dice **a quale fondo e per quanto** la spesa è stata attribuita.

## Come sceglie il fondo

- Il fondo giusto è quello che ha accantonato e non ha ancora **documentazione a supporto**.
- Se chi registra è un **volontario**, valgono solo i **suoi** fondi. Se è un **consigliere** o un altro ruolo, vale **qualunque fondo** con documentazione scoperta, con la precedenza a quello dell'attività.
- Valgono anche i fondi **già liquidati**.
- La spesa si può **dividere su due fondi**.

## Controllare e correggere

In **Cassa → Conti e fondi**, in basso, trovi:

- **Spese documentate non rimborsate**: ogni riga ha un menu e il pulsante **Attribuisci**;
- **Fondi accantonati non documentati**;
- **Attribuzioni recenti**, con il pulsante **Annulla**.

I primi due elenchi hanno il link **Esporta CSV**. In **Bacheca** compaiono le versioni brevi. Ogni attribuzione è tracciata nel registro azioni.

Per i conti e i fondi in generale vedi [Conti, fondi e giroconti](guida:conti-fondi-e-giroconti).

## Il prospetto dei rimborsi per persona

In **Contabilità → Bilancio provvisorio**, la sezione **«Rimborsi e spese documentate per persona»** mette a confronto quanto è stato rimborsato con le spese documentate di ciascuno. Ha i pulsanti **Esporta CSV** e **Scarica PDF per il rendiconto**.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'bilancio-provvisorio-e-avvisi-di-bilancio',
        'title' => 'Bilancio provvisorio, avvisi di bilancio e storico mensile',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale (da Pro 1.1.38, con il plugin gratuito 1.2.47: aggiorna insieme gratuito e Pro)

Con il Pro Fiscale hai sempre sott'occhio come sta andando l'anno fiscale.

## Il bilancio provvisorio

Vai in **Contabilità → Bilancio provvisorio**. La pagina mostra il bilancio dell'anno fiscale **aggiornato all'ultimo mese concluso**, l'andamento del **mese in corso** e la **stima di fine anno**. Lo stesso riquadro compare in **Bacheca**, **Contabilità**, **Report** e **Preventivo**.

## Gli avvisi di bilancio

Compaiono **solo quando c'è qualcosa che non va**: se tutto è a posto non c'è nessun messaggio verde.

- **«Spese non coperte»**: le spese superano gli incassi oltre una soglia, di partenza **10%**.
- **«Utile troppo alto»**: l'utile supera una soglia, di partenza **20%**.

Compaiono dopo almeno **3 mesi** dall'inizio dell'anno fiscale e si ricalcolano a ogni apertura. In **Bacheca** la riga si può chiudere. Le soglie si cambiano in **Impostazioni → Limiti e soglie**, gruppo **«Bilancio, avvisi e fondi»**.

## Lo storico mensile

Sempre in **Bilancio provvisorio**, la tabella **«Storico mensile»** ti fa scegliere l'anno e mostra i mesi uno per uno. A fine mese il plugin **salva da solo un'istantanea**. Puoi usare:

- **Esporta CSV** per l'anno scelto;
- **Esporta tutto** per tutti gli anni;
- **«Salva ora le foto dei mesi conclusi mancanti»**, se vuoi salvare subito quelle che mancano.

## Altre impostazioni

Nel gruppo **«Bilancio, avvisi e fondi»** di **Limiti e soglie** trovi anche i mesi minimi prima degli avvisi, l'istantanea mensile e i mesi di guardia indietro dell'attribuzione automatica ([vedi Spese documentate e fondi](guida:spese-documentate-e-fondi-dei-rimborsi)).
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'verifiche-di-fine-anno-e-chiusura',
        'title' => 'Verifiche di fine anno fiscale e chiusura dell\'anno',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale (da Pro 1.1.38, con il plugin gratuito 1.2.47: aggiorna insieme gratuito e Pro)

Prima della fine dell'anno fiscale il plugin ti avvisa se ci sono **discrepanze**, per esempio rimborsi senza spese documentate a copertura, e può **bloccare la chiusura** finché non le sistemi.

## Gli avvisi di fine anno

L'avviso ha **due fasi**:

1. **Preavviso**: parte **3 mesi prima** dell'avviso fisso (di partenza) e si può **chiudere**; la chiusura vale fino al lunedì.
2. **Avviso fisso**: **non si può chiudere**. Compare dal mese prima dell'ultimo mese dell'anno fiscale (con l'anno da gennaio a dicembre: dal 1° novembre).

Compare in **Bacheca**, **Cassa**, **Contabilità**, **Report** e **Preventivo**, **solo se ci sono discrepanze**, con i link **«verifica»**. Lo vedono amministratori, presidente, vicepresidente, la segreteria con il permesso contabile e il tesoriere.

Puoi attivare anche un **promemoria per email**, **spento** di partenza.

## Chiudere l'anno

1. Vai in **Contabilità → Anni solari** e premi **Chiudi l'anno** (vedi [Aprire e chiudere gli anni solari](guida:aprire-e-chiudere-gli-anni-solari)).
2. Di partenza la chiusura **si ferma** se chi ha avuto rimborsi non ha spese documentate a copertura.
3. Se hai scelto l'opzione **«chiedi conferma»**, compare una casella da spuntare per procedere.

## Cosa si può configurare

In **Impostazioni → Limiti e soglie**, gruppo **«Bilancio, avvisi e fondi»**:

- il **comportamento alla chiusura**: spento, solo avviso, chiede conferma, oppure blocca;
- la **tolleranza** sulla differenza, di partenza **1,00 €**;
- i **mesi di avviso fisso** e i **mesi di preavviso**;
- il **promemoria email**.
MD,
    ],
];
