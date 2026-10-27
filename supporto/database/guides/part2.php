<?php

// Guide: soci, tessere e area riservata.
return [
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'importare-i-soci-da-excel-o-csv',
        'title' => 'Importare i soci da Excel o CSV',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Se hai già un elenco di soci, non devi riscriverlo: puoi importarlo da un file Excel (`.xlsx`) o CSV. Prima di salvare vedi sempre un'anteprima.

## Prepara il file

- Una riga di intestazione con i nomi delle colonne. Può stare anche sotto qualche riga di titolo.
- Colonne riconosciute per **soci e ospiti**: *Numero tessera, Tipo, Nome, Cognome, Email, Telefono, Codice fiscale* e, per gli ospiti, *Ospite di* (tessera, email o nome e cognome del socio).
- Se il file Excel ha più fogli (per esempio «Soci», «Ospiti», «Prima nota»), il plugin li legge tutti e capisce da solo a cosa serve ciascuno. I fogli non riconosciuti vengono ignorati.
- I vecchi file `.xls` vanno salvati prima come `.xlsx` o CSV.

## Importa

1. Vai in **Soci → Importa da Excel/CSV**.
2. Carica il file.
3. Controlla l'**anteprima**: gli eventuali errori indicano il numero di riga del foglio.
4. Conferma l'importazione.

## Da sapere

- I soci già presenti vengono **aggiornati** (riconosciuti per tessera, email, codice fiscale o nome): importare due volte lo stesso file non crea doppioni.
- Limiti: 5000 righe per tipo e 20 MB per file. Le formule di Excel si leggono con il risultato salvato.
- In alternativa puoi usare **WP All Import**: il plugin mette a disposizione due tipi di contenuto di appoggio, applicando le stesse regole.
- Anche la **prima nota** si può importare da file: vedi [Importare ed esportare la prima nota](guida:importare-ed-esportare-la-prima-nota).
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'iscrivere-un-socio-e-rinnovare-la-tessera',
        'title' => 'Iscrivere un socio e rinnovare la tessera',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (livelli di socio e quote diverse: Pro)

## Iscrivere un nuovo socio

1. Vai nell'elenco **Soci** e aggiungi una nuova persona con nome e cognome. L'**email è facoltativa**: con l'email il socio ha subito l'accesso all'area riservata; senza, l'accesso si attiva con un link da mandargli su WhatsApp.
2. Registra la quota associativa dalla scheda del socio o dall'incasso: se il socio ha la tessera non valida, la quota si inserisce da sola in qualunque incasso.
3. La tessera si attiva quando la quota è **saldata** (vedi «Quota pagata a rate» più sotto).

Creando il socio il plugin crea anche l'**utente WordPress** con la stessa email, con il solo ruolo «Socio»: nessun accesso alla gestione e nessuna email automatica. Se esiste già un utente con quell'email, viene collegato senza cambiargli ruolo.

## Come dura la tessera

La tessera dura l'anno solare e **scade sempre il 31 dicembre**, per tutti tranne i soci fondatori (tessera sempre rinnovata). Chi si iscrive a fine anno può pagare la tessera dell'anno dopo e avere gratis quella in corso.

## Rinnovare

1. Dalla **Bacheca** apri l'elenco **Soci da rinnovare**: tessera scaduta o in scadenza.
2. Premi **Incassa** accanto al socio: l'incasso si apre già compilato con la persona e gli importi dovuti.
3. Se un socio non rinnova più, puoi **Sospendere**: diventa inattivo, non compare più in Bacheca e non può prenotare finché non lo riattivi a mano dalla sua scheda.

## Quota pagata a rate

Dalla versione 1.2.30 del plugin gratuito un socio può versare la quota in più volte.

- Se versa **meno della quota** (per esempio 20 su 25), nella scheda del socio vedi **«versati 20 su 25, mancano 5»** con il pulsante **Incassa il saldo**. Lo stesso avviso compare in **Pagamenti da incassare**.
- L'incasso si apre **già compilato con il residuo**, e il **pagamento online** propone il saldo.
- La **tessera arriva solo al saldo**. Se annulli un versamento, la tessera decade.

### Cambiare il comportamento

In **Impostazioni → Limiti e soglie** trovi **«Tessera valida solo quando la quota è saldata»**, attivo di partenza. Se lo **spegni**, basta un versamento qualsiasi per attivare la tessera, come prima.

### Quota ridotta di proposito

Se il socio deve pagare meno per un motivo preciso (una riduzione concordata), **non** registrarlo come rata: usa il campo **sconto** nell'incasso, così la quota risulta saldata.

## Altro che può servire

- **Livelli di socio** e **nucleo familiare** (sconto per i familiari del capofamiglia): vedi [Livelli di socio e nucleo familiare](guida:livelli-di-socio-e-nucleo-familiare).
- Un socio che esce (recesso, esclusione, decesso) si registra come **cessato** dalla sua scheda e resta nel libro soci.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'gestire-gli-ospiti',
        'title' => 'Gestire gli ospiti e le partecipazioni',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Ai non soci è consentito partecipare solo poche volte: oltre, anche per ragioni assicurative, dovrebbero iscriversi. Il plugin **non blocca nessuno**: tiene il conto e ti aiuta ad accorgertene.

## Cosa fa il plugin

- Chiede il **cellulare** per ogni ospite: è il dato migliore per riconoscerlo e scrivergli su WhatsApp.
- Conta le partecipazioni (prenotazioni a eventi e iscrizioni a corsi). Raggiunta la **soglia** (di norma 2) l'ospite compare come **«da invitare a iscriversi»**.
- Riconosce le schede «gemelle», cioè con lo stesso cellulare, la stessa email o lo stesso nome, e ne **somma** le partecipazioni.

## Cosa fare

1. Per impostare la soglia vai in **Impostazioni → Ospiti** (0 = nessuna segnalazione).
2. Nell'elenco persone usa il filtro **Solo ospiti da invitare a iscriversi**.
3. Apri la scheda dell'ospite: vedi a cosa è già venuto e puoi scrivergli su WhatsApp con un messaggio d'invito già pronto.
4. Per trasformarlo in socio premi **Iscrivi come socio** (serve l'email). La scheda resta la stessa: eventi, corsi e pagamenti già registrati restano collegati.

## Se non vuoi ospiti

Se l'ente non li accetta (**Impostazioni → Soci e quote**), non se ne registrano di nuovi e la sezione «I miei ospiti» sparisce dall'area soci. Gli ospiti già registrati restano.

> I soci vedono solo il conto dei propri ospiti, senza segnalazioni.

## Ospiti dell'associazione e ospiti del volontario

> Disponibile in: Pro Contabile e Pro Fiscale (compare in prenotazione solo con il Pro, perché si calcola sulla quota del volontario). Solo per amministratori, segreteria e consiglieri con accesso operativo, mai per il socio.

Quando un evento ha una quota per il rimborso del volontario, puoi segnare un ospite in due modi:

- **Ospite dell'associazione**: non paga la parte che resta all'associazione. Versa solo la quota del volontario, che va tutta al suo fondo.
- **Ospite del volontario**: non paga la quota del volontario e il suo fondo non si alimenta. Versa la parte dell'associazione.

Le parti derivano dalla quota per il rimborso dell'attività. Si impostano in prenotazione, da **Cassa → Prenota** o dalla scheda dell'evento.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'assegnare-ruoli-e-permessi',
        'title' => 'Assegnare ruoli e permessi (segreteria, tesoriere, staff)',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

I permessi derivano dai dati della persona, non solo dai ruoli di WordPress. Si assegnano dalla **scheda del socio**.

## I ruoli

- **Amministratore** del sito: tutto, comprese impostazioni, pagamenti online e copia di sicurezza.
- **Segreteria**: gestisce soci, attività, incassi, registri e comunicazioni dall'amministrazione, senza essere amministratore del sito e senza accesso alle impostazioni.
- **Presidente e vicepresidente**: agiscono come la segreteria finché la carica e la tessera sono valide.
- **Tesoriere**: dall'area riservata incassa, registra le spese, iscrive nuovi soci e vende gli eventi. Non vede comunicazioni né impostazioni.
- **Volontario**: non gestisce soldi; per le attività di cui è referente gestisce avvisi agli iscritti, staff e ingressi.
- **Staff dell'ente**: verifica gli ingressi di tutti gli eventi.
- **Staff dell'evento**: verifica gli ingressi di quell'evento e, se abilitato, incassa il biglietto sul posto.
- **Socio**: i propri dati, la tessera, le prenotazioni e i pagamenti.

## Come assegnare

1. Apri la scheda del socio.
2. Nel riquadro **Segreteria** (solo amministratori) o nei campi del ruolo, scegli quello che serve.
3. Salva. Il permesso è immediato; per toglierlo basta rimuovere il ruolo.

## Quadro completo

**Impostazioni → Tecniche → Ruoli e accessi** mostra la matrice «chi può fare cosa» e l'elenco di chi ha un ruolo. Puoi anche dare l'accesso operativo della segreteria a un altro ruolo di WordPress.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'attivare-il-qr-della-tessera',
        'title' => 'Attivare il QR della tessera e dei biglietti',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Il QR permette di verificare al volo una tessera o una prenotazione, anche a chi non ha accesso al sito. È facoltativo ed è spento di default.

## QR della tessera

1. Vai in **Impostazioni → Tecniche → Tessera/QR/Wallet** (scheda *Tessera e Wallet*).
2. Attiva **QR sulla tessera digitale**.
3. Nella tessera digitale del socio compare il QR.

Chi lo scansiona apre una pagina che dice se la tessera è **valida in questo momento**, con nome, tipo, numero e scadenza e nient'altro. La verifica è in diretta: il QR non cambia al rinnovo. Gli ospiti non hanno tessera.

## Biglietto QR degli eventi

1. Apri la scheda di un evento (o evento ricorrente).
2. Nel riquadro **Biglietto QR** scegli **Genera un QR per ogni prenotazione**.
3. Chi prenota trova il QR sotto la prenotazione. Il socio vede anche quelli dei propri ospiti.

Scansionandolo si vede se la prenotazione è valida, per quale data, se è stata annullata e se il contributo è versato. I corsi non hanno prenotazioni.

## Sicurezza

Il QR contiene solo un codice firmato, legato al tuo sito. **Rigenera tutti i QR** annulla tutti i codici in circolazione: usalo se pensi che siano stati diffusi.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Soci', 'slug' => 'personalizzare-la-tessera',
        'title' => 'Personalizzare la tessera',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

La tessera standard è bianca e stampabile, con il logo e i testi nei colori della tua associazione. Puoi anche disegnarla tu.

## Colori e logo

1. Vai in **Impostazioni → Soci e identità → Aspetto**.
2. Scegli il logo dalla libreria media e il colore principale e secondario. Di default si prendono dal sito (Elementor o il tema).
3. Spunta **Personalizza** per cambiarli. Togli la spunta per tornare ai colori del sito.

## Tessera su immagine propria

1. In **Aspetto → Tessera** scegli le **misure in centimetri**: orizzontale (8,56 × 5,4), verticale, quadrata o personalizzata.
2. Carica dalla libreria media l'immagine di sfondo, oppure parti dalla tessera generata dal sito.
3. Trascina sopra l'immagine il logo, il nome e cognome, il numero di tessera, la scadenza e il QR.
4. Per ogni dato scegli se mostrarlo, la dimensione e il colore.
5. Premi **Salva tessera**. L'anteprima mostra la tessera con un QR di esempio.

Le misure sono in percentuale della larghezza, quindi la tessera si ridimensiona da sola ed è identica a schermo e in stampa (**Stampa la tessera**).

> Dopo aver spostato i campi controlla l'anteprima prima di salvare: ogni modifica seleziona la tessera personalizzata.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Area riservata', 'slug' => 'area-riservata-dei-soci',
        'title' => 'Creare l\'area riservata dei soci',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

L'area riservata è la pagina dove ogni socio trova il suo spazio: tessera, attività, pagamenti, ospiti, profilo, avvisi e calendario.

## Creare la pagina

1. La configurazione guidata crea la pagina **Area soci**: se non l'hai fatto, riaprila da **Strumenti → Configurazione guidata**.
2. In alternativa crea una pagina e inserisci lo shortcode `[associazionesemplice_area_soci]` oppure il blocco **Associazione Semplice**.
3. Pubblica e aggiungi la pagina al menu. La voce **Area riservata** compare da sola.

## Cosa vede ciascuno

- **Tutti i soci**: «Il mio spazio» con tessera, attività, pagamenti, ricevute, ospiti, profilo, avvisi, calendario e regolamento.
- **Chi ha un ruolo**: un piccolo menu laterale con **Segreteria**, **Gestione corsi** e **Gestione eventi**.

## Aspetto e contenuti

- Il colore d'accento è quello scelto in **Impostazioni → Aspetto**.
- Il messaggio mostrato a chi non ha i permessi si cambia dalle impostazioni.
- Con l'attributo `sezioni` dello shortcode puoi tornare all'elenco piatto, senza menu laterale.

> Le pagine che mostrano contenuti riservati controllano sempre i permessi di chi le apre.
MD,
    ],
    [
        'topic' => 'soci-e-tessere', 'section' => 'Area riservata', 'slug' => 'primo-accesso-e-soci-senza-email',
        'title' => 'Primo accesso dei soci e soci senza email',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

## Come entra un socio la prima volta

Nella pagina di accesso e nell'area riservata c'è il link **Primo accesso**.

1. Il socio indica nome, email e cellulare.
2. Se l'email è già quella di un socio, gli arriva il link per scegliere la password.
3. Se non c'è corrispondenza sull'email, la richiesta arriva alla segreteria.

La risposta mostrata al socio è sempre la stessa, così non si scopre chi è socio e chi no. Ogni indirizzo IP può fare 10 tentativi l'ora.

## Cosa fa la segreteria

Se il socio ha indicato solo il cellulare, il plugin **non modifica nulla da solo**: il cellulare non è un segreto e da solo non prova chi sei.

1. Apri la **Bacheca**: la richiesta compare nell'elenco.
2. Verifica la persona, per esempio scrivendole su WhatsApp con il pulsante già pronto.
3. Scegli **Approva e manda il link**: si crea l'utente (o si cambia l'email) e parte il link. Oppure **Rifiuta**.

## Soci senza email

Nella scheda del socio l'**email è facoltativa**. Con l'email il socio ha subito l'accesso all'area riservata. Senza email, l'accesso si attiva con un **link da mandare al socio su WhatsApp**.

## Se l'ente chiede dati obbligatori

Puoi indicare la pagina dell'informativa privacy e un regolamento: chi attiva il proprio accesso deve accettarli, e l'accettazione si registra con data e versione.
MD,
    ],
];
