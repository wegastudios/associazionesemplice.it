<?php

// 25 domande frequenti sulle funzioni di Free e Pro; la prima è la tabella di confronto.
return [
    [
        'topic' => 'domande-frequenti', 'section' => 'Free e Pro', 'slug' => 'free-pro-contabile-e-pro-fiscale-a-confronto',
        'title' => 'Free, {piano_contabile} e {piano_fiscale} a confronto: cosa include ogni versione?',
        'body' => file_get_contents(__DIR__.'/confronto.md'),
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Free e Pro', 'slug' => 'quali-funzioni-sono-gratuite',
        'title' => 'Quali funzioni sono incluse nel plugin gratuito?',
        'body' => <<<'MD'
Il plugin gratuito contiene tutto il nucleo per gestire una piccola associazione:

- **Soci**: libro soci con una quota associativa (e l'opzione socio fondatore), tessera digitale con QR, ospiti, importazione da Excel o CSV ed esportazione.
- **Attività**: corsi ed eventi con iscrizioni, posti limitati, lista d'attesa, calendario e registrazione degli ingressi.
- **Cassa e conti**: prima nota con una cassa unica, spese con allegati e donazioni tramite PayPal.
- **Registri e privacy**: verbali, informativa privacy, consensi, anonimizzazione e download dei dati personali.
- **Area riservata** per i soci, con avvisi, regolamento, tessera, eventi e corsi, e **domanda di adesione online** per chi vuole iscriversi (con minori e genitori o tutori).
- **Ruoli**: presidente, vicepresidente, tesoriere, consiglieri e segreteria, con permessi separati.
- **Copia di sicurezza** e azzeramento dei dati.

Funziona anche con **Elementor** e **WP All Import**. Per il confronto completo vedi [Free, {piano_contabile} e {piano_fiscale} a confronto](guida:free-pro-contabile-e-pro-fiscale-a-confronto).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Free e Pro', 'slug' => 'cosa-aggiunge-pro',
        'title' => 'Cosa aggiunge Associazione Semplice Pro?',
        'body' => <<<'MD'
Pro è un plugin a parte, che si aggiunge a quello gratuito e ne estende le funzioni:

- **Pagamenti online** con Stripe, PayPal, SumUp o WooCommerce.
- **Più livelli di socio** con quote diverse e sconto per il nucleo familiare.
- **Conti e fondi** multipli, giroconti e cassa per più persone.
- **Ricevute in PDF** e attestazioni annuali.
- **Comunicazioni a gruppi**, app installabile con notifiche e tessera nel wallet del telefono.
- **Incasso sul posto** agli eventi, assicurazioni dei volontari e presenze.
- **Personalizzazione** di testi, tipo di ente e lingue.
- Con **{piano_fiscale}** anche IVA, 5 per mille e rendiconto per cassa.

Leggi [Installare Associazione Semplice Pro](guida:installare-associazione-semplice-pro) per i passi di installazione.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Free e Pro', 'slug' => 'differenza-tra-contabile-e-fiscale',
        'title' => 'Che differenza c\'è tra {piano_contabile} e {piano_fiscale}?',
        'body' => <<<'MD'
**{piano_contabile}** comprende tutte le funzioni Pro tranne quelle fiscali: pagamenti online, livelli di socio, conti e fondi, ricevute, report, comunicazioni, app, wallet, incasso sul posto, assicurazioni, presenze e personalizzazione dei testi.

**{piano_fiscale}** aggiunge la parte fiscale:

- l'**IVA** su quote, attività, incassi e spese;
- il **5 per mille** con il registro dei contributi e il rendiconto sull'utilizzo;
- il **rendiconto per cassa** per il commercialista.

L'apertura e la chiusura degli anni solari sono incluse anche nel piano gratuito.

Se la tua associazione non ha partita IVA e un commercialista che richiede questi documenti, {piano_contabile} è di solito sufficiente. Puoi passare da un piano all'altro dall'area riservata, senza reinstallare nulla.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Free e Pro', 'slug' => 'serve-il-plugin-gratuito-con-pro',
        'title' => 'Devo tenere installato il plugin gratuito se uso Pro?',
        'body' => <<<'MD'
Sì. Associazione Semplice Pro è un'aggiunta e richiede che il plugin gratuito sia installato e attivo: i due condividono dati e funzioni.

Hanno numeri di versione diversi, ma vanno **aggiornati insieme**: con un plugin gratuito vecchio, il Pro nuovo si sospende e mostra un avviso. Aggiorna prima il gratuito, poi Pro; se il sito segnala che non sono compatibili, aggiorna entrambi all'ultima versione.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'serve-la-licenza-per-il-plugin-gratuito',
        'title' => 'Serve una licenza per usare il plugin gratuito?',
        'body' => <<<'MD'
No. Il plugin gratuito funziona sempre, anche senza chiave: nessuna funzione di base dipende dalla licenza.

La **chiave gratuita** è facoltativa e abilita i servizi collegati, come la prova di Pro e l'avviso degli aggiornamenti. Crea un account che dà accesso al supporto e alle demo dei piani Pro. Si richiede da [associazionesemplice.it/free](https://associazionesemplice.it/free); vedi [Richiedere la licenza gratuita](guida:richiedere-la-licenza-gratuita).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'cosa-succede-se-la-licenza-pro-scade',
        'title' => 'Cosa succede se la licenza Pro scade?',
        'body' => <<<'MD'
Il plugin torna alle **funzioni di base**. Le funzioni Pro spariscono da menu e schede e, a chi prova a usarle, un messaggio spiega che la licenza va regolarizzata. In cima alle pagine compare un avviso.

- **I dati non vengono toccati**: soci, prima nota, registri restano al loro posto.
- Le quote diverse dei soci non si applicano più (vale la quota unica).
- I soci, i volontari e le esportazioni di base non si bloccano mai.
- I pagamenti online già avviati si chiudono comunque, così gli incassi in arrivo si registrano.

Quando rinnovi, tutto torna come prima. Vedi [Inserire la chiave e controllarne lo stato](guida:inserire-e-controllare-la-chiave).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'una-licenza-su-piu-siti',
        'title' => 'Posso usare una licenza su più siti?',
        'body' => <<<'MD'
No: ogni licenza è legata a **un solo dominio**. I sottodomini contano come domini diversi (`miaassociazione.it` e `soci.miaassociazione.it` sono due domini).

Se sposti il sito puoi cambiare il dominio della licenza dall'area riservata: sono consentiti **3 cambi ogni 30 giorni**. Vedi [Cambiare il dominio della licenza](guida:cambiare-il-dominio-della-licenza). I siti locali o di prova (`localhost`, `*.local`, `*.test`, indirizzi IP) non richiedono licenza.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'quali-dati-escono-dal-sito',
        'title' => 'Quali dati invia il plugin a servizi esterni?',
        'body' => <<<'MD'
Il plugin funziona da solo. Contatta un servizio esterno solo in questi casi opzionali:

- **Verifica della licenza**: se inserisci una chiave, una volta al giorno invia al server delle licenze solo la chiave e il nome del dominio. Mai dati dei soci.
- **Avviso degli aggiornamenti**: con una chiave inserita, al massimo ogni 24 ore chiede al sito associazionesemplice.it i numeri delle ultime versioni, senza inviare chiave, dati dei soci o informazioni sul sito. Si può spegnere dalle impostazioni.
- **Donazioni PayPal**: il pulsante apre la pagina di donazione di PayPal nel browser del visitatore; dal tuo server non parte nulla.
- **Ricerca dei luoghi con Google**: spenta di default; se la attivi con la tua chiave, il testo scritto nel campo «Luogo» arriva a Google dal browser dell'amministratore.

Sui siti locali nessuna di queste verifiche parte.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'cosa-succede-ai-dati-se-elimino-il-plugin',
        'title' => 'Cosa succede ai dati se elimino il plugin?',
        'body' => <<<'MD'
Di default **restano nel database** del sito, anche se disattivi o elimini il plugin. Se lo reinstalli li ritrovi.

Se vuoi che vengano cancellati definitivamente quando elimini il plugin, devi scegliere questa opzione nelle impostazioni prima di eliminarlo. Se invece vuoi solo ripartire da zero con il plugin installato, usa l'[azzeramento dei dati](guida:azzerare-i-dati-e-ripartire). In entrambi i casi **scarica prima una copia di sicurezza** ([guida](guida:copia-di-sicurezza-e-ripristino)).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'prova-gratuita-di-pro',
        'title' => 'Come funziona la prova gratuita di Pro?',
        'body' => <<<'MD'
Se hai già la licenza gratuita, dall'**area riservata** di associazionesemplice.it puoi provare un piano Pro **per 30 giorni**, **una sola volta**.

La prova ti dà una chiave a tempo sullo stesso dominio: inseriscila in **Impostazioni → Licenza**. Alla fine dei 30 giorni, se non passi a un piano a pagamento, il plugin torna alle funzioni di base e i tuoi dati restano intatti. Vedi [Passare a Pro, provarlo e rinnovare](guida:passare-a-pro-e-rinnovare).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Licenza e dati', 'slug' => 'disdire-il-rinnovo',
        'title' => 'Come posso disdire o cambiare piano?',
        'body' => <<<'MD'
Dall'**area riservata** di associazionesemplice.it. I piani Pro si rinnovano da soli, ma puoi disdire il rinnovo, cambiare piano o aggiornare i dati di fatturazione in autonomia, senza vincoli.

Se disdici, alla scadenza il plugin torna alle funzioni di base senza toccare i dati. Se hai pagato con Stripe, dall'area riservata puoi aprire anche il portale di fatturazione del fornitore.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'importare-i-miei-soci',
        'title' => 'Posso importare l\'elenco dei miei soci?',
        'body' => <<<'MD'
Sì, da un file **Excel (`.xlsx`) o CSV**, anche con più fogli (soci, ospiti, prima nota). Prima di salvare vedi sempre un'anteprima con gli eventuali errori per riga. I soci già presenti vengono aggiornati, quindi importare due volte lo stesso file non crea doppioni.

È disponibile anche nel plugin gratuito. Vedi [Importare i soci da Excel o CSV](guida:importare-i-soci-da-excel-o-csv).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'quando-scade-la-tessera',
        'title' => 'Quando scade la tessera associativa?',
        'body' => <<<'MD'
La tessera dura l'anno solare e **scade sempre il 31 dicembre**, per tutti tranne i soci fondatori, la cui tessera è sempre rinnovata. Chi si iscrive a fine anno può pagare la tessera dell'anno dopo e avere gratis quella in corso.

Un socio con la tessera scaduta non può prenotare gli eventi dal sito, nemmeno quelli gratuiti, finché non rinnova. Dalla Bacheca vedi i **Soci da rinnovare** e puoi incassare con un clic.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'soci-senza-email',
        'title' => 'Posso gestire soci che non hanno un indirizzo email?',
        'body' => <<<'MD'
Sì. Nella scheda del socio l'**email è facoltativa**. Con l'email il socio ha subito accesso all'area riservata. Senza email resta nel libro soci con tessera, quote e partecipazioni, e l'accesso si attiva con un **link da mandargli su WhatsApp**. Gli **ospiti** senza email ricevono avvisi e promemoria tramite il socio che li ospita.

Se un socio vuole accedere e non ha l'email, può usare il **Primo accesso** indicando il cellulare: la segreteria verifica la persona e approva l'accesso. Vedi [Primo accesso dei soci e soci senza email](guida:primo-accesso-e-soci-senza-email).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'pagamenti-con-carta',
        'title' => 'Posso far pagare i soci con carta o PayPal?',
        'body' => <<<'MD'
Sì, con **Pro** (Contabile o Fiscale): i pagamenti online funzionano con **Stripe**, **PayPal**, **SumUp** (anche insieme) o, in alternativa, con **WooCommerce**. Il socio paga dall'area riservata e l'incasso entra da solo in prima nota, con la ricevuta.

Nel **plugin gratuito** puoi comunque indicare le coordinate del bonifico, registrare a mano contanti e bonifici e ricevere **donazioni** con un modulo che porta alla pagina di PayPal. Vedi [Attivare i pagamenti online](guida:attivare-stripe-o-paypal).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'dati-della-carta',
        'title' => 'I dati della carta di credito passano dal mio sito?',
        'body' => <<<'MD'
No. Il socio paga su una **pagina ospitata** da Stripe o da PayPal: i dati della carta non toccano mai il tuo sito né vengono salvati dal plugin.

Le chiavi dei fornitori che inserisci nelle impostazioni sono salvate **cifrate** e legate al tuo sito. Commissioni e rimborsi si gestiscono dal pannello del fornitore e si registrano a mano in prima nota.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'lingua-del-plugin',
        'title' => 'In che lingua è il plugin? Posso averlo in inglese?',
        'body' => <<<'MD'
L'interfaccia e i testi sono in **italiano**, perché il plugin segue le regole del terzo settore italiano (APS, ETS, registri e fisco). Il pacchetto **inglese** è incluso in Pro, e sempre con Pro puoi caricare altre lingue (file CSV, Excel o JSON) e cambiare ogni testo.

Il plugin non usa i file di traduzione di WordPress: le lingue si gestiscono dalla pagina dei testi. Vedi [Personalizzare i testi, il tipo di ente e le lingue](guida:personalizzare-testi-e-lingue).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'elementor-e-woocommerce',
        'title' => 'Funziona con Elementor e WooCommerce?',
        'body' => <<<'MD'
- **Elementor**: sì, in tutte le versioni. Le schermate dei soci sono disponibili come **widget Elementor**, oltre che come blocchi e shortcode; i colori si prendono dal tema impostato in Elementor.
- **WP All Import**: sì, per importare soci e prima nota con le stesse regole dell'importazione da file.
- **WooCommerce**: con **Pro**, in alternativa a Stripe e PayPal. Ogni quota o attività si collega a un prodotto del negozio e il pagamento entra in prima nota una sola volta quando l'ordine è «in lavorazione» o «completato».
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'come-emettere-una-ricevuta',
        'title' => 'Come emetto una ricevuta per un pagamento?',
        'body' => <<<'MD'
Con **{piano_contabile}** o **{piano_fiscale}** ogni incasso ha una **ricevuta in PDF**, numerata (N/AAAA) e con il nome dell'associazione. La trovi in **Cassa → Prima nota**: puoi scaricarla o inviarla per email a chi ha pagato. Con i pagamenti online il socio la riceve da solo.

Vedi [Ricevute in PDF e attestazione annuale](guida:ricevute-in-pdf).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'gestione-iva',
        'title' => 'Il plugin gestisce l\'IVA?',
        'body' => <<<'MD'
Sì, a un livello minimo pensato per chi ha un commercialista che completa i quadri, con **{piano_fiscale}**. Se l'ente ha la partita IVA puoi indicare regime, aliquota e se gli importi sono IVA compresa o esclusa; ogni incasso e spesa registra aliquota e IVA contenuta, e l'esportazione per il commercialista le include.

Senza partita IVA non compare nulla di fiscale. In regime forfettario l'IVA non si applica. Vedi [IVA su quote, attività, incassi e spese](guida:iva-su-quote-e-incassi).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'gestione-cinque-per-mille',
        'title' => 'Posso gestire il 5 per mille?',
        'body' => <<<'MD'
Sì, con **{piano_fiscale}**. Puoi mostrare ai soci il messaggio per chiedere la firma con il codice fiscale dell'associazione, preparare il promemoria nelle Comunicazioni e tenere il registro dei contributi ricevuti. Il plugin calcola la scadenza del **rendiconto sull'utilizzo** (12 mesi dall'accredito) e ti avvisa in Bacheca.

Vedi [Gestire il 5 per mille](guida:cinque-per-mille).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'come-fare-la-copia-di-sicurezza',
        'title' => 'Come faccio una copia di sicurezza dei dati?',
        'body' => <<<'MD'
Da **Impostazioni → Tecniche → Copia di sicurezza** scarichi un file ZIP con tutti i dati del plugin (e, se vuoi, gli allegati). Il ripristino sostituisce i dati con quelli della copia, tutto o niente, dopo aver salvato lo stato precedente. Se l'ultima copia ha più di 30 giorni, la Bacheca te lo ricorda.

È disponibile in tutte le versioni. Vedi [Fare una copia di sicurezza e ripristinarla](guida:copia-di-sicurezza-e-ripristino).
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'come-ricevere-assistenza',
        'title' => 'Come ricevo assistenza se non trovo la risposta?',
        'body' => <<<'MD'
Cerca prima nelle guide con il campo di ricerca del [centro assistenza](/cerca): la maggior parte delle procedure è descritta passo per passo.

Se non trovi la risposta, **apri una richiesta**: accedi dall'area riservata, scegli l'argomento e descrivi il problema, indicando la versione del plugin e, se puoi, i passi che hai fatto. Il tuo indirizzo email e il tuo piano vengono compilati da soli. Riceverai un numero di riferimento e potrai seguire le risposte dalla pagina **I miei ticket**.
MD,
    ],
    [
        'topic' => 'domande-frequenti', 'section' => 'Uso quotidiano', 'slug' => 'ruoli-e-permessi-del-direttivo',
        'title' => 'Posso dare permessi diversi a segreteria, tesoriere e volontari?',
        'body' => <<<'MD'
Sì, in tutte le versioni. I permessi si assegnano dalla **scheda del socio** e valgono solo finché la persona ha il ruolo (e, per le cariche, la tessera in regola):

- **Segreteria**: gestisce soci, attività, incassi, registri e comunicazioni, senza impostazioni.
- **Tesoriere**: incassa, registra le spese e iscrive nuovi soci dall'area riservata, senza vedere comunicazioni e impostazioni.
- **Volontario**: gestisce solo le attività di cui è referente.
- **Staff**: verifica gli ingressi agli eventi.

Presidente e vicepresidente agiscono come la segreteria. In **Impostazioni → Tecniche → Ruoli e accessi** trovi la matrice «chi può fare cosa». Vedi [Assegnare ruoli e permessi](guida:assegnare-ruoli-e-permessi).
MD,
    ],
];
