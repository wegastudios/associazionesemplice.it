<?php

// Guide: funzioni Pro e privacy.
return [
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'conti-fondi-e-giroconti',
        'title' => 'Conti, fondi e giroconti',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

Con Pro puoi tenere più conti (cassa contanti, banca, carta, Stripe, PayPal…) e accantonare **fondi**, per esempio per il rimborso dei volontari.

## Gestire i conti

1. Vai in **Cassa → Conti e fondi**.
2. Per ogni conto puoi **rinominare**, cambiare tipo e saldo di partenza (il saldo attuale si ricalcola da solo).
3. Per **chiudere** un conto il saldo deve essere zero: prima sposta i soldi con un giroconto. I conti chiusi non accettano movimenti, ma restano nei rendiconti dei periodi in cui hanno lavorato.
4. Puoi riaprire un conto chiuso.

## Spostare denaro tra conti

1. Vai in **Cassa → Giroconto**.
2. Scegli conto di partenza, conto di arrivo, importo e data.
3. Salva. Il giroconto crea due righe collegate.

Le commissioni e i trasferimenti dai gateway di pagamento al conto corrente si registrano così: una spesa per la commissione e un giroconto per il trasferimento.

## Cassa per più persone

Con **Cassa per più persone** incassi in un'unica operazione quote e contributi di più persone, per esempio all'ingresso di un evento.

## Fondi

I fondi accantonano somme già incassate. Per ogni anno solare vedi quanto è stato accantonato, rimborsato o liberato e quanto resta da rimborsare: la tabella dei **fondi da rimborsare per anno** è nella pagina degli anni solari della versione Pro. **Un anno con fondi non rimborsati non si chiude.** Per aprire e chiudere gli anni vedi [Aprire e chiudere gli anni solari](guida:aprire-e-chiudere-gli-anni-solari).
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'rendiconto-per-cassa-e-report',
        'title' => 'Rendiconto per cassa e report dell\'anno',
        'body' => <<<'MD'
> Disponibile in: Report: Pro Contabile e Pro Fiscale. Rendiconto per cassa: Pro Fiscale.

Questa parte serve a chi deve preparare i documenti per il commercialista. L'apertura e la chiusura dell'anno solare sono invece disponibili in tutte le versioni: vedi [Aprire e chiudere gli anni solari](guida:aprire-e-chiudere-gli-anni-solari).

## Il rendiconto per cassa

1. Vai in **Contabilità → Rendiconto**.
2. Scegli l'anno: vedi entrate e uscite raggruppate per «voce di rendiconto», il confronto con l'anno precedente, l'avanzo o disavanzo, i saldi dei conti e i fondi accantonati.
3. Scrivi la relazione sull'andamento (testo libero per anno).
4. Scarica il PDF con le firme di tesoriere e presidente.

> Il rendiconto è un documento di lavoro: fallo verificare dal commercialista prima dell'approvazione.

## Report

La scheda **Report** offre **Conti e liquidità** (saldi, fondi, disponibilità reale), **Anno sociale** e **Anno solare (commercialista)**.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'iva-su-quote-e-incassi',
        'title' => 'IVA su quote, attività, incassi e spese',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale

Se l'ente ha la partita IVA, il plugin la gestisce a un livello minimo, pensato per chi ha un commercialista che completa i quadri. Senza partita IVA non compare nulla di fiscale.

## Impostare i dati fiscali

1. Vai in **Impostazioni → Ente e fiscalità → Dati e fiscalità**.
2. Inserisci la **partita IVA** (11 cifre, anche con prefisso IT): viene controllata.
3. Scegli il **regime**, l'**aliquota** proposta (22, 10, 5, 4 o 0 %) e se gli importi si scrivono IVA compresa o esclusa. Il codice SDI è facoltativo.

In regime forfettario l'IVA non si applica.

## Come si applica

- **Attività**: nella scheda scegli l'aliquota (o «Fuori campo IVA» per i contributi che non sono operazioni commerciali) e se l'importo è IVA compresa o esclusa.
- **Quote associative**: un'unica aliquota, di norma fuori campo IVA.
- **Incassi e spese**: l'aliquota è automatica o scelta nel modulo; ogni riga registra aliquota e IVA contenuta.
- **Ricevute**: mostrano partita IVA, imponibile e IVA contenuta per ogni aliquota.
- **Prima nota**: l'importo resta quello lordo, con la nota «di cui IVA».

## Per il commercialista

L'esportazione CSV della prima nota aggiunge aliquota, imponibile e IVA. Il plugin calcola lo scorporo mantenendo sempre la somma esatta.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'cinque-per-mille',
        'title' => 'Gestire il 5 per mille',
        'body' => <<<'MD'
> Disponibile in: Pro Fiscale

Il 5 per mille è spento di default e lo attiva un amministratore.

## Attivarlo

1. Vai in **Contabilità → Adempimenti**.
2. Attiva il **5 per mille**.
3. Controlla il messaggio standard (o personalizzalo con `{associazione}` e `{codice_fiscale}`).

## Chiedere la firma ai soci

- Inserisci lo shortcode `[associazionesemplice_cinquepermille]` (o il blocco omonimo) dove vuoi mostrare il messaggio, con il codice fiscale dell'associazione.
- Con un pulsante prepari il promemoria ai soci nelle **Comunicazioni**, già scritto.

## Registrare i contributi e il rendiconto

1. Nel registro dei **contributi ricevuti** inserisci anno di imposta, importo, scelte e data di accredito.
2. Il plugin calcola la scadenza del **rendiconto sull'utilizzo**, a 12 mesi dall'accredito, e ti avvisa in Bacheca quando sta per scadere (60 giorni prima) o è scaduto.
3. Quando lo prepari, registra il rendiconto con data e descrizione dell'uso.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'assicurazioni-e-presenze',
        'title' => 'Assicurazioni dei volontari e registro delle presenze',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

Nel menu **Registri** trovi gli elenchi da tenere in ordine. Gli elenchi si scaricano in PDF e CSV.

## Assicurazioni

Dalle **Impostazioni → Assicurazioni** puoi accendere, separatamente:

- il **registro delle assicurazioni dei volontari**: per ogni volontario le polizze (compagnia, numero, periodo) e lo stato (in regola, in scadenza entro 30 giorni, scaduta, nessuna polizza). In Bacheca compare un avviso se qualcuno è scoperto;
- le **polizze dell'associazione**: responsabilità civile, infortuni e altre coperture, con premio e massimale. Se la responsabilità civile è scoperta, scaduta o in scadenza, compare un avviso.

## Presenze

1. In **Registri → Presenze** scegli la lezione di un corso o la data di un evento.
2. A lezione fatta, segna chi c'era: la prima volta sono tutti presenti, togli gli assenti.
3. Il riepilogo per periodo mostra presenze, lezioni e percentuale; il PDF è a matrice (fino a 10 lezioni) o riepilogativo.

## Altri registri

Il **libro soci** e i **verbali** (assemblee e consiglio, con numerazione e firme) sono nello stesso menu e fanno parte anche della versione gratuita.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'Contabilità e adempimenti', 'slug' => 'livelli-di-socio-e-nucleo-familiare',
        'title' => 'Livelli di socio, quote differenziate e nucleo familiare',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

Con il plugin gratuito c'è una sola quota (con l'opzione socio fondatore). Con Pro puoi avere più tipi di socio, ognuno con la sua quota.

