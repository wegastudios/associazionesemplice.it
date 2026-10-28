<?php

// Guide: domanda di adesione online e minori (plugin 1.2.25, Pro 1.1.22).
return [
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'domanda-di-adesione-online',
        'title' => 'Ricevere le domande di adesione online',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (dalla versione 1.2.25 del plugin; aggiorna insieme gratuito e Pro). Il pagamento online della quota alla domanda richiede Pro.

Chi vuole iscriversi compila un modulo sul tuo sito e la segreteria lo **approva o lo rifiuta**. Non c'è nessuna iscrizione automatica: decidi sempre tu.

## Attivare il modulo

1. Vai in **Impostazioni → Soci e quote → Domanda di adesione online** e attivala (è spenta di default). Se invece usi la [configurazione guidata](guida:completare-la-configurazione-guidata), dalla versione 1.2.33 la accende da sola scegliendo **«Chiunque può chiedere di iscriversi»** (e la spegne con **«Solo su presentazione»**): chi ha già configurato non cambia nulla finché non riapre la procedura.
2. Indica, se vuoi, l'indirizzo per l'**Avviso alla segreteria**, a cui arriva un'email a ogni nuova domanda (se non lo indichi, va all'indirizzo di amministrazione del sito).
3. Inserisci il modulo in una pagina con lo shortcode `[associazionesemplice_adesione]`, con il blocco o con il widget Elementor **Associazione Semplice → Pubblico · Domanda di adesione online**. La pagina **«Diventa socio»** si crea da **Pagine del sito**.

## Cosa chiede il modulo

Nome, cognome, data di nascita, codice fiscale (con controllo di forma e di carattere finale), email, cellulare, tipo di socio (ordinario, e «socio e volontario» se lo hai acceso) e l'accettazione del **regolamento** e dell'**informativa privacy**.

Se la data di nascita indica un **minorenne**, il modulo chiede anche i dati del genitore o tutore (vedi [Minori con genitori o tutori](guida:minori-con-genitori-o-tutori)).

## Cosa succede dopo l'invio

- La domanda entra con stato **«richiesta»**: non compare in rubrica, libro soci, esportazioni, tessere né nei conti, non ha tessera né utente WordPress e non può usare il «Primo accesso».
- La segreteria riceve un'email e chi ha scritto riceve una conferma.
- La domanda si vede nella **Bacheca** di Associazione Semplice (riquadro **Domande di adesione**) e nel riquadro **Da lavorare** della Bacheca di WordPress.

## Approvare o rifiutare

1. Apri la domanda dalla Bacheca.
2. Premi **Approva** e scegli il **numero di tessera**: ti viene proposto il successivo libero, controllando che non sia già assegnato. Oppure premi **Rifiuta**.
3. Con l'approvazione la persona entra nel **libro soci** e al richiedente parte un'email con il **link per scegliere email e password**.
4. Con il rifiuto la domanda sparisce e a chi l'aveva inviata arriva un breve avviso. Se non c'è stato un pagamento, non resta alcun dato personale.

## Controllo dei doppioni e protezioni

- La domanda viene confrontata con le persone già presenti, e con quelle in coda, per **email** e **codice fiscale**.
- A chi compila il modulo la risposta è sempre la stessa («Domanda ricevuta…»), qualunque cosa succeda: così non si scopre chi è già socio.
- Ci sono limiti contro l'uso scorretto: al massimo 5 domande l'ora per connessione e 200 domande in attesa.

## La tessera e la quota

- L'iscrizione all'anno sociale **non si registra all'approvazione**: la tessera diventa valida **con l'incasso della quota**. La quota resta tra le voci da pagare, in sede o online dall'area riservata.
- Se la quota è stata già pagata online con la domanda, o è zero, la tessera vale **subito**.
- Il **pagamento online della quota alla domanda** funziona solo con **Stripe o PayPal** (Pro), non con WooCommerce. Se rifiuti la domanda, il pagamento resta **«da rimborsare»**: il rimborso si fa dal pannello del fornitore.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'minori-con-genitori-o-tutori',
        'title' => 'Iscrivere i minori con genitori o tutori',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (dalla versione 1.2.25 del plugin)

Un minore si registra con **uno o due genitori o tutori**, che gestiscono per lui tessera, prenotazioni, pagamenti e avvisi.

## Registrare un minore

1. Nella scheda della persona inserisci la **data di nascita**: è obbligatoria per chi ha un tutore.
2. Scegli **uno o due tutori** tra le persone con email già registrata. Un tutore deve essere maggiorenne (se la sua data è nota) e deve poter entrare nell'area riservata: un socio o una persona di tipo **«genitore o tutore»**. Un ospite non può essere tutore.
3. Salva.

Il genitore o tutore di tipo **«Genitore o tutore (non socio)»** **non è socio**: non compare nel libro soci, non ha la tessera, non paga la quota, non riceve i promemoria di rinnovo e non vota. Può invece entrare nell'area riservata per seguire il minore: riceve l'invito per scegliere la password, gli avvisi e i promemoria del minore. Il minore può anche non avere né email né utente WordPress, e può usare l'email del genitore.

## Da una domanda di adesione online

Se chi compila la domanda risulta minorenne dalla data di nascita, il modulo chiede anche nome, cognome, email e cellulare del genitore o tutore (il codice fiscale è facoltativo), e lo fa dichiarare come tale. Email e cellulare del minore diventano facoltativi. Vedi [Ricevere le domande di adesione online](guida:domanda-di-adesione-online).

## Cosa fa il genitore dall'area riservata

La sezione **«I miei figli»** (shortcode `[associazionesemplice_minori]`, già nell'area soci) mostra per ogni minore:

- la **tessera** e le attività;
- le caselle per accettare **regolamento e privacy a suo nome** (resta registrato chi ha accettato);
- in **Pagamenti**, le voci del minore, compresa la quota associativa: il genitore paga per lui;
- le **prenotazioni** agli eventi, che si fanno per il minore con la **tessera del minore** (anche se quella del genitore è scaduta).

Promemoria, avvisi delle attività e ricevute arrivano anche ai tutori, o **solo** a loro se il minore non ha email.

## Il diciottesimo compleanno

Nella Bacheca e in **Da lavorare** compare **«Maggiorenni da invitare»** con i ragazzi che non hanno ancora un accesso. Aggiungendo la loro email nella scheda, gli arriva l'invito per scegliere la password. Il collegamento con il tutore **resta** finché l'amministratore non lo toglie dalla scheda.

## Importare minori

Nel file Excel o CSV puoi usare le colonne facoltative **Data di nascita** (per esempio 31/12/2010) e **Tutore** (numero di tessera, email o nome e cognome; per indicarlo serve la data di nascita). Un tutore non trovato o ambiguo viene segnalato senza fermare le altre righe. Il libro soci e le esportazioni hanno le colonne *Data di nascita* e *Tutore*.
MD,
    ],
];
