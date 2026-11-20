<?php

// Guide: cassa, pagamenti online e ricevute.
return [
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'registrare-un-incasso',
        'title' => 'Registrare un incasso',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale} (cassa per più persone e IVA: Pro)

Ogni versamento (quota, mensilità, contributo di un evento, donazione) entra nella prima nota. Il plugin ricalcola sempre importi e voci.

## Cosa fare

1. Vai in **Cassa → Nuovo incasso**.
2. Scegli la persona. Se ha la tessera non valida, la quota associativa si inserisce da sola.
3. Controlla le voci proposte: mensilità e contributi già dovuti compaiono precaricati. Puoi aggiungere o togliere righe.
4. Scegli il conto e la modalità di pagamento.
5. Se incassi in **contanti**, scrivi quanto hai ricevuto: la calcolatrice dice il **resto**, con i tagli.
6. Salva.

## Scorciatoie

- Dalla **Bacheca**, il pulsante **Incassa** apre l'incasso già compilato con le mensilità dovute e i contributi non ancora pagati.
- Se il tuo tesoriere lavora da telefono, può incassare dalla pagina **Tesoriere** dell'area soci.

## Se hai sbagliato

I movimenti della prima nota **non si modificano**: si **annullano** (resta traccia dell'annullamento) e si registra quello giusto. Un incasso annullato sparisce da saldi, report e ricevute. Se annulli l'incasso di una quota, la tessera non è più valida, a meno che ci sia **un altro incasso valido** della stessa quota per lo stesso socio e anno (per esempio se era stata incassata due volte).

> Un incasso può avere una ricevuta in PDF (vedi [Ricevute in PDF](guida:ricevute-in-pdf), funzione Pro).
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'registrare-una-spesa-con-allegato',
        'title' => 'Registrare una spesa con lo scontrino allegato',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

## Cosa fare

1. Vai in **Cassa → Nuova spesa**.
2. Compila importo, data, conto e voce di spesa. Con la partita IVA puoi indicare anche aliquota e se l'importo è IVA compresa o esclusa.
3. Nel campo **Documenti** carica uno o più PDF o foto. Dal telefono puoi premere **Scatta una foto**.
4. Salva.

Per aggiungere altri allegati più tardi apri la **Prima nota**, trova il movimento e aggiungili da lì.

## Regole degli allegati

- Formati ammessi: PDF, JPG, PNG, WebP e HEIC, fino a 10 MB ciascuno e 10 per movimento.
- Se un file non è ammesso la spesa **non viene registrata** e il modulo resta com'era.
- I doppioni (stesso contenuto) vengono rifiutati.
- Le foto vengono ridotte dal browser prima dell'invio.

## Dove finiscono i file

Non vanno nella libreria media del sito: stanno in una cartella privata e li apre solo chi ne ha il permesso. **Togli** non cancella il file: l'allegato esce dall'elenco e l'operazione resta nel registro azioni.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'aprire-e-chiudere-gli-anni-solari',
        'title' => 'Aprire e chiudere gli anni fiscali',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

L'anno fiscale (l'«esercizio», per il commercialista) è l'anno della contabilità: la prima nota è divisa per anno fiscale. Solo gli amministratori possono aprirlo, chiuderlo e riaprirlo. Di partenza coincide con l'anno solare; il mese di inizio si sceglie in **Dati dell'ente** (vedi [Impostare il mese di inizio dell'anno fiscale](guida:inizio-dell-anno-fiscale)).

## Aprire l'anno fiscale nuovo

1. Vai in **Cassa → Anni fiscali** (con {piano_contabile} o {piano_fiscale}: **Contabilità → Anni fiscali**).
2. In fondo alla pagina scrivi l'anno e premi **Crea l'anno**: serve se devi registrare incassi o spese in anticipo. Le quote dei soci non dipendono da qui.

Da settembre la **Bacheca** ti ricorda di aprire l'anno fiscale nuovo. L'avviso si può chiudere e ricompare a inizio mese; a dicembre è sempre visibile. Il primo giorno dell'anno il nuovo anno fiscale si apre da solo.

## Chiudere un anno fiscale

Prima di chiudere:

- gli anni fiscali si chiudono **in ordine, dal più vecchio**, e l'anno in corso non si chiude finché non è finito;
- con Pro, non devono restare fondi non rimborsati.

