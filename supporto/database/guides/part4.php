<?php

// Guide: cassa, pagamenti online e ricevute.
return [
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'registrare-un-incasso',
        'title' => 'Registrare un incasso',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (cassa per più persone e IVA: Pro)

Ogni versamento (quota, mensilità, contributo di un evento, donazione) entra nella **prima nota**. Importi e voci vengono sempre ricalcolati dal sistema.

## Cosa fare

1. Vai in **Cassa → Nuovo incasso**.
2. Scegli la persona. Se ha la tessera non valida, la quota associativa si inserisce da sola.
3. Controlla le voci proposte: mensilità e contributi già dovuti compaiono precaricati. Puoi aggiungere o togliere righe.
4. Scegli il conto e la modalità di pagamento.
5. Se incassi in **contanti**, scrivi quanto hai ricevuto: la calcolatrice dice il **resto**, con i tagli.
6. Salva.

## Scorciatoie

- Dalla **Bacheca**, il pulsante **Incassa** apre l'incasso già compilato con le mensilità dovute e i contributi non ancora pagati.
- Se il tuo tesoriere lavora da telefono, può incassare dalla pagina **Tesoriere** dell'area riservata.

## Se hai sbagliato

I movimenti della prima nota **non si modificano**: si **annullano** (resta traccia dell'annullamento) e si registra quello giusto. Un incasso annullato sparisce da saldi, report e ricevute.

> Un incasso può avere una ricevuta in PDF (vedi [Ricevute in PDF](guida:ricevute-in-pdf), funzione Pro).
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'registrare-una-spesa-con-allegato',
        'title' => 'Registrare una spesa con lo scontrino allegato',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

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

Non vanno nella libreria media del sito: stanno in una cartella privata e si aprono solo da un indirizzo del plugin, con controllo dei permessi. **Togli** non cancella il file: l'allegato esce dall'elenco e l'operazione resta nel registro azioni.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'aprire-e-chiudere-gli-anni-solari',
        'title' => 'Aprire e chiudere gli anni solari',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale (dalla versione 1.2.19 del plugin)

La tessera dura l'anno solare e la prima nota si organizza per anno solare: per questo l'apertura e la chiusura dell'anno sono disponibili in tutte le versioni. Solo gli amministratori possono aprire, chiudere e riaprire un anno.

## Aprire l'anno nuovo

1. Vai in **Cassa → Anni solari**.
2. **Apri** l'anno nuovo (per esempio a novembre, per l'anno dopo).

Da settembre la **Bacheca** ti ricorda di aprire il nuovo anno. L'avviso si può chiudere e ricompare a inizio mese; a dicembre è sempre visibile. L'anno in corso esiste sempre.

## Chiudere un anno

1. Nella stessa pagina scegli l'anno e premi **Chiudi**.
2. Per chiudere un anno non devono restare fondi non rimborsati (funzione Pro).

Si può incassare, spendere e girare denaro solo negli anni **aperti**. Un anno chiuso rifiuta nuovi movimenti e non permette di annullare i suoi. Se hai bisogno di correggere qualcosa, **riapri** l'anno dalla stessa pagina.

## La quota associativa

La quota va all'anno solare più recente creato. Un socio che ha già l'anno in corso rinnova in anticipo; un nuovo socio paga il più recente e ha l'anno in corso in omaggio.

## Con Pro

Con Pro Contabile e Pro Fiscale la stessa pagina è raggiungibile anche da **Contabilità** e mostra la tabella dei **fondi da rimborsare per anno**. Report e rendiconto per cassa restano funzioni Pro (vedi [Rendiconto per cassa e report dell'anno](guida:rendiconto-per-cassa-e-report)).
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'importare-ed-esportare-la-prima-nota',
        'title' => 'Importare ed esportare la prima nota',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

## Esportare

1. Vai in **Strumenti → Esporta**.
2. Scegli cosa esportare: soci e ospiti, prima nota (anno solare) o report delle attività (anno sociale).
3. Scarica il file CSV.

I file usano il punto e virgola come separatore e la virgola come decimale, pronti per Excel. Con la partita IVA l'esportazione della prima nota aggiunge aliquota, imponibile e IVA, utili al commercialista.

## Importare movimenti già registrati

1. Vai in **Soci → Importa da Excel/CSV** e carica il file.
2. Colonne necessarie: **Data** e **Importo** (oppure *Entrata* e *Uscita*). Facoltative: *Tipo, Conto, Modalità, Voce, Descrizione, Riferimento, N. tessera, Persona, Attività, Competenza*.
3. Controlla l'anteprima e conferma.

## Regole dell'importazione

- Date nei formati `15/01/2024` o `2024-01-15`; importi come `1.234,56` o `1234.56`.
- I conti che non esistono si creano. Le voci non riconosciute usano «Altra entrata» o «Costo generale» e il nome originale resta nella descrizione.
- **Doppioni**: un movimento uguale a uno già presente viene saltato, quindi puoi ricaricare lo stesso file senza duplicare.
- Per gli anni passati, l'opzione **Non cambiare i saldi attuali** (attiva di default) aggiusta il saldo iniziale dei conti esistenti: il saldo di oggi resta com'è e la storia si completa.
- I movimenti importati si annullano uno per uno dalla Prima nota.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Incassi e spese', 'slug' => 'donazioni-con-paypal',
        'title' => 'Ricevere donazioni con PayPal',
        'body' => <<<'MD'
> Disponibile in: Free, Pro Contabile, Pro Fiscale

Il modulo donazioni porta chi dona sulla pagina di donazione di PayPal. Dal tuo server non parte nessuna richiesta verso PayPal: il pulsante si apre direttamente nel browser del visitatore.

## Attivarle

1. Vai nelle impostazioni delle **donazioni** del plugin e attivale.
2. Indica l'**account PayPal** a cui devono andare, lo scopo e gli importi proposti.
3. Inserisci il modulo in una pagina con lo shortcode dedicato (o il blocco **Associazione Semplice**).

## Dopo una donazione

Le donazioni vanno **direttamente sul tuo account PayPal**. Il plugin non le vede: le registri tu in prima nota, con un incasso. Se vuoi, per ogni versamento puoi emettere una ricevuta.

> Il titolo «Ricevuta di erogazione liberale» compare in automatico sulle ricevute che contengono solo donazioni (funzione Pro).
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'attivare-stripe-o-paypal',
        'title' => 'Attivare i pagamenti online con Stripe o PayPal',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

I soci pagano quote, mensilità e contributi direttamente dall'area riservata. I dati della carta **non passano mai dal tuo sito**: il socio paga su una pagina ospitata da Stripe o da PayPal.

## Prima di iniziare

Ti serve un account **Stripe** o **PayPal Business** dell'associazione. Le chiavi si copiano dal pannello del fornitore.

Se in **Impostazioni → Pagamenti online** compare un avviso sulle **chiavi di sicurezza di WordPress** (`AUTH_KEY` e `AUTH_SALT`) mancanti o di esempio, fai sostituire quei valori nel file `wp-config.php` dal tuo fornitore di hosting o da chi segue il sito (avviso dalla versione 1.2.37).

## Cosa fare

1. Vai in **Impostazioni → Tecniche → Pagamenti online**.
2. Scegli il gateway: Stripe, PayPal, SumUp (anche combinati), WooCommerce (in alternativa) oppure solo bonifico. Per SumUp vedi [Attivare i pagamenti online con SumUp](guida:attivare-sumup).
3. Incolla le chiavi. Vengono salvate **cifrate** e legate al sito: se lo copi su un altro dominio vanno reinserite.
4. Per Stripe, crea nel pannello Stripe un **webhook** verso l'indirizzo mostrato nella pagina del plugin.
5. Salva e fai un **pagamento di prova** con un importo minimo.

## Come funziona

1. Nell'area soci, sezione **Pagamenti**, il socio vede cosa deve (quota, mensilità, contributi, anche per i suoi ospiti).
2. Il sito crea il pagamento e porta il socio alla pagina del fornitore.
3. La conferma torna al sito per tre strade (ritorno del socio, webhook e controllo orario), tutte senza rischio di doppio conteggio.
4. L'incasso entra in prima nota sul conto **Stripe** o **PayPal** (creato in automatico) e il socio riceve la ricevuta via email.

> Commissioni, trasferimenti sul conto corrente e rimborsi si registrano a mano (una spesa e un giroconto). I rimborsi si fanno dal pannello del fornitore.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'attivare-sumup',
        'title' => 'Attivare i pagamenti online con SumUp',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale (dalla versione 1.2.26 del plugin gratuito e 1.1.23 di Pro: aggiornali insieme)

SumUp è un altro metodo di pagamento online. Il socio paga su una **pagina sicura di SumUp**: i dati della carta non passano mai dal tuo sito. Si può usare da solo o **insieme a Stripe e PayPal**.

## Cosa ti serve

Un account **SumUp** dell'associazione, con il **codice commerciante** e la **chiave API**, che trovi nel pannello SumUp.

## Cosa fare

1. Vai in **Impostazioni → Pagamenti online**.
2. Attiva **SumUp** e inserisci il **codice commerciante** e la **chiave API**. La chiave viene salvata cifrata.
3. Premi **Verifica connessione**: il plugin controlla che chiave e codice siano validi.
4. Salva.

## Come funziona

1. Il socio sceglie SumUp dall'area riservata e paga sulla pagina di SumUp.
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
> Disponibile in: Pro Contabile, Pro Fiscale (dalla versione 1.2.26 del plugin gratuito e 1.1.23 di Pro)

Ogni gateway trattiene una commissione su ciascun pagamento. Il plugin ti aiuta a tenerne conto in prima nota e, se vuoi, a ribaltarla su chi paga.

## Indicare la commissione

1. Vai in **Impostazioni → Pagamenti online**.
2. Per ogni metodo (**Stripe**, **PayPal**, **SumUp** e, da Pro 1.1.28, **Satispay**) indica la commissione: una **cifra fissa**, una **percentuale**, oppure **entrambe**.

Dalla versione 1.1.24 di Pro i campi partono già compilati con le **tariffe standard**:

| Metodo | Commissione di partenza |
|---|---|
| Stripe | 1,5% + 0,25 € |
| PayPal | 3,40% + 0,35 € |
| SumUp | 2,5% |
| Satispay (da Pro 1.1.28) | 0,20 € fissi, 0% |

Sono valori di partenza: **modificali** se il tuo contratto ha condizioni diverse, oppure **svuota** i campi se non vuoi stimare la commissione. Le tariffe applicate dal gateway possono cambiare: controlla sempre il tuo contratto.

## Registrarla come spesa

Con l'opzione **«Registra come spesa la commissione stimata»**, a ogni pagamento confermato entra in prima nota una spesa chiamata **«Commissione di incasso (stima)»**. È una **stima**: confrontala con l'estratto conto del gateway e correggila quando serve.

## Ribaltarla su chi paga

L'opzione **«Ribalta la commissione su chi paga»** è **spenta di partenza**. Se la accendi, il totale da pagare aumenta e il socio vede la commissione sotto il pulsante di pagamento.

> **Attenzione**: in Italia e nell'Unione europea è **vietato** applicare un supplemento ai consumatori per i pagamenti con carta (direttiva PSD2, D.Lgs. 11/2010). La responsabilità di accendere questa opzione è dell'associazione: valuta bene prima di attivarla.

## Dove non vale

Le commissioni non si applicano alle **domande di adesione online** e ai pagamenti tramite **WooCommerce**.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'controllare-un-pagamento-non-abbinato',
        'title' => 'Controllare un pagamento «da controllare» o non abbinato',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

A volte un pagamento online non coincide con quello che il sistema si aspettava, per esempio perché l'importo è diverso o la prenotazione è stata annullata nel frattempo. In questi casi i soldi entrano comunque, come **«pagamento online non abbinato»**, e il pagamento è segnato **da controllare**.

## Cosa fare

1. Apri **Pagamenti online** dal menu.
2. Trova i pagamenti con lo stato **da controllare**.
3. Confronta importo e riferimento con quello che trovi nel pannello del fornitore.
4. Se la persona ha pagato più o meno del dovuto, correggi a mano: annulla il movimento e registra l'incasso corretto, oppure registra la differenza.
5. Se il pagamento era per una prenotazione annullata, decidi se **rimborsare** (dal pannello del fornitore) e registra la spesa e l'eventuale restituzione.

## Se un pagamento non compare

Premi il pulsante di controllo nella pagina **Pagamenti online**: il plugin interroga il fornitore. Il controllo gira comunque ogni ora.

## Con WooCommerce

Se usi WooCommerce, quando l'ordine passa a «in lavorazione» o «completato» l'incasso entra in prima nota una sola volta; se l'importo è diverso entra come «non abbinato». Gli ordini senza voci di Associazione Semplice non vengono toccati.
MD,
    ],
    [
        'topic' => 'pagamenti', 'section' => 'Pagamenti online', 'slug' => 'ricevute-in-pdf',
        'title' => 'Ricevute in PDF e attestazione annuale',
        'body' => <<<'MD'
> Disponibile in: Pro Contabile, Pro Fiscale

Ogni incasso può avere una **ricevuta in PDF**, numerata e con il nome dell'associazione.

## Emettere e inviare una ricevuta

1. Vai in **Cassa → Prima nota** e trova l'incasso.
2. Premi **Ricevuta PDF** per vederla o scaricarla.
3. Premi **Invia per email** per mandarla a chi ha pagato.

## Cosa contiene

- Un numero progressivo **N/AAAA**, assegnato alla prima emissione e poi fisso.
- La firma «Per l'associazione» con il nome del presidente, se assegnato, e una riga finale a tua scelta (per esempio un riferimento normativo).
- Il titolo «Ricevuta di erogazione liberale» se sono solo donazioni.
- Con la partita IVA, imponibile e IVA contenuta per ogni aliquota.

Un incasso annullato non ha ricevuta.

## Attestazione annuale

Dalla scheda persona (o dall'area soci) si può ottenere l'**attestazione annuale** dei versamenti: quote, contributi ed erogazioni liberali, con i totali.

## Area soci

Con lo shortcode `[associazionesemplice_ricevute]` (già incluso nell'area soci) il socio vede l'elenco e scarica le ricevute. Le vedono solo chi ha pagato e gli amministratori. Dalla versione 1.2.37 del plugin gratuito la ricevuta o il rendiconto di un **ospite** lo scaricano solo l'ospite, il suo tutore e la segreteria, **non** chi lo ospita; le ricevute dei pagamenti fatti dall'ospitante restano sue.

## Impostazioni

La riga finale e la numerazione si impostano in **Impostazioni → Promemoria, privacy e ricevute**.
MD,
    ],
];