## Creare i livelli

1. Vai in **Impostazioni → Livelli di socio**.
2. Aggiungi una riga per ogni livello e indica:
   - il **nome** (compare su tessera, elenchi ed esportazioni);
   - la **base**: fondatore, ordinario, socio o volontario, che decide il comportamento (tessera, attività, cariche);
   - una **quota propria** facoltativa: se vuota, vale la quota predefinita.
3. Salva.

Chi non ha un livello assegnato usa il nome della base e la quota predefinita: nessuna migrazione dei soci esistenti. Un livello con dei soci non si cancella: resta disattivato.

## Nucleo familiare

1. Nella scheda del socio scegli il **capofamiglia**.
2. Il capofamiglia paga la quota piena; i familiari pagano la quota del loro livello ridotta dello **sconto nucleo familiare** (in percentuale, nelle impostazioni).

Il capofamiglia deve essere un socio e non un familiare. Gli ospiti non hanno livello né nucleo.

La quota calcolata vale ovunque: incassi del tesoriere, pagamenti online, promemoria e Bacheca.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'App, Wallet e testi', 'slug' => 'app-installabile-e-notifiche',
        'title' => 'App installabile e notifiche sul telefono',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

L'area soci può diventare un'**app installabile** sul telefono, con notifiche. Non è un'app dello store: si installa dal browser.

