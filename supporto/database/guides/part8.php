<?php

// Guide: domanda di adesione online e minori (plugin 1.2.25, Pro 1.1.22).
return [
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'domanda-di-adesione-online',
        'title' => 'Ricevere le domande di adesione online',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale} (aggiorna insieme gratuito e Pro). Il pagamento online della quota alla domanda richiede Pro.

Chi vuole iscriversi compila un modulo sul tuo sito e la segreteria lo **approva o lo rifiuta**. Non c'è nessuna iscrizione automatica: decidi sempre tu.

## Attivare il modulo

1. Vai in **Impostazioni → Soci e quote → Domanda di adesione online** e attivala (è spenta di partenza). Nella [configurazione guidata](guida:completare-la-configurazione-guidata) la domanda si accende scegliendo **«Chiunque può chiedere di iscriversi»** e si spegne con **«Solo su presentazione»**.
2. Se vuoi, in **Avviso alla segreteria** scrivi l'indirizzo che riceve un'email a ogni nuova domanda. Se lo lasci vuoto, l'email va all'amministratore del sito.
3. Inserisci il modulo in una pagina con lo shortcode `[associazionesemplice_adesione]`, con il blocco o con il widget Elementor **Associazione Semplice → Pubblico · Domanda di adesione online**. La pagina **«Diventa socio»** si crea da **Pagine del sito**.

## Cosa chiede il modulo

Nome, cognome, data di nascita, **luogo di nascita e residenza**, codice fiscale (il modulo controlla che sia scritto bene), email, cellulare, tipo di socio (ordinario, e «socio e volontario» se lo hai acceso), la **liberatoria per foto e video** (per i minori risponde il genitore o tutore) e l'accettazione del **regolamento** e dell'**informativa privacy**.

Se la data di nascita indica un **minorenne**, il modulo chiede anche i dati del genitore o tutore (vedi [Minori con genitori o tutori](guida:minori-con-genitori-o-tutori)).

**Nome e cognome** (anche del genitore o tutore) possono avere fino a **60 caratteri**: solo **lettere, spazi, apostrofi e trattini**. Altrimenti compare «Nome non valido: usa solo lettere, spazi, apostrofi e trattini.»

## Cosa succede dopo l'invio

- Chi ha compilato il modulo riceve subito un'email con un **link di conferma, valido 48 ore**. La domanda arriva alla segreteria **solo dopo il clic** sul link: prima non compare in Bacheca e la segreteria non riceve l'avviso.
- Dopo il clic, finché non la approvi la domanda resta in stato **«richiesta»**: la persona non compare in rubrica, libro soci, esportazioni e conti, non ha la tessera né un accesso al sito e non può usare il «Primo accesso».
- La segreteria riceve un'email a ogni domanda confermata.
- La domanda si vede nella **Bacheca** di Associazione Semplice (riquadro **Domande di adesione**) e nel riquadro **Da fare** della Bacheca di WordPress.

## Approvare o rifiutare

1. Apri la domanda dalla Bacheca.
2. Premi **Approva** e conferma il **numero di tessera** (il programma propone il successivo libero). Oppure premi **Rifiuta**.

- **Se approvi**, la persona entra nel libro soci e riceve un'email con il **link per scegliere email e password**.
- **Se rifiuti**, la domanda sparisce e chi l'aveva inviata riceve un breve avviso. Se non c'è stato un pagamento, non resta alcun dato personale.

## Controllo dei doppioni e protezioni

- La domanda viene confrontata con le persone già presenti, e con quelle in coda, per **email** e **codice fiscale**.
- A chi compila il modulo la risposta è sempre la stessa («Domanda ricevuta…»), qualunque cosa succeda: così non si scopre chi è già socio.
- Ci sono limiti contro l'uso scorretto: al massimo 5 domande l'ora per connessione, **un invio l'ora per indirizzo email** e 200 domande in attesa.

## Domande non confermate e dati conservati

- Le domande **mai confermate** (il link non è stato aperto) vengono **cancellate dopo 30 giorni**.
- Una domanda **confermata** resta in coda finché non la approvi o la rifiuti: **non scade**.
- Se rifiuti una domanda con la **quota già pagata**, dopo **90 giorni** restano solo **nome e cognome**.

## La tessera e la quota

- Approvare non basta: la tessera diventa valida **quando incassi la quota**. Fino ad allora la quota resta tra le voci da pagare, in sede o online dall'area soci.
- Se la quota è stata già pagata online con la domanda, o è zero, la tessera vale **subito**.
- Il **pagamento online della quota alla domanda** funziona solo con **Stripe o PayPal** (Pro), non con WooCommerce. Se rifiuti la domanda, il pagamento resta **«da rimborsare»**: il rimborso con Stripe o PayPal si fa dal programma (vedi [Restituire un pagamento online al socio](guida:restituire-un-pagamento-online-al-socio)).
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'minori-con-genitori-o-tutori',
        'title' => 'Iscrivere i minori con genitori o tutori',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