Poi, nella stessa pagina, premi **Chiudi l'anno** accanto all'anno fiscale.

Si può incassare, spendere e fare giroconti solo negli anni fiscali **aperti**. Un anno chiuso non si modifica più, nemmeno negli allegati, nei saldi iniziali e nelle cessazioni dei soci: se ci provi, un messaggio ti rimanda alla pagina «Anni fiscali», dove si può riaprire solo l'ultimo anno chiuso. In un anno chiuso si bloccano anche:

- **assemblee** (anche non svolte), **votazioni**, **sondaggi** e **verbali**;
- la **relazione del rendiconto**;
- la **cessione del posto** e l'eliminazione di eventi e iscrizioni con incassi in quell'anno;
- l'accantonamento e il rilascio dei **fondi**;
- il **nome e il tipo di un conto** con movimenti in quell'anno (commissione, chiusura e riapertura del conto si possono cambiare).

## Anno passato ancora aperto e in disavanzo ({piano_fiscale})

Se un anno fiscale passato è ancora aperto e le uscite superano le entrate, il programma te lo segnala in **Bacheca** e in **Cassa** con un avviso fisso: **«Anno fiscale 2025 in disavanzo.»** (con l'anno giusto). L'avviso sparisce quando il risultato torna a zero o più, o quando chiudi l'anno. Se mancano delle entrate, registrale in **Prima nota** con una data dentro quell'anno. Se invece i conti sono giusti, chiudi l'anno confermando con la spunta **«chiudo anche se l'anno è in disavanzo di …»** (con l'importo). L'avviso non blocca nulla.

## Il libro soci dell'anno fiscale

Alla chiusura il libro soci di quell'anno fiscale diventa definitivo: viene pubblicato e bloccato a quel momento e non cambia più. Prima della chiusura è una bozza: i numeri sono quelli di oggi e possono cambiare. Si scarica in PDF e in CSV (vedi [Il libro soci completo](guida:libro-soci-completo)). Per gli anni chiusi che non hanno ancora il libro soci definitivo compare **«Emetti i documenti definitivi degli anni già chiusi»**: ogni documento porta la dicitura «Emesso il gg/mm/aaaa per un anno già chiuso: i dati sono quelli di quel giorno, non quelli della chiusura.» L'emissione non si può annullare.

## Riaprire un anno fiscale

Si riapre **solo l'ultimo anno fiscale chiuso**, dalla stessa pagina con **Riapri l'anno**, scrivendo il **motivo**. Gli anni fiscali più vecchi non si riaprono. Dopo la correzione si richiude l'anno fiscale. Lo **storico delle chiusure e delle riaperture** resta **per sempre**: non si cancella nemmeno con la pulizia del registro azioni.

## La quota associativa

La quota e la tessera seguono l'anno fiscale (vedi [Come dura la tessera](guida:iscrivere-un-socio-e-rinnovare-la-tessera)).

## Con Pro

Con {piano_contabile} e {piano_fiscale} la stessa pagina è raggiungibile anche da **Contabilità** e mostra la tabella dei **fondi da rimborsare per anno**. Report e rendiconto per cassa restano funzioni Pro (vedi [Rendiconto per cassa e report dell'anno](guida:rendiconto-per-cassa-e-report)).
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'importare-ed-esportare-la-prima-nota',
        'title' => 'Importare ed esportare la prima nota',
        'body' => <<<'MD'
> Disponibile in: Free, {piano_contabile}, {piano_fiscale}

## Esportare

1. Vai in **Strumenti → Esporta**.
2. Scegli cosa esportare: soci e ospiti, prima nota (anno fiscale) o report delle attività (anno delle attività).
3. Scarica il file CSV. Il report delle attività si chiama «anno-attivita-» seguito dall'anno e ha, per ogni anno fiscale attraversato, le righe «ENTRATE GENERALI (non di attività) - …», «USCITE GENERALI (non di attività) - …» e «Totale generali …».

I file usano il punto e virgola come separatore e la virgola come decimale, pronti per Excel. Con la partita IVA l'esportazione della prima nota aggiunge aliquota, imponibile e IVA, utili al commercialista.

## Importare movimenti già registrati

1. Vai in **Strumenti → Importa da Excel/CSV** e carica il file.
2. Colonne necessarie: **Data** e **Importo** (oppure *Entrata* e *Uscita*). Facoltative: *Tipo, Conto, Modalità, Voce, Descrizione, Riferimento, N. tessera, Persona, Attività, Competenza*.
3. Controlla l'anteprima e conferma.

## Regole dell'importazione

- Date nei formati `15/01/2024` o `2024-01-15`; importi come `1.234,56` o `1234.56`.
- I conti che non esistono si creano. Le voci non riconosciute usano «Altra entrata» o «Costo generale» e il nome originale resta nella descrizione.
- **Doppioni**: un movimento uguale a uno già presente viene saltato, quindi puoi ricaricare lo stesso file senza duplicare.
- Per gli anni passati, l'opzione **Non cambiare i saldi attuali** (attiva di partenza) aggiusta il saldo iniziale dei conti esistenti: il saldo di oggi resta com'è e la storia si completa.
- I movimenti importati si annullano uno per uno dalla Prima nota.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'donazioni-con-paypal',
        'title' => 'Ricevere donazioni dal sito',
        'body' => <<<'MD'
> Disponibile in: Free (con il pulsante PayPal), {piano_contabile} e {piano_fiscale} (anche con il pagamento del sito)

Le donazioni si raccolgono in due modi, a seconda che tu abbia o no un metodo di pagamento attivo.

- **Con un metodo di pagamento attivo** (Stripe, PayPal, SumUp o Satispay, in **Pagamenti online**; non il checkout di WooCommerce): il modulo è aperto a tutti, anche a chi non è socio, e la donazione si registra da sola.
- **Senza un metodo attivo**: il modulo mostra il pulsante «Dona con PayPal» e le donazioni vanno registrate a mano.

## Attivarle

1. Apri la pagina **Donazioni** del plugin e accendi la raccolta con **Accendi le donazioni**. All'inizio è spenta: finché resta spenta, sul sito non compare nessun modulo.
2. Indica gli **Importi proposti** (in euro, separati da punto e virgola, al massimo 8) e la **Causale generale**, il titolo del modulo e il motivo che compare sul pagamento.
3. Inserisci il modulo in una pagina con lo shortcode che trovi in **Shortcode della raccolta generale** (o con il blocco **Associazione Semplice**).
4. Il campo **Conto PayPal** serve solo se non hai un metodo di pagamento attivo: scrivi l'email del conto PayPal dell'associazione oppure il suo ID commerciante (13 caratteri, nel profilo PayPal). Serve un conto Business abilitato a ricevere donazioni.

Se manca sia un metodo attivo sia il conto PayPal, la raccolta resta «In attesa: manca un metodo di pagamento attivo o il conto PayPal» e il modulo non compare ai visitatori.

## Con il pagamento del sito

Chi dona sceglie l'importo tra quelli proposti oppure scrive un altro importo, da 1 € a 1.000 €. Scrive nome, cognome ed email; il codice fiscale e l'indirizzo sono facoltativi e servono solo se vuole che compaiano nella ricevuta. Poi sceglie come pagare e paga sul sito del gestore.

A pagamento confermato, in automatico:

- la donazione va in **Prima nota** come «Erogazione liberale», con la sua ricevuta; la commissione non si ribalta sul donatore;
- chi ha donato riceve un'email «Grazie per la tua donazione» con la ricevuta in allegato;
- se l'email è già nel gestionale (socio o contatto), la donazione si lega alla sua scheda e la scheda non cambia. Altrimenti nasce un contatto «Sostenitore (non socio)» con i dati del modulo: serve solo per la ricevuta, e resta fuori dal libro soci e dal RUNTS.

Il modulo si difende da solo da spam e abusi, senza servizi esterni: c'è un limite ai tentativi per connessione e per email, e se si superano compare per esempio «Troppi tentativi: riprova tra un'ora.»

Il titolo «Ricevuta di erogazione liberale» compare in automatico sulle ricevute che contengono solo donazioni.

## Con il pulsante PayPal «Dona»

Il pulsante porta chi dona sulla pagina di donazione di PayPal: il tuo sito non comunica con PayPal e la pagina si apre nel browser di chi dona. I soldi vanno direttamente sul tuo account PayPal. Il plugin non le vede e non le registra da solo: la pagina **Donazioni** lo ricorda. Registrale a mano in Prima nota da **Nuovo incasso**, con la categoria «Erogazione liberale». Se vuoi, per ogni versamento puoi emettere una ricevuta. Per farle registrare in automatico attiva un metodo di pagamento in **Pagamenti online**.

L'account PayPal lo cambiano solo gli amministratori. Ogni modifica, anche con il ripristino di una copia, resta nel registro azioni (con l'account mascherato) e viene segnalata per email a tutti gli amministratori. Se non sei stato tu, cambia subito le password degli amministratori e controlla le impostazioni delle donazioni.

## Invitare a donare

Nella pagina **Donazioni**, la sezione **Invito a donare** aggiunge una riga con il link alla tua pagina delle donazioni:

- sulla pagina dell'evento o del corso dice «Non puoi partecipare? Sostienici con una donazione»;
- nella conferma dopo l'iscrizione, nell'area soci e nelle email dice «Vuoi sostenere» e il nome dell'associazione, poi «Fai una donazione».

All'inizio è spenta ovunque. In **Dove mostrarlo** scegli tu dove farla comparire:

- sulla pagina dell'evento o del corso (utile soprattutto quando è pieno o già passato);
- nella conferma dopo l'iscrizione o la prenotazione;
- nell'area soci;
- in fondo alle email ai soci.

In **Pagina delle donazioni** scrivi l'indirizzo della pagina dove hai messo il modulo. Se lo lasci vuoto il plugin usa la prima pagina del sito che contiene lo shortcode; se non c'è nessuna pagina, l'invito non compare. Se hai acceso **Donazioni detraibili** (in **Dati dell'ente**, solo Pro) accanto all'invito compare «È anche detraibile.».
MD,

    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'attivare-stripe-o-paypal',
        'title' => 'Attivare i pagamenti online con Stripe o PayPal',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

I soci pagano quote, mensilità e contributi direttamente dall'area soci. I dati della carta **non passano mai dal tuo sito**: il socio paga su una pagina ospitata da Stripe o da PayPal.

## Prima di iniziare

Ti serve un account **Stripe** o **PayPal Business** dell'associazione. Le chiavi si copiano dal pannello del fornitore.

Se in **Impostazioni → Cassa → Pagamenti online** compare un avviso sulle **chiavi di sicurezza di WordPress** (`AUTH_KEY` e `AUTH_SALT`) mancanti o di esempio, fai sostituire quei valori nel file `wp-config.php` dal tuo fornitore di hosting o da chi segue il sito.

## Cosa fare

1. Vai in **Impostazioni → Cassa → Pagamenti online**.
2. Scegli il fornitore di pagamento: Stripe, PayPal, SumUp (anche insieme), WooCommerce (in alternativa) oppure solo bonifico. Per SumUp vedi [Attivare i pagamenti online con SumUp](guida:attivare-sumup).
3. Incolla le chiavi. Vengono salvate **cifrate** e legate al sito: se lo copi su un altro dominio vanno reinserite.
4. Per Stripe, crea nel pannello Stripe (**Sviluppatori → Webhook**) un **webhook** verso l'indirizzo mostrato nella pagina del plugin e aggiungi questi **cinque eventi**: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `charge.refunded` e `charge.dispute.created`. **Se il webhook c'è già**, aggiungi solo gli ultimi due (`charge.refunded` e `charge.dispute.created`): servono per gli avvisi sui rimborsi e sulle contestazioni fatti dal pannello di Stripe.
5. Salva e fai un **pagamento di prova** con un importo minimo.

Se l'associazione è senza scopo di lucro, Stripe e PayPal possono avere condizioni particolari: nella pagina trovi i link «Stripe per i non profit» e «PayPal: tariffa per gli enti benefici». Le condizioni le decide il gestore e possono cambiare: chiedi sempre a lui.

## Con la configurazione guidata

Nella **Configurazione guidata** i passi **Collega Stripe** e **Collega PayPal** fanno il collegamento al posto tuo. Puoi anche lasciare i campi vuoti e compilarli dopo in **Pagamenti online**.

- **Stripe**: apri «Apri la pagina delle chiavi di Stripe» e copia la chiave pubblicabile (inizia con `pk_`); crea la chiave segreta (`sk_`, oppure `rk_` se vuoi una chiave con restrizioni). Una chiave limitata deve poter scrivere su «Checkout Sessions», «Refunds» e «Webhook Endpoints». Scegli la modalità, **Prova (test)** o **Reale (live)**: comincia dalla prova.
- **PayPal**: apri «Apri la pagina delle credenziali di PayPal», crea un'app e copia il «Client ID» e il «Client Secret». Per la prova apri la scheda «Sandbox» della stessa pagina e scegli la modalità **Prova (sandbox)**: le credenziali di prova e quelle reali sono diverse.
- **Prova della connessione**: appena incolli le chiavi, il plugin controlla che funzionino. Se la prova non riesce, le chiavi restano salvate ma il metodo non viene attivato: il messaggio dice di correggerle in **Pagamenti online**.
- **Conferma dei pagamenti**: con Stripe la casella «Crea da solo il collegamento con Stripe (webhook)» è già spuntata. Il plugin crea il webhook con gli eventi giusti e salva il suo segreto. Se non ci riesce lo dice, con il motivo, e puoi crearlo a mano come al passo 4. Il webhook si crea da solo solo se il sito è raggiungibile da internet con https; se in Stripe esiste già, Stripe non mostra più il segreto: copialo dalla pagina dei webhook di Stripe e incollalo in **Pagamenti online**.

## Importare le chiavi da WooCommerce

Se sul sito ci sono già i plugin di pagamento di WooCommerce, in **Pagamenti online** compare la scheda **Importa da WooCommerce**. Elenca i metodi trovati, dove sono e in che modalità; spunta quelli che vuoi e premi **Importa le chiavi spuntate**. Le chiavi restano anche in WooCommerce; qui si salvano cifrate e sostituiscono quelle già salvate per lo stesso metodo (Stripe, PayPal o SumUp). Il metodo scelto in «Come incassare online» non cambia finché non lo cambi tu.

Dopo l'importazione premi **Verifica connessione**, chiudi gli ordini del negozio ancora in attesa, scegli il metodo in «Come incassare online» e salva. Gli incassi già registrati in prima nota restano come sono. Per Stripe crea un webhook nuovo con l'indirizzo che trovi nella pagina: quello di WooCommerce non vale per questo plugin.

Alcune chiavi non si importano:

- Stripe collegato a WooCommerce con il pulsante «Connetti»: la chiave salvata vale per un'ora sola. Crea una chiave tua in Stripe (Sviluppatori → Chiavi API).
- WooPayments non ha chiavi da importare: apri un conto Stripe dell'associazione e inserisci le sue chiavi.
- PayPal Standard, il vecchio metodo di WooCommerce, non ha le credenziali che servono: crea Client ID e Client Secret su developer.paypal.com.

## Proporre una donazione al pagamento

In **Pagamenti online** la riga **Donazione al pagamento** è spenta di partenza. Accendila con «Proponi ai soci di aggiungere una donazione quando pagano»: sotto l'elenco di ciò che c'è da pagare compare la spunta «Aggiungi … € per una donazione a …».

- **Importo proposto**: chi paga può cambiarlo, da 1 € a 1.000 €. Se è fuori da questi limiti compare «Scrivi un importo per la donazione tra … e …, oppure togli la spunta.»
- La donazione è una voce di pagamento a sé: va in prima nota come «Erogazione liberale» e ha una ricevuta sua. Sulla donazione non si ribalta la commissione.
- Funziona con Stripe, PayPal, SumUp e Satispay, non con il checkout di WooCommerce. Su PayPal la voce è indicata come donazione.
- **Donazioni detraibili**, in **Dati dell'ente**: se la accendi, accanto alla richiesta compare «È anche detraibile.». Verifica con il tuo commercialista se la tua associazione ne ha diritto: spenta, la frase non compare mai, perché il plugin non può sapere se le donazioni al tuo ente danno un beneficio fiscale.

## Eventi e corsi dei soci come erogazione liberale

In **Dati dell'ente**, nella sezione **Erogazioni liberali**, c'è la casella «Incassa come erogazione liberale gli eventi e i corsi pagati dai soci». È spenta di partenza: gli eventi e i corsi pagati dai soci sono contributi, come sempre.

Accesa, da quel momento la ricevuta scrive «Erogazione liberale per l'evento…» o «…per il corso…», il rendiconto li conta tra le erogazioni liberali, con PayPal la voce è indicata come donazione e, se l'ente applica l'IVA, restano fuori campo IVA. Non cambia nulla per i pagamenti dei non soci, che restano contributi, e per le quote associative, che restano sempre escluse. I movimenti già registrati non cambiano. Prima di accenderla chiedi al tuo commercialista: questa impostazione non garantisce alcun vantaggio fiscale a chi paga.

## Come funziona

1. Nell'area soci, sezione **Pagamenti**
, il socio vede cosa deve (quota, mensilità, contributi, anche per i suoi ospiti).
2. Il sito crea il pagamento e porta il socio alla pagina del fornitore.
3. La conferma torna al sito per tre strade (ritorno del socio, webhook e controllo orario), tutte senza rischio di doppio conteggio.
4. L'incasso entra in prima nota sul conto **Stripe** o **PayPal** (creato in automatico) e il socio riceve la ricevuta via email.

> **Rimborsi e contestazioni su Stripe.** Con i due eventi `charge.refunded` e `charge.dispute.created` nel webhook, un rimborso o una contestazione fatti dal **pannello di Stripe** mettono il pagamento **«da controllare»** e compare in Bacheca l'avviso **«Pagamenti online da controllare»**. Il plugin **non rimborsa e non annulla nulla da solo**, e la prima nota **non cambia**: controlla dal pannello di Stripe e, se serve, **annulla** il movimento in prima nota. Per **PayPal** questi avvisi non arrivano.
>
> Commissioni, trasferimenti sul conto corrente e rimborsi si registrano a mano (una spesa e un giroconto). I rimborsi si fanno dal pannello del fornitore.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'attivare-sumup',
        'title' => 'Attivare i pagamenti online con SumUp',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale} (aggiorna insieme gratuito e Pro)

SumUp è un altro metodo di pagamento online. Il socio paga su una **pagina sicura di SumUp**: i dati della carta non passano mai dal tuo sito. Si può usare da solo o **insieme a Stripe e PayPal**.

## Cosa ti serve

Un account **SumUp** dell'associazione, con il **codice commerciante** e la **chiave API**, che trovi nel pannello SumUp.

## Cosa fare

1. Vai in **Impostazioni → Cassa → Pagamenti online**.
2. Attiva **SumUp** e inserisci il **codice commerciante** e la **chiave API**. La chiave viene salvata cifrata.
3. Premi **Verifica connessione**: il plugin controlla che chiave e codice siano validi.
4. Salva.

## Come funziona

1. Il socio sceglie SumUp dall'area soci e paga sulla pagina di SumUp.
2. **Non serve nessun webhook**: la conferma arriva quando il socio torna sul sito e il plugin la ricontrolla anche **ogni ora**.
3. L'incasso entra in prima nota sul conto **«SumUp»**, creato in automatico.

## Per le prove

SumUp **non ha una modalità di prova** sul sito: per provare serve un **account di prova di SumUp**. Fai un pagamento di importo minimo per verificare tutto.

> Commissioni e trasferimenti sul conto corrente si registrano come per gli altri metodi. Per la gestione delle commissioni vedi [Commissioni di incasso sui pagamenti online](guida:commissioni-di-incasso).
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'commissioni-di-incasso',
        'title' => 'Commissioni di incasso sui pagamenti online',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

Ogni fornitore di pagamento trattiene una commissione su ciascun pagamento. Il plugin ti aiuta a tenerne conto in prima nota e, se vuoi, a ribaltarla su chi paga.

## Indicare la commissione

1. Vai in **Impostazioni → Cassa → Pagamenti online**.
2. Per ogni metodo (**Stripe**, **PayPal**, **SumUp** e **Satispay**) indica la commissione: una **cifra fissa**, una **percentuale**, oppure **entrambe**.

I campi partono già compilati con le **tariffe standard**:

| Metodo | Commissione di partenza |
|---|---|
| Stripe | 1,5% + 0,25 € |
| PayPal | 3,40% + 0,35 € |
| SumUp | 2,5% |
| Satispay | 0,20 € fissi, 0% |

Sono valori di partenza: **modificali** se il tuo contratto ha condizioni diverse, oppure **svuota** i campi se non vuoi stimare la commissione. Le tariffe applicate dal fornitore di pagamento possono cambiare: controlla sempre il tuo contratto.

## Registrarla come spesa

Con l'opzione **«Registra come spesa la commissione stimata»**, a ogni pagamento confermato entra in prima nota una spesa chiamata **«Commissione di incasso (stima)»**. È una **stima**: confrontala con l'estratto conto del fornitore di pagamento e correggila quando serve.

## Ribaltarla su chi paga

L'opzione **«Ribalta la commissione su chi paga»** è **spenta di partenza**. Se la accendi, il totale da pagare aumenta e il socio vede la commissione sotto il pulsante di pagamento.

> **Attenzione**: in Italia e nell'Unione europea è **vietato** applicare un supplemento ai consumatori per i pagamenti con carta (direttiva PSD2, D.Lgs. 11/2010). La responsabilità di accendere questa opzione è dell'associazione: valuta bene prima di attivarla.

## Dove non vale

Le commissioni non si applicano alle **domande di adesione online** e ai pagamenti tramite **WooCommerce**.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'controllare-un-pagamento-non-abbinato',
        'title' => 'Sistemare un pagamento online «da controllare» o non abbinato',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

A volte un pagamento online non coincide con quello che il sistema si aspettava, per esempio perché l'importo è diverso o la prenotazione è stata annullata nel frattempo. In questi casi i soldi entrano comunque, come **«pagamento online non abbinato»**, e il pagamento è segnato **da controllare**.

## Cosa fare

1. Vai in **Impostazioni → Cassa → Pagamenti online**.
2. Trova i pagamenti con lo stato **da controllare**.
3. Confronta importo e riferimento con quello che trovi nel pannello del fornitore.
4. Se la persona ha pagato più o meno del dovuto, correggi a mano: annulla il movimento e registra l'incasso corretto, oppure registra la differenza.
5. Se il pagamento è «da controllare» perché **rimborsato o contestato su Stripe**, il plugin non ha cambiato nulla: guarda nel pannello di Stripe com'è andata e, se serve, **annulla** il movimento in prima nota. L'avviso arriva solo per Stripe e solo con i due eventi `charge.refunded` e `charge.dispute.created` nel webhook.
6. Se il pagamento era per una prenotazione annullata, decidi se **rimborsare**: con Stripe o PayPal si fa dal programma (vedi [Restituire un pagamento online al socio](guida:restituire-un-pagamento-online-al-socio)), con gli altri metodi dal pannello del fornitore.

## Se un pagamento non compare

Premi il pulsante di controllo nella pagina **Pagamenti online**: il plugin interroga il fornitore. Il controllo gira comunque ogni ora.

## Con WooCommerce

Con WooCommerce l'incasso entra in prima nota una sola volta:

- con **carta e PayPal**, quando l'ordine passa a «in lavorazione» o «completato»;
- con **bonifico, assegno o contrassegno**, solo quando metti l'ordine su **«Completato»**: prima controlla di aver ricevuto i soldi.

Se l'importo è diverso, entra come «non abbinato». Un **secondo ordine** per lo stesso pagamento non si incassa due volte: finisce in **«da controllare»**. Gli ordini senza voci di Associazione Semplice non vengono toccati.

Se leggi «Incasso registrato, ma un passo successivo non è riuscito (…): controlla il pagamento.», l'incasso è già in prima nota: controlla soltanto il resto.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'ricevute-in-pdf',
        'title' => 'Ricevute in PDF e attestazione annuale',
        'body' => <<<'MD'
> Disponibile in: {piano_contabile}, {piano_fiscale}

Ogni incasso può avere una **ricevuta in PDF**, numerata e con il nome dell'associazione.

## Emettere e inviare una ricevuta

1. Vai in **Cassa → Prima nota** e trova l'incasso.
2. Premi **Ricevuta PDF** per vederla o scaricarla.
3. Premi **Invia per email** per mandarla a chi ha pagato.

## Cosa contiene

- Un numero progressivo **N/AAAA**, per anno fiscale, assegnato alla prima emissione e poi fisso: una ricevuta emessa non cambia più.
- La firma «Per l'associazione» con il nome del presidente, se assegnato, e una riga finale a tua scelta (per esempio un riferimento normativo).
- Il titolo «Ricevuta di erogazione liberale» se sono solo donazioni.
- Con la partita IVA, imponibile e IVA contenuta per ogni aliquota.

Un incasso annullato non ha ricevuta.

## La ricevuta non cambia più

Il **PDF di una ricevuta si crea una volta sola**, quando viene emessa, e si salva nella **cartella riservata** del sito. Quando lo scarichi o lo spedisci è sempre **lo stesso file**: il programma controlla che sia quello emesso. I **dati** della ricevuta (nome, codice fiscale, importi, dati dell'ente, piè di pagina, righe) sono **sempre quelli salvati all'emissione**, anche se poi cambiano i dati del socio o dell'associazione.

Le ricevute emesse con le versioni precedenti del plugin sono state fissate in automatico: il loro PDF ha i dati dell'emissione ma i testi di oggi (i tuoi testi personalizzati, le parole scelte per il tipo di ente, la grafica).

Le ricevute più vecchie, emesse quando il sito non salvava ancora i dati, non hanno un PDF fisso. Dalla pagina **«Verifica delle ricevute»**, sotto **«Emesse prima che si salvassero i dati»**, l'amministratore le fissa a gruppi di 100 con **«Fissa anche queste con i dati di oggi»**: il PDF usa i dati di oggi, non quelli dell'emissione, e da quel momento non cambia più. Conviene farlo prima di una copia di sicurezza. Le ricevute annullate restano come sono.

## La pagina «Verifica delle ricevute»

È solo per gli **amministratori**. La apri dai riquadri **«PDF definitivi delle ricevute»** (pagina **Anni fiscali**) e **«Ricevute: PDF definitivi»** (pagina **Copia di sicurezza**). Scegli l'anno fiscale: vedi quante ricevute sono «A posto», «PDF ancora da fissare», «Emesse prima che si salvassero i dati», «File mancanti» e «File diversi da quando sono stati emessi».

- **«Fissa i prossimi PDF»** fissa le ricevute ancora da fissare a gruppi di 100: se ne restano, premilo ancora.
- **«Rifai dai dati salvati»** (per un file mancante o diverso) rifà il PDF dai dati salvati all'emissione, dopo una conferma. Il file diverso resta messo da parte e l'operazione resta nel registro azioni.
- Il file si può anche **ripristinare da una copia di sicurezza**, che contiene sempre i PDF delle ricevute.
- **«Scarica i PDF di questo anno fiscale (ZIP)»** li scarica tutti insieme.

Finché un PDF non coincide con quello emesso, **scaricare quella ricevuta dà un errore**: non viene mai rifatta di nascosto. Il **socio** che prova a scaricarla dall'area soci legge: «Al momento questa ricevuta non è disponibile. Scrivi alla segreteria: {email}, tel. {telefono}.» con i contatti che hai inserito nei Dati dell'ente.

## Se annulli un incasso

Se annulli un incasso, anche la ricevuta già emessa viene annullata, ma il suo **numero non si libera**. La ricevuta annullata non si vede più nell'area soci (il socio la chiede alla segreteria); alla segreteria resta, con il PDF **«RICEVUTA ANNULLATA»**.

Se serve una nuova ricevuta, dopo l'annullo premi **«Emetti la ricevuta sostitutiva»**: prende un **numero nuovo** e la **data di oggi**, e vale solo per le voci rimaste. Non si può emettere se l'incasso o la sua data cadono in un anno fiscale chiuso.

## Ricevute dei minori

La ricevuta di un minore è **intestata al genitore o tutore che opera**, o a quello che ha pagato. Riporta i **codici fiscali** di tutti i genitori e del ragazzo e, per i **5-18 anni**, l'indicazione di **pagamento tracciato o non tracciato**: serve per la detrazione delle spese sportive.

Sono **tracciabili** bonifico, POS, assegno, Stripe, PayPal, SumUp e WooCommerce. **Non** sono tracciabili i **contanti** e il metodo «altro».

## Attestazione annuale

Dalla scheda persona (o dall'area soci) si può ottenere l'**attestazione annuale** dei versamenti: quote, contributi ed erogazioni liberali, con i totali.

## Area soci

Con lo shortcode `[associazionesemplice_ricevute]` (già incluso nell'area soci) il socio vede l'elenco e scarica le ricevute. Le vede solo chi ha pagato, oltre agli amministratori. La ricevuta o il rendiconto di un **ospite** lo scaricano solo l'ospite, il suo tutore e la segreteria, **non** chi lo ospita; le ricevute dei pagamenti fatti dall'ospitante restano sue.

## Impostazioni

La riga finale e la numerazione si impostano in **Impostazioni → Ente → Privacy e regolamento** (con {piano_contabile} o {piano_fiscale}: **Ente e fiscalità → Privacy, regolamento e ricevute**).
MD,
    ],
];