## Requisiti

- Sito in **https**, permalink «carini» e l'estensione **openssl** di PHP (per le notifiche).
- Su iPhone: iOS 16.4 o successivo e app aggiunta alla schermata Home.

## Attivarla

1. Vai in **Impostazioni → Tecniche → App e notifiche**.
2. Attiva l'app e, se vuoi, le notifiche.
3. Scegli nome e icona (un'immagine quadrata di almeno 192 px, meglio 512).

## Cosa fa il socio

1. Nell'area soci apre la sezione **App e notifiche**.
2. Preme **Installa l'app** (su iPhone segue le istruzioni per la schermata Home).
3. Attiva le notifiche con un tocco, su ogni dispositivo.

## Quando arrivano le notifiche

Insieme alle email, per comunicazioni a gruppi, avvisi dei volontari, promemoria e posti liberati dalla lista d'attesa. Chi non le ha attivate riceve solo l'email.

> Per la privacy l'app **non salva mai pagine con dati personali**: offre soltanto una pagina «Sei offline» e gli stili del plugin. Spegnendo l'app, scompare da sola dai dispositivi.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'App, Wallet e testi', 'slug' => 'tessera-nel-wallet',
        'title' => 'La tessera in Apple Wallet e Google Wallet',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

Il socio può salvare la tessera nel wallet del telefono. Il sito non contatta mai Apple o Google: la tessera Apple è un file firmato sul tuo sito, quella Google un indirizzo firmato.

## Cosa serve

- **Apple Wallet**: un account **Apple Developer** (a pagamento), un **Pass Type ID** con il certificato in formato `.p12` (con la sua password) e il certificato intermedio **WWDR**. Il certificato dura circa un anno e il plugin avvisa quando sta per scadere.
- **Google Wallet**: un account **Google Pay & Wallet Console** con l'ID emittente e un **account di servizio** Google Cloud, con la sua chiave in formato JSON. Finché l'emittente è in prova, il salvataggio è consentito solo agli utenti di test.

## Cosa fare

1. Vai in **Impostazioni → Tecniche → Tessera/QR/Wallet**.
2. Carica le credenziali Apple e/o Google. Le chiavi private sono salvate cifrate e legate al sito: se copi il sito altrove vanno ricaricate.
3. Nella tessera digitale del socio compaiono **Aggiungi ad Apple Wallet** e/o **Salva su Google Wallet**.

## Da sapere

La tessera nel wallet mostra nome, tipo, numero e scadenza **al momento dell'emissione**: non si aggiorna da sola al rinnovo. Se è attivo il QR della tessera, anche il QR è presente e verifica sempre la validità in diretta. Gli ospiti non hanno tessera.
MD,
    ],
    [
        'topic' => 'plugin-pro', 'section' => 'App, Wallet e testi', 'slug' => 'personalizzare-testi-e-lingue',
        'title' => 'Personalizzare i testi, il tipo di ente e le lingue',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

Con Pro puoi cambiare i testi mostrati da pagine, email e PDF, scegliere come chiamare le cose («associazione» o «comitato», «socio» o «tesserato») e aggiungere altre lingue.

## Tipo di ente e termini

1. Vai in **Impostazioni → Ente e fiscalità → Testi e lingua** (in cima alla pagina).
2. Scegli il tipo di ente e i termini da usare. I testi si adeguano.

## Modificare un testo

1. Nella stessa pagina cerca il testo da cambiare.
2. Scrivi la tua versione e salva. La tua versione ha **sempre la precedenza** sul testo originale.
3. Se in futuro vuoi tornare all'originale, svuota il campo.

## Lingue

- Il pacchetto **inglese** è incluso.
- Per aggiungere una lingua, esporta i testi, compila le colonne «Originale» e «Traduzione» e ricarica il file (CSV, Excel o JSON) con un codice lingua (`fr`, `de`, `es`…) e un nome.
- Le parole singole e brevi («Salva», «Data») si traducono solo se sono il testo intero. Le frasi senza traduzione restano in italiano.

> Se la licenza non è in regola tornano i testi originali; le tue personalizzazioni restano salvate e riappaiono quando regolarizzi.
MD,
    ],
    [
        'topic' => 'altro', 'section' => 'Privacy e dati personali', 'slug' => 'informativa-privacy-e-consensi',
        'title' => 'Informativa privacy e consensi dei soci',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Il plugin compila da solo l'informativa sulla privacy con i dati della tua associazione. È un **modello**: fallo verificare dal tuo consulente.

## Generare l'informativa

1. Controlla i dati dell'ente in **Impostazioni → Ente e fiscalità → Dati e fiscalità** (denominazione, codice fiscale, sede, PEC) e l'email per la privacy in **Impostazioni → Privacy**.
2. Apri l'anteprima nelle impostazioni.
3. Scarica il PDF (con la firma di presa visione) oppure pubblicala come pagina del sito con lo shortcode `[associazionesemplice_privacy]`.

Il responsabile del trattamento è il presidente; in mancanza, il socio con il ruolo di segreteria. L'informativa cita Stripe, PayPal, Wallet e notifiche solo se sono attivi.

## Registrare i consensi

- Nella **scheda persona** registri quando e come è stato dato il consenso: cartaceo, sul sito, a voce, importato.
- Nella Rubrica usi il filtro **Senza consenso privacy** per trovare chi manca.
- Se indichi la pagina dell'informativa, chi attiva il proprio accesso deve accettarla e il consenso si registra da solo.

## Regolamento

Allo stesso modo puoi pubblicare il **regolamento** dell'associazione: chi attiva l'accesso deve accettarlo. Cambiando la **versione**, tutti devono accettare di nuovo.
MD,
    ],
    [
        'topic' => 'altro', 'section' => 'Privacy e dati personali', 'slug' => 'anonimizzare-un-ex-socio-e-scaricare-i-dati',
        'title' => 'Anonimizzare un ex socio e scaricare i propri dati',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

## Scaricare i dati di una persona

- **Il socio**: dal suo profilo nell'area soci scarica i propri dati in formato JSON.
- **L'amministratore**: dalla scheda persona scarica i dati di quel socio.

## Anonimizzare una persona

L'anonimizzazione toglie nome, contatti, codice fiscale, tessera e note, scollega (ed elimina, se è solo un socio) l'utente del sito e sostituisce il nome nelle descrizioni dei movimenti. **I movimenti contabili restano**, per l'obbligo di conservazione.

1. Apri la scheda della persona.
2. Premi **Anonimizza** e conferma.

Non si può anonimizzare chi ha ospiti, iscrizioni a corsi, prenotazioni future o cariche, né un amministratore: prima risolvi queste situazioni.

## Ex soci da anonimizzare

L'elenco **Ex soci da anonimizzare** mostra chi è inattivo da più di N anni (5 di default, modificabile nelle impostazioni). La decisione è tua, caso per caso.

> Le persone anonimizzate spariscono dagli elenchi. L'operazione non si può annullare.
MD,
    ],
];