Un minore si registra con **uno o due genitori o tutori**, anche non soci e senza email. Sul sito però **agisce solo il primo**: gestisce per il minore tessera, prenotazioni, pagamenti e avvisi. Per i minori già registrati vale il primo tutore già collegato. Nel libro soci il minore prende le date (domanda, delibera) di questo tutore.

## Registrare un minore

1. Nella scheda della persona inserisci la **data di nascita**: è obbligatoria per chi ha un tutore.
2. Scegli **uno o due tutori**: soci o persone di tipo **«genitore o tutore»**, maggiorenni, anche **senza email**. Un ospite non può essere tutore.
3. Salva.

Chi è registrato come **«Genitore o tutore (non socio)»** non compare nel libro soci, non ha la tessera, non paga la quota, non riceve i promemoria di rinnovo e non vota. Può invece entrare nell'area soci per seguire il minore: riceve l'invito per scegliere la password, gli avvisi e i promemoria del minore. Il minore può non avere né email né un accesso suo, oppure usare l'email del genitore.

## Da una domanda di adesione online

Se chi compila la domanda risulta minorenne dalla data di nascita, il modulo chiede anche nome, cognome, email e cellulare del genitore o tutore (il codice fiscale è facoltativo) e gli fa dichiarare di esserlo. Email e cellulare del minore diventano facoltativi. Vedi [Ricevere le domande di adesione online](guida:domanda-di-adesione-online).

### Se il genitore è già in anagrafica

Quando approvi la domanda di un minore, il plugin guarda l'email del genitore o tutore indicata nella domanda. Se non è ancora in anagrafica, il genitore viene registrato e collegato subito al minore, senza nessuna conferma. Se è già in anagrafica, il collegamento con il minore e le accettazioni date a suo nome (regolamento, informativa privacy, scelta sulle immagini) non si attribuiscono da soli: prima il genitore deve **confermare**.

1. All'approvazione il genitore riceve, **all'indirizzo già registrato**, l'email **«Conferma del genitore o tutore»** con un link che vale **7 giorni**.
2. Il link apre la pagina **«Conferma del genitore o tutore»** con il pulsante **«Confermo»**: **aprire il link non basta**, la conferma c'è solo premendo il pulsante. Poi compare **«Grazie, confermato.»**

Il collegamento tra genitore e minore nasce solo dopo la conferma.

**In segreteria**, nella scheda del minore (riquadro **Privacy**) vedi **«In attesa della conferma del genitore»**, con il nome del genitore, la data dell'email e la scritta «Il genitore non ha ancora confermato: il collegamento si crea quando conferma». Puoi **approvare la domanda anche prima** della conferma. Con **«Rimanda l'email di conferma»** il messaggio riparte (**al massimo 3 volte all'ora**: dopo compare «Hai già rimandato il messaggio più volte: riprova tra un'ora.»).

Se il genitore apre il link dopo i 7 giorni vede **«Il link è scaduto: scrivi alla segreteria, che ti manda un nuovo messaggio.»**: basta rimandare l'email dalla scheda del minore.

## Cosa fa il genitore dall'area soci

La sezione **«I miei figli»** (shortcode `[associazionesemplice_minori]`, già nell'area soci) mostra per ogni minore:

- la **tessera** e le attività;
- le caselle per accettare **regolamento e privacy a suo nome** (resta registrato chi ha accettato);
- in **Pagamenti**, le voci del minore, compresa la quota associativa: il genitore paga per lui;
- le **prenotazioni** agli eventi, che si fanno per il minore con la **tessera del minore** (anche se quella del genitore è scaduta).

Promemoria, avvisi delle attività e ricevute arrivano anche ai tutori, o **solo** a loro se il minore non ha email.

## Il diciottesimo compleanno

Nella Bacheca e in **Da fare** compare **«Maggiorenni da invitare»** con i ragazzi che non hanno ancora un accesso. Aggiungendo la loro email nella scheda, gli arriva l'invito per scegliere la password.

**A 18 anni il genitore o tutore collegato smette di agire per il ragazzo**, anche se questo non ha ancora un accesso suo: non lo vede più nell'area soci, non prenota né paga per lui e non riceve più le sue email. Il **collegamento resta registrato** e si toglie dalla scheda.

Se preferisci che il tutore continui anche dopo i 18 anni, spegni in **Impostazioni → Limiti e soglie** l'opzione **«Il tutore smette di agire per chi compie 18 anni»** (è **accesa** di partenza).

## Importare minori

Nel file Excel o CSV puoi usare le colonne facoltative **Data di nascita** (per esempio 31/12/2010) e **Tutore** (numero di tessera, email o nome e cognome; per indicarlo serve la data di nascita). Un tutore non trovato o ambiguo viene segnalato senza fermare le altre righe. Il libro soci e le esportazioni hanno le colonne **Data di nascita** e **Tutore**.
MD,
    ],
];
