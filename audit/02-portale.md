# Audit 02 · Portale (`portale/`)

Revisione in sola lettura del 13/11/2026: testi di vendita (A), testi dell'area clienti e staff (B), resa da telefono e logica dei percorsi (C).
I percorsi sono relativi a `portale/`. «Dal vivo» = fatto visto sul sito vero da telefono e passato dal coordinatore; tutto il resto è letto dal codice (le voci «da verificare» lo dicono).

Regole rispettate nel giudizio: mai «Wega» ai clienti (nelle pagine pubbliche e nell'area clienti non compare: bene; resta solo nelle pagine staff), nomi dei piani dal pannello, limiti di legge fissi, pagine d'errore con i due pulsanti di `docs/GRAFICA_UX.md` §3.5.

## I 15 problemi più gravi

| # | Gravità | Dove | Problema | Voce |
|---|---|---|---|---|
| 1 | Bloccante | `views/buy.php:16`, `src/Billing.php:74-95` | Dal vivo: «Scegli …» porta a «Questo piano non è ancora acquistabile». I piani Pro sono in vetrina con prezzo e pulsante ma non si comprano | C1 |
| 2 | Bloccante | `views/_catalog_saas.php:23`, `src/SaasCheckout.php:116-119` | Gestionale: «Prova gratuita di N giorni, senza carta» ma il percorso incassa subito | A1 |
| 3 | Bloccante | `views/saas_buy.php:93`, `src/App.php:186`, `src/SaasCheckout.php:126-158` | Dopo l'acquisto del gestionale il portale non manda nessuna email, anche se la promette | B1 |
| 4 | Bloccante | `views/pricing.php:74-75,88`, `views/_plans.php:17`, `views/buy.php:14` | IVA mai indicata sui prezzi del plugin; il gestionale invece è «+ IVA» in listino e «IVA inclusa» in account | A2 |
| 5 | Bloccante | `src/Legal.php:133-165`, `src/Site.php:65` | Termini e privacy parlano solo del plugin; la FAQ dice che i dati dei soci «non stanno da noi», falso per il gestionale online | A3 |
| 6 | Importante | `views/pricing.php:71`, `src/Site.php:60`, `content/plugin.md:10` | «La licenza gratuita è facoltativa» ma senza licenza il plugin non si scarica da nessuna pagina pubblica | A4 |
| 7 | Importante | `views/home.php:37`, `src/Site.php:83`, `views/account.php:102-130` | «Passi al Pro dall'area riservata, senza reinstallare nulla»: nell'area non c'è il pulsante e il Pro è un secondo plugin da installare | A5 |
| 8 | Importante | `src/Emails.php:31`, `src/Auth.php:7` | Email «Il tuo piano è attivo»: il link per vedere la chiave scade in 15 minuti | B2 |
| 9 | Importante | pannello Prezzi → `views/pricing.php:73`, `views/confronto.php:131` | Dal vivo: promozione ancora chiamata «Halloween» il 13/11 (scade il 26/11) | A6 |
| 10 | Importante | `views/home.php:4-11`, `views/layout.php:11,39`, `src/Site.php:88,100-113` | Il gestionale online è invisibile: non è in home né nel menu; in `/saas` la fascia e la barra portano a prezzi e demo del plugin | C2 |
| 11 | Importante | `views/demo.php:8-65`, `src/Demo.php:74` | Dal vivo: `/prova` è lunga 12.000 px da telefono, con tabella a 9 colonne; il pulsante «Apri la demo» è seguito da 5 schermate di istruzioni | C3 |
| 12 | Importante | `views/message.php:9`, `src/App.php:66-68` | Un errore in un modulo pubblico apre una pagina a parte: dati persi, «Torna al tuo account» anche a chi non ha un account | B3 |
| 13 | Importante | `views/saas_buy.php:31,50,63-81`, `public/assets/portale.css:199,205` | Acquisto gestionale: menu a tendina senza stile (zoom su iPhone), prezzo visibile solo dopo «Calcola il totale», nessun link a termini e privacy | C4 |
| 14 | Importante | `content/confronto.md:173,176`, `views/saas_section.php:10`, `views/wordpress_section.php:9` | Testi sbagliati in vetrina: «30 giorni» fisso, «sezione Prezzi della home» che non esiste, «il elenco delle domande» (dal vivo) | A7 |
| 15 | Importante | `views/wordpress-admin/promos.php:9`, `views/buy.php:18-35` | I codici promo del plugin si creano dal pannello ma nel modulo d'acquisto non c'è il campo per scriverli | C5 |

Dal vivo anche: `/saas/prezzi`, `/wordpress/confronto` e `/partner` danno 404 (voce C6) e in `/saas` i pacchetti hanno «—» senza prezzo (voce C7).

---

# Parte A · Testi di vendita

## A · Bloccante

### A1 · Prova gratuita del gestionale promessa e non mantenuta
- **Dove:** `views/_catalog_saas.php:23` → «Prova gratuita di N giorni, senza carta.»; il pulsante sotto (`:24`) porta a `/saas/acquista`, che chiede il pagamento (`views/saas_buy.php:81` «Ordina e paga»; `src/SaasCheckout.php:116-119`). `docs/PORTALE.md:257` lo ammette: «il listino la annuncia, il flusso d'acquisto oggi incassa subito».
- **Perché non va:** è la promessa più forte della scheda e chi clicca trova la richiesta della carta. Pubblicità non veritiera.
- **Proposta:** finché la prova non esiste, togliere la riga 23. Quando esisterà: «Prova gratis per N giorni. Non serve la carta.» con pulsante «Inizia la prova gratuita».

### A2 · IVA: tre modi diversi, e per il plugin non è scritto niente
- **Dove:** plugin `views/pricing.php:74` («al mese»), `:75` («all'anno»), `:88` («Prezzi in euro.»), `views/_plans.php:17-18`, `views/confronto.php:38-40`, `content/confronto.md:174`, `views/buy.php:14`. Gestionale: `views/_catalog_saas.php:12` («Importi IVA esclusa»), `:21-22` («+ IVA»), `views/saas_buy.php:59` («IVA inclusa»), `views/account.php:43` («IVA inclusa»).
- **Perché non va:** un tesoriere deve sapere quanto esce dalla cassa; un commercialista deve sapere se è imponibile o totale. Ai consumatori il prezzo va mostrato comprensivo di IVA. Nella stessa pagina `/prezzi` convivono un prezzo muto e uno «+ IVA».
- **Proposta (scegliere il regime vero e scriverlo ovunque):**
  - `pricing.php:88` → «Prezzi in euro, IVA inclusa. Pagamento sicuro con carta (Stripe) o PayPal. Ogni licenza vale per un sito e si sposta dall'area riservata.»
  - `buy.php:14`, dopo l'importo → `<small class="muted">IVA inclusa</small>`.
  - `_plans.php:29` → aggiungere «Prezzi IVA inclusa.»
  - Gestionale: mostrare anche in scheda il prezzo finale: «da 12 € al mese (14,64 € con IVA)».

### A3 · Termini, privacy e FAQ non coprono il gestionale online
- **Dove:** `src/Legal.php:135` («Il servizio di licenze, aggiornamenti e area riservata…»), `:138-140` (solo licenza gratuita e piani Pro), `:152`; privacy `:91-94` (solo email e dominio) e `:105`; FAQ `src/Site.php:65` «Dove stanno i dati dei miei soci? Sul tuo sito WordPress, non da noi.»; `:66` «Restano sul tuo sito.»
- **Perché non va:** con il gestionale i dati dei soci stanno sui server di Associazione Semplice. La FAQ è falsa per quel prodotto, i termini non dicono nulla su disdetta, esportazione e cancellazione dei dati, né sul ruolo di responsabile del trattamento (art. 28 GDPR) che un presidente deve poter mostrare ai soci.
- **Proposta:**
  - `Site.php:65` → domanda: «Dove stanno i dati dei miei soci?» risposta: «Dipende da cosa scegli. Con il plugin stanno sul tuo sito WordPress, non da noi: il gratuito non contatta nessun servizio, il Pro controlla solo la licenza. Con il gestionale online stanno sui nostri server in Europa, separati da quelli delle altre associazioni, e li puoi esportare quando vuoi.» (confermare «in Europa» ed «esportare» prima di pubblicare).
  - `Site.php:66` → «Con il plugin restano sul tuo sito: si torna alle funzioni gratuite e nulla viene cancellato. Con il gestionale online hai N giorni per esportarli.»
  - `Legal.php`: aggiungere ai termini le sezioni «Gestionale online» (cosa include, rinnovo, disdetta, cosa succede ai dati) e alla privacy «Dati dei soci nel gestionale online» (noi responsabili, l'associazione titolare). Testo da far vedere a un consulente (lo dice già `views/wordpress-admin/legal_edit.php:24`).

## A · Importante

### A4 · «La licenza è facoltativa», ma senza licenza il plugin non si trova
- **Dove:** `views/pricing.php:71` e `views/_plans.php:15` «La licenza gratuita è facoltativa»; `views/home.php:35` «Il plugin funziona anche senza.»; `src/Site.php:60`; `content/plugin.md:10` «Si scarica da WordPress.org»; però `views/pricing.php:79` «Inizia gratis» → `/free` (modulo) e `views/account.php:75` «Il gratuito si aggiorna caricando lo zip da qui»; `content/confronto.md:161` «finché non è su WordPress.org».
- **Perché non va:** tre pagine dicono tre cose. Chi non vuole lasciare l'email non ha nessun link per scaricare; chi segue `plugin.md` cerca su WordPress.org un plugin che non c'è ancora.
- **Proposta (finché non è su WordPress.org):**
  - `pricing.php:71`, `_plans.php:15`, `confronto.php:38` → «Ti serve solo un'email».
  - `home.php:35` → «Scrivi email e indirizzo del sito: ti mandiamo il link per scaricare il plugin, con la chiave e l'accesso al supporto.»
  - `plugin.md:10` → «Si scarica dalla tua area riservata dopo aver chiesto la licenza gratuita. Funziona da solo e non contatta servizi esterni.»
  - `Site.php:60` → «No. Il plugin è gratuito e resta tale. Per scaricarlo chiedi la licenza gratuita: basta un'email. Con la licenza hai anche il supporto e la prova dei piani Pro.»

### A5 · «Senza reinstallare nulla» e «dall'area riservata»: due promesse non vere
- **Dove:** `views/home.php:37` «Passi al Pro dall'area riservata, senza reinstallare nulla.»; `src/Site.php:83` «al Pro passi quando vuoi, senza reinstallare»; `views/free.php:27`; contro `content/plugin.md:20` «È un plugin a parte, che richiede quello gratuito» e `content/confronto.md:182` «Non si reinstalla nulla (se hai già Pro)». In `views/account.php:102-130` una licenza gratuita mostra solo la prova: nessun pulsante «Passa al Pro» (il link a `/prezzi` c'è solo nello stato vuoto, `:61`).
- **Proposta:**
  - `home.php:37` → «Ti servono incassi online o contabilità? Scegli un piano Pro, installi il plugin Pro accanto a quello gratuito e i dati restano dove sono.»
  - `Site.php:83` → «…e al Pro passi quando vuoi: i tuoi dati restano dove sono.»
  - `account.php`, dentro la scheda della licenza gratuita, dopo `:107`: `<div class="trial-box"><p><b>Ti servono incassi online o contabilità?</b><br><span class="muted small">Passi a un piano Pro tenendo tutti i dati.</span></p><a class="btn" href="/prezzi">Vedi i piani Pro</a></div>`.

### A6 · Promozione «Halloween» a metà novembre (dal vivo)
- **Dove:** nome scritto nel pannello (Piani e prezzi → Promozione in vetrina), mostrato da `views/pricing.php:73` e `views/confronto.php:131`.
- **Perché non va:** un'offerta di Halloween il 13/11 sembra un sito abbandonato o uno sconto finto; la data di fine non si vede in `/prezzi`.
- **Proposta:** rinominare subito (es. «Offerta d'autunno» o «Black Friday» dal 20/11). In `pricing.php:73` mostrare anche la fine: `<div class="promo-tag">NOME · fino al 26/11</div>`. In `views/wordpress-admin/prices.php:11` aggiungere il suggerimento «Scegli un nome che resti vero fino all'ultimo giorno».

### A7 · Testi sbagliati o superati
| Dove | Testo attuale | Perché | Riscrittura |
|---|---|---|---|
| `views/saas_section.php:10`, `views/wordpress_section.php:9` | «c'è il elenco delle domande», «sono nel elenco delle domande» | Errore di grammatica (dal vivo) | «ci sono le <a>domande frequenti</a>» · «sono nelle <a>domande frequenti</a>» |
| `content/confronto.md:173` | «Prova gratuita di Pro (30 giorni)» | I giorni si cambiano dal pannello (`src/Settings.php:17`, da 1 a 90) e la prova si può spegnere | Segnaposto `{trial_days}` sostituito in `views/confronto.php:48`; riga nascosta se la prova è spenta |
| `content/confronto.md:176` | «nella sezione **Prezzi** della home» | La home non ha quella sezione; la pagina è `/prezzi` | «I prezzi aggiornati sono nella pagina [Prezzi](https://associazionesemplice.it/prezzi). Se la licenza scade, il Pro torna alle funzioni gratuite e i dati restano.» |
| `content/confronto.md:180` | «**Da Free a …**» | Nome del piano scritto fisso | «**Da {free_name} a {lite_name}**» |
| `views/pricing.php:17` | «'lead' => 'Tutto il Free, più:'» | Nome fisso (viene sostituito solo se c'è un piano prima: `:60`) | «Tutto il piano gratuito, più:» |
| `src/Site.php:131`, `views/confronto.php:49` | riserva «PRO Contabilità», «PRO Contabilità & Fiscalità» | Diversi dai nomi decisi («Contabilità», «Contabilità & Fiscale», `docs/GRAFICA_UX.md` §2) e da `config.example.php:53,61` («Pro Contabile», «Pro Fiscale») | Una sola riserva uguale ai nomi decisi |
| `views/wordpress_section.php:9` | «Guarda il SaaS» | Sigla che un volontario non conosce | «Guarda il gestionale online» |
| `views/saas_section.php:3` | «Lo stesso lavoro del plugin, senza un sito WordPress da mantenere: la segreteria apre il gestionale dal browser…» | Parte dal confronto con un prodotto che il lettore può non conoscere | «Soci, quote, eventi e conti in un posto solo. Non devi installare niente: si apre da computer e da telefono, e ogni socio ha la sua area riservata.» |
| `src/Site.php:52` | «Nel menu del sito, non in wp-admin.» | Gergo | «La trova nel menu del sito, senza entrare nel pannello di WordPress.» |
| `views/free.php:5` | «la chiave per i servizi collegati» | Non dice a cosa serve | «Bastano due dati: ricevi il plugin, la chiave di licenza, l'accesso al supporto e la prova dei piani Pro. Ti mandiamo un link per confermare l'email.» |
| `views/free.php:24` | «verbali e backup» | Inglese inutile (altrove è «copia di sicurezza») | «Informativa, consensi, verbali e copia dei dati.» |
| `views/demo.php:8` | paragrafo di 62 parole («Un sito WordPress vero… non si possono cambiare.») | Troppo lungo, mescola invito e avvertenze | «Un sito di prova già pieno di dati inventati. Clicca, inserisci, cancella: non rompi niente.» + riga piccola: «Non scrivere dati veri: la demo è pubblica e si azzera ogni notte alle 4. Le email non partono.» |
| `views/wordpress_changelog.php:10,14` | data `2026-11-10` | Formato tecnico; esiste `date_it()` | `date_it( $r['versions'][0]['created_at'] )` → 10/11/2026 |
| `src/Site.php:64` | «Hai alcuni giorni di tolleranza.» | Vago: quanti? | «La licenza resta valida fino alla scadenza già pagata; intanto ti scriviamo per sistemare il pagamento. I dati non vengono mai cancellati.» (coerente con `src/Emails.php:41`) |
| `src/Site.php:63` | «Il rinnovo è automatico, mensile o annuale a scelta.» | In `views/buy.php:6-7` esiste anche il pagamento singolo senza rinnovo | «Scegli tu: rinnovo automatico (mensile o annuale) oppure pagamento singolo, che non si rinnova. Il rinnovo automatico si disdice dall'area riservata e la licenza vale fino alla scadenza già pagata.» |

### A8 · La promessa in cima alla home parla solo a chi ha già WordPress
- **Dove:** `views/home.php:4` «Plugin WordPress per associazioni», `:6` «Un plugin per il tuo sito WordPress: libro soci, eventi e corsi, prima nota e privacy. Il gratuito basta per cominciare; incassi online e contabilità si aggiungono con il Pro.»
- **Perché non va:** un presidente senza sito (o che non sa cosa sia un plugin) si ferma alla prima riga; il titolo di riserva (`views/layout.php:18`) dice invece «Il gestionale per le associazioni». Nessuna parola per APS, ASD, ETS.
- **Proposta:**
  - `:4` → «Per APS, ASD ed enti del Terzo Settore»
  - `:5` (resta) «Soci, eventi e cassa della tua associazione, finalmente in ordine.»
  - `:6` → «Libro soci, tessere, iscrizioni a corsi ed eventi, prima nota e privacy. Lo usi dentro il tuo sito WordPress oppure online, senza installare niente. Si parte gratis.»
  - `:11` → `<li>Gratis per cominciare</li><li>In italiano, con le regole del Terzo Settore</li><li>I dati restano tuoi</li>`
  - sotto i pulsanti: `<p class="fine">Non hai un sito WordPress? <a href="/saas">C'è il gestionale online</a>.</p>`

### A9 · I termini che cerca il commercialista non ci sono
- **Dove:** in tutte le pagine pubbliche non compaiono mai: «Mod. D», «RUNTS», «Codice del Terzo Settore», «regime 398», «fattura elettronica», «riconciliazione», «esercizio». Ci sono solo «rendiconto per cassa per il commercialista» (`views/pricing.php:32`, `content/confronto.md:122`) e «Anno fiscale» (`:35`).
- **Proposta (una riga tecnica per scheda, senza appesantire; scrivere solo ciò che il prodotto fa davvero, da confermare con la sessione plugin):**
  - `pricing.php:32` → «Rendiconto per cassa (Mod. D degli ETS), pronto per il commercialista»
  - `pricing.php:30` → «IVA su quote, attività, incassi e spese (anche regime forfettario L. 398/1991)» solo se gestito
  - `pricing.php:35` → «Esercizio con mese di inizio a scelta e verifiche prima della chiusura»
  - `src/Site.php:50` → «Entrate e uscite sempre al loro posto, con scontrini e fatture allegati. È la base del rendiconto di fine esercizio.»
  - `views/features.php:8`, prima del link → `<p class="fine center">Per il commercialista: prima nota, rendiconto per cassa (Mod. D), 5 per mille, IVA, ricevute ed esportazioni in CSV.</p>`
  - `content/confronto.md:116` titolo → «Parte fiscale (per il commercialista)»
  - Se fattura elettronica e riconciliazione bancaria non ci sono, dirlo in una FAQ («Fa anche la fattura elettronica?»): è la prima domanda di un commercialista (obiezione oggi senza risposta).

### A10 · Nessuna prova di fiducia
- **Dove:** `views/home.php` (tutta), `views/pricing.php`, `views/layout.php:57` («© 2026 Associazione Semplice»).
- **Perché non va:** nessun numero, nessuna associazione che lo usa, nessun nome di chi c'è dietro, nessuna partita IVA nel piè di pagina (obbligatoria per chi vende online), nessun contatto oltre a «Supporto».
- **Proposta:** `layout.php:57` → `© 2026 {titolare} · P.IVA {piva} · <a href="mailto:{email}">{email}</a>` con i dati di `Legal::vars()`. In home, sotto i tre passi, una fascia con ciò che è vero oggi: «Soddisfatti o rimborsati entro 14 giorni» (è già nei termini, `src/Legal.php:149`), «Assistenza in italiano», «Disdici da solo, in un clic». Testimonianze solo quando ci sono davvero.

### A11 · Etichette dei pulsanti: la stessa azione ha quattro nomi
| Azione | Etichette usate | Dove | Proposta unica |
|---|---|---|---|
| Chiedere la licenza gratuita | «Ottieni la licenza gratuita», «Inizia gratis», «Licenza gratuita», «Ricevi la chiave» | `home.php:9`, `pricing.php:79`, `_plans.php:22`, `confronto.php:38`, `Site.php:78,103`, `layout.php:58`, `account.php:61`, `free.php:15` | «Inizia gratis» nei pulsanti; «Licenza gratuita» solo come voce di menu; in `free.php:15` «Inizia gratis: ricevi il link» |
| Scegliere un piano | «Scegli {nome}», «Scegli questo piano», «Scegli» | `pricing.php:81-82`, `_plans.php:24`, `confronto.php:40`, `_catalog_saas.php:24` | «Scegli {nome}» ovunque |
| Vedere i prezzi | «Prezzi», «Vedi i prezzi», «Vedi i piani», «Vedi i piani Pro», «Tutti i dettagli dei prezzi» | `layout.php:32`, `Site.php:77,102`, `account.php:61`, `plugin.md:33`, `_plans.php:29` | «Vedi i prezzi» |
| Confronto | «Confronto versioni», «Confronta le versioni», «Confronta tutte le funzioni delle tre versioni», «confronto tra le versioni», «Confronta le funzioni tra le versioni» | `layout.php:39,58`, `Site.php:81`, `pricing.php:88`, `features.php:8`, `_plans.php:29` | «Confronta i piani» (si chiamano «piani» in tutti i prezzi; «versioni» crea confusione con le versioni del plugin di `/wordpress/changelog`) |
| Pagare | «Ordina e paga» / titoli «Completa l'acquisto», «Prendi il gestionale» | `buy.php:13,34`, `saas_buy.php:23,81` | Titoli: «Acquista {piano}» · «Acquista il gestionale» |

### A12 · Titoli e SEO di base
- `views/layout.php:18`: la home non passa `title` → «Associazione Semplice · Il gestionale per le associazioni», ma la descrizione (`src/Site.php:30`) e l'h1 parlano di plugin. Proposta title: «Associazione Semplice · Soci, eventi e prima nota per APS, ASD ed ETS».
- `src/App.php:137`: title «Il plugin Associazione Semplice · Associazione Semplice» (nome ripetuto) → «Il plugin per WordPress».
- `src/App.php:143,145`: title «Funzioni», «Domande frequenti» troppo generici → «Funzioni: libro soci, eventi, prima nota» · «Domande frequenti su licenza, prezzi e dati».
- `views/layout.php:19-22`: mancano `<link rel="canonical">` e i tag `og:title`, `og:description`, `og:image` (un link condiviso su WhatsApp, il canale tipico delle associazioni, esce senza anteprima). Manca `/sitemap.xml` (`src/App.php:130-132` ha solo robots.txt).
- `/funzioni` e home hanno lo stesso h2/h1 «Tutto quello che serve a una segreteria» (`home.php:24`, `features.php:2`) e le stesse sei schede: vedi C8.
- h1 unico: rispettato in tutte le viste. Ma l'h1 di `features.php:2`, `pricing.php:48`, `faq.php:2`, `saas_section.php:3`, `wordpress_section.php:3`, `demo.php:7` ha lo stile scritto a mano (`style="font-size:clamp(…)"`): meglio una classe.
- `/prezzi` (`pricing.php:48`): h1 «Prezzi chiari, disdici quando vuoi» sta dentro la sezione plugin; il gestionale più sotto ha un h2 generico «Piani e prezzi» (`_catalog_saas.php:12`), uguale anche in `/confronto` dove di prezzi non ce ne sono (lì: «Cosa comprende il gestionale online»).
- `src/Site.php:88`: fascia di `/saas` «Prezzi e confronto tra le versioni» porta ai prezzi del plugin (vedi C2).

## A · Miglioria

- **Tono:** quasi tutto al «tu», bene. Stonano: `views/saas_buy.php:39` «Quanti soci avete» (voi) → «Quanti soci ha l'associazione (anche in previsione)?»; `views/area_vai.php:4` e `views/wordpress-admin/staff_vai.php:4` «Ti porto lì» (io) e `src/Billing.php:459`, `src/App.php:617` «Non riesco…» contro il «noi» del resto → «Ti portiamo lì…», «Non riusciamo…».
- **«Free» / «gratuito»:** `config.example.php:51` etichetta «Free», testi «il gratuito», «piano gratuito», «licenza gratuita», «versione gratuita» (`src/Site.php:34`). Proposta: piano «Gratuito» ovunque.
- **Piè di pagina** (`views/layout.php:58`): «Plugin WordPress» (`/wordpress`) e «Il plugin» (`/plugin`) sono due link quasi uguali → tenere «Plugin WordPress» e far diventare `/plugin` una scheda di `/wordpress`; raggruppare in tre colonne (Prodotti · Aiuto · Note legali).
- `views/home.php:42` «Tre piani, parti da quello gratuito» ripete «gratuito» tre volte in due righe → «Tre piani. Si parte gratis.» / «I piani Pro si rinnovano da soli e li disdici quando vuoi.»
- `views/pricing.php:17`: «Tutto il gratuito, più incassi online e comunicazione ai soci. Senza la parte fiscale.» chiude in negativo → «Per incassare online e scrivere ai soci.»
- `views/pricing.php:19-27`: «Conti multipli, fondi e giroconti», «Apple Wallet e Google Wallet», «app e notifiche» → «Più conti (cassa, banca, PayPal) e passaggi tra conti» · «Tessera nel telefono (Apple e Google Wallet)».
- `views/pricing.php:87`: frase di 33 parole in grassetto → «Hai già la licenza gratuita? Prova {piano} per N giorni, una volta sola. Non serve la carta.»
- `views/buy.php:27` «La licenza sarà legata a questo dominio; potrai cambiarlo in seguito.» → «La licenza vale per questo sito. Se cambi indirizzo lo aggiorni dall'area riservata.» Stesso campo, tre etichette: «Indirizzo del sito WordPress» (`free.php:12`), «Dominio» (`account.php:97`), «Cambia dominio» (`:145`): scegliere «Indirizzo del sito» e spiegare «dominio» una volta.
- `views/saas_buy.php:42` «Componenti in più» / listino «pacchetti» (`saas_manage.php:27`, FAQ) → «Pacchetti aggiuntivi». `:68` «ne aggiungiamo un pezzetto noi» → «Se è già usato aggiungiamo un numero alla fine.»
- `content/confronto.md:13` (66 parole in una cella), `:63-69`, `:79`: descrizioni lunghissime; da telefono lo script le piega, ma senza script la tabella è illeggibile. Tenere il titolo breve prima di « :: » in ogni riga (mancano in `:9-11,14-15,18,20-28,35,37-46,52-60,80-81,88-96`).
- `content/confronto.md:12,19,79,101,104,106`: gergo («ribaltamento opzionale», «tutto o niente», «estinzione», «con il QR dello staff») da riscrivere in parole semplici.
- `content/plugin.md:15` «tramite blocchi, shortcode e widget Elementor» → «si inserisce nelle pagine del sito con i blocchi di WordPress (anche shortcode ed Elementor)».
- `src/Legal.php:120`: «sotto `/demo/` il cookie «asem_s»» con gli accenti gravi visibili (Legal::html non li converte).

---

# Parte B · Testi dell'area clienti e delle aree staff

## B · Bloccante

### B1 · Dopo l'acquisto del gestionale non arriva nessuna email dal portale
- **Dove:** promessa in `views/saas_buy.php:93` («creiamo il gestionale… e ti scriviamo per entrare») e `src/App.php:186` («ti scriviamo un link per entrare nell'area riservata e trovare… l'indirizzo del tuo gestionale»). In `src/SaasCheckout.php:126-158` (`paid`) non c'è nessun `Emails::send`; `src/Emails.php:17-83` non ha un evento per il gestionale; `docs/PORTALE.md:271` lo elenca tra le cose «da fare».
- **Perché non va:** chi ha appena pagato resta su «Grazie!» senza sapere dove andare. Se il SaaS manda un suo invito, il testo va comunque allineato; se non lo manda, il cliente è bloccato (deve indovinare «Accedi → Password dimenticata o prima volta qui?»).
- **Proposta:** nuovo evento `saas_welcome`: oggetto «Il gestionale di {associazione} è pronto», testo «Grazie! Il gestionale di {associazione} è attivo fino al {scadenza}.\n\nPer entrare la prima volta scegli la tua password da qui (il link vale 24 ore):\n{link}\n\nDa quel momento entri da https://associazionesemplice.it/accedi con email e password.» E in `/grazie`, per gli ordini del gestionale: «Stiamo preparando il gestionale: di solito bastano pochi minuti. Ti arriva un'email con il link per entrare. Se non la vedi, guarda nello spam oppure vai su Accedi e scegli "Prima volta qui?".»

## B · Importante

### B2 · Link nelle email: 15 minuti sono pochi (e l'email di benvenuto li spreca)
- **Dove:** `src/Auth.php:7` (`TTL = 900`), `src/Emails.php:21,26,31`; `src/App.php:166` non dice la scadenza; `views/login.php:14` «(vale 15 minuti)»; `views/identity_reset_request.php:4` «Vale un'ora»; `src/App.php:203` «Vale poco: usalo subito.»
- **Perché non va:** l'email «Il tuo piano è attivo» può essere letta ore dopo: il link per «vedere la chiave» è già scaduto e il cliente che ha pagato vede «Il link non è valido o è scaduto». Tre durate diverse scritte in tre posti.
- **Proposta:** `Emails.php:31` → «Grazie!\nLa licenza {piano} per {dominio} è attiva fino al {scadenza}.\n\nLa chiave è nella tua area riservata:\n{account_url}\n\nÈ la prima volta? Vai su Accedi, scrivi questa email e scegli "Mandami un link per entrare".» (oppure link di benvenuto valido 24–48 ore). `App.php:166` → «Ti abbiamo scritto a {email}. Apri il link entro 15 minuti per confermare. Non lo trovi? Guarda nello spam.» `App.php:203` → scrivere la durata vera.

### B3 · Errori dei moduli pubblici: pagina a parte, dati persi, pulsante sbagliato
- **Dove:** `src/App.php:66-68` (ogni `UserError` → vista `message`), `views/message.php:9` («Torna al tuo account» + «Vai alla home»). Colpisce `/free`, `/acquista`, `/accedi`: es. `src/Billing.php:139` «Dominio non valido…», `:120` «Questo dominio risulta già registrato da un altro indirizzo email.», `src/Auth.php:102` «Email o password non corrette.», `src/App.php:62` «Sessione scaduta: ricarica la pagina e riprova.» (ma la pagina da ricaricare non è più quella).
- **Perché non va:** chi sbaglia il dominio perde email e nome già scritti; «Torna al tuo account» porta un visitatore senza account alla pagina di accesso. La regola dei due pulsanti (`docs/GRAFICA_UX.md` §3.5) resta valida per 404 ed errori veri, non per un campo sbagliato.
- **Proposta:** per gli errori di modulo ri-mostrare la pagina con il messaggio sopra il campo e i valori conservati (come fa già `/saas/acquista`, `views/saas_buy.php:27`). In `message.php:9`, come minimo, un terzo pulsante prima degli altri: `<a class="btn" href="javascript:history.back()">Torna indietro e correggi</a>`, e «Torna al tuo account» solo se `$_SESSION['customer']` c'è (altrimenti «Accedi»).
- **Messaggi da riscrivere:**
  - `Billing.php:120` → «Questo sito ha già una licenza con un'altra email. Entra con quell'email, oppure scrivici dal supporto se il sito è passato a te.»
  - `Billing.php:440` «Dominio non valido.» → uguale a `:139` (che spiega come scriverlo).
  - `Billing.php:457` «Dominio non accettato dal server delle licenze.» → «Questo indirizzo non si può usare per una licenza. Controlla di averlo scritto bene (per esempio miaassociazione.it).»
  - `App.php:74` «…con il server licenze» → «Non siamo riusciti a completare l'operazione. Non è cambiato nulla: riprova tra qualche minuto.»
  - `App.php:172` «Piano non disponibile.» → «Questo piano non è più in vendita. Guarda i piani disponibili nella pagina Prezzi.» (con link).
  - `App.php:371` «Questa pagina non esiste.» → «Questa pagina non esiste o è stata spostata. Riparti dalla home o dalla pagina Prezzi.»
  - `App.php:78` «Se il problema continua scrivici.» → dire dove: «…scrivici dal supporto o a info@associazionesemplice.it.»
  - `Billing.php:245,291,436,469` «Abbonamento sconosciuto/non trovato» e `:629`, `PlanChange.php:91` «Licenza non trovata»: stesso oggetto, due nomi → «Licenza non trovata: ricarica la pagina del tuo account.»
  - `App.php:321,329,364` idem per il gestionale: «Abbonamento non trovato.» va bene, ma aggiungere cosa fare.
  - `Auth.php:102` → «Email o password non corrette. Se non ricordi la password, fatti mandare un link per entrare.»

### B4 · Azioni che pesano senza conferma, o con conferma poco chiara
- **Cambio dominio** (`views/account.php:143-148`): il pulsante «Aggiorna» blocca il dominio per 180 giorni (`src/Settings.php:28`) e spegne il vecchio sito, senza conferma; l'avviso è scritto sotto, dopo il pulsante (`:159`). Proposta: `onsubmit` con `js_confirm( 'Spostare la licenza sul nuovo indirizzo? Il vecchio sito smette di funzionare con questa chiave e per 180 giorni non potrai cambiarlo di nuovo.' )`; pulsante «Sposta la licenza»; la riga `:159` va sopra il campo.
- **Attiva la prova** (`account.php:105`): una sola volta per licenza, senza conferma → «Attiva la prova di N giorni» + conferma «La prova parte adesso e dura N giorni. Si può fare una volta sola. Parto?».
- **Disdetta** (`account.php:54,154`): la conferma non dice la data → «Disdire il rinnovo automatico? La licenza resta valida fino al {data}, poi tornano le funzioni gratuite. I dati restano.» Il pulsante è «alt» per il gestionale e «danger» per il plugin: uniformare.
- **Togli l'indirizzo personalizzato** (`views/saas_manage.php:46`): non dice che il sito smette di rispondere su quel dominio → «…Il gestionale non risponderà più a {dominio}; resta l'indirizzo standard.»
- **Staff:** `views/wordpress-admin/license.php:44-48` cambio dominio senza conferma; `:50` «Aggiungere un periodo alla licenza?» non dice quanti giorni né che non incassa nulla.

### B5 · Termini che cambiano da una riga all'altra
- **Area:** «Il mio account» (`layout.php:34`, nome fisso da `GRAFICA_UX` §2), «area riservata» (home, FAQ, email, `enter.php:5`), «area clienti» (`saas_buy.php:60`), «Le tue licenze» (h1 di `account.php:6`, anche quando il cliente ha solo il gestionale), «Il tuo account» (`account_staff.php:4`, `profile.php:8`). L'«area riservata» è anche quella dei soci nel prodotto (`Site.php:52`): doppio senso. Proposta: «il tuo account» ovunque per il portale (h1 «Il tuo account»), «area riservata dei soci» solo per il prodotto.
- **Licenza / abbonamento / piano:** `account.php` usa «licenza»; errori «Abbonamento non trovato» (`Billing.php:436`); email «Abbonamento attivo» (`Emails.php:29`); `App.php:329,364` «per questo abbonamento». Proposta: plugin = «licenza», gestionale = «abbonamento», «piano» = il livello.
- **Disdire / annullare:** stato «Annullata» (`helpers.php:8`), fatto «Rinnovo: Disdetto» (`account.php:99`), pulsante «Disdici il rinnovo», rotta `/account/annulla`, «Annullato» (`SaasAccount.php:34`), «Annulla il cambio» (`account.php:117`). Proposta: «Disdetta» per i rinnovi; «Annulla» solo per tornare indietro su un'azione.
- **Accedi / entra:** titolo «Accedi», pulsante «Entra», link «Mandami un link per entrare», «Torna all'accesso» (`twofactor.php:13`). Accettabile; unificare il pulsante in «Accedi» come il titolo.
- **Secondo livello / verifica in due passaggi / codice di verifica:** `profile.php:39`, `twofactor.php:4`, `wordpress-admin/security.php:3`, `mfa_verify.php:3`. Proposta: «Verifica in due passaggi» ovunque. `profile.php:41` «(per esempio quella del telefono)» → «(per esempio Google Authenticator o Microsoft Authenticator)».
- **Pulsanti uguali con nomi diversi:** «Fatture e metodo di pagamento» (`account.php:52`) e «Carta e fatture» (`:151`) → «Fatture e metodo di pagamento».

### B6 · Stati poco chiari e dati grezzi
- `views/account.php:41`: mostra `$g['plan']`, cioè il codice interno del piano del gestionale, non il nome (la vista non ha il catalogo). Da verificare con un ordine vero; proposta: passare il nome da `Catalog::plans()` come fa `App::saasManage` (`src/App.php:494-497`).
- `src/helpers.php:11` «Rinnovo registrato», `:14` «In attesa» (di cosa?) → «In attesa del pagamento». `:33` «Assegnata manualmente» → «Assegnata da noi». `:36` «Carta · singolo» → «Carta · pagamento singolo».
- `views/account.php:94,104`: la prova dà una **seconda chiave** da incollare e poi togliere a mano («alla fine rimetti quella gratuita»). È l'attrito più grande della prova: se si può, la stessa chiave dovrebbe sbloccare la prova. Testo intanto: «Alla fine della prova, se non scegli un piano, rimetti la chiave gratuita: i dati restano.»
- `views/account.php:157-159`: tre righe fitte con `staging.`, `clone.`, «sottocartelle», «al controllo successivo» → «Vale anche per i siti di prova dello stesso indirizzo (per esempio prova.{dominio}).» e il resto dietro «Dettagli».
- `views/account.php:123-125`: l'opzione di passaggio a un piano inferiore può essere bloccata (`src/PlanChange.php:74-77`, campo `blocked`) ma la vista non lo mostra: il cliente clicca, conferma e riceve una pagina d'errore (`PlanChange.php:147`). Mostrare il motivo al posto del pulsante.
- `views/saas_manage.php:17` «Come sta andando» per tre numeri → «Quanto stai usando»; `:22` «Spazio: N GB» è solo il limite → «Spazio disponibile». `:38-39` «nameserver», «record MX», «nella nostra zona»: l'avviso sulla posta arriva **dopo** il salvataggio; va prima del modulo: «Attenzione: se sul tuo dominio hai anche la posta, parlane prima con chi te lo gestisce o scrivici. Cambiando i nameserver la posta si ferma finché non la riconfiguriamo insieme.»
- `views/saas_manage.php:65` «Per cambiare davvero scrivici dal supporto»: vicolo cieco senza link → aggiungere `<a class="btn alt" href="/supporto/accedi">Scrivi al supporto</a>`.
- `views/profile.php:71` «Indirizzo 93.x.x.x · ultimo uso …» → «Indirizzo IP … · ultimo accesso il …» (data in formato italiano).
- `views/account_staff.php:11` «Per ora non hai aree a cui accedere.» → aggiungere cosa fare: «Chiedi a chi ti ha invitato di assegnarti un ruolo.»
- `views/area_vai.php:3`: h1 fuori dal riquadro e title «Apertura» (`App.php:617`); senza JavaScript resta solo «Continua». → «Stiamo aprendo: {nome}» + «Se non succede niente, premi Continua.»
- `views/login.php:11`: la password non è obbligatoria; lasciandola vuota e premendo «Entra» parte un link per email senza averlo chiesto (`App.php:247-261`). Proposta: due pulsanti chiari, «Accedi» e «Entra senza password (ti mandiamo un link)».
- `views/login.php:13`: «Password dimenticata o prima volta qui?» porta a una pagina intitolata «Password dimenticata» (`identity_reset_request.php:3`): chi entra la prima volta pensa di aver sbagliato. Titolo: «Scegli o recupera la password».

### B7 · Testi delle email (`src/Emails.php`)
- Saluti diversi: «Ciao,» (`:21,26`), «Grazie!» (`:31`), nessun saluto nelle altre. Uniformare con «Ciao,» (o «Ciao {nome},» quando c'è).
- `:10` firma con indirizzo fisso `https://associazionesemplice.it` invece di `base_url`; nessun contatto per rispondere → «— Associazione Semplice\nPer aiuto: {supporto_url}».
- `:41` pagamento non riuscito: non dice cosa fare → «…Per sistemare: entra nel tuo account, premi "Fatture e metodo di pagamento" e aggiorna la carta.\n{account_url}»
- `:51` «al prossimo controllo del plugin» → «entro un giorno; per averle subito premi "Controlla adesso" nelle impostazioni del plugin».
- `:71,76` «rimetti la chiave gratuita» (vedi B6).
- `:81` «scrivici subito» senza dire dove.
- `:29` etichetta «Abbonamento attivo» per una licenza (vedi B5).
- Tutte le email sono solo per il plugin: per il gestionale mancano benvenuto, rinnovo, pagamento non riuscito, disdetta (B1).

## B · Miglioria (staff)

- `views/wordpress-admin/_nav.php:35`: «Piattaforma SaaS» porta a `/saas`, la pagina pubblica, non all'amministrazione → `/saas/admin`, con il nome fisso «Gestisci SaaS».
- `_nav.php:8`: scheda «Privacy e cookie» ma dentro ci sono anche i Termini (`legal_edit.php:16`) → «Privacy, cookie e termini».
- `legal_edit.php:21`: l'elenco dei segnaposto omette `{piano_lite}` e `{piano_full}` (esistono: `src/Legal.php:24-25`).
- `catalog.php:25,62`, `promos.php:35`, `utenti.php:18`: classe `btn small ghost` che fuori dalla fascia finale non ha stile (`portale.css:282` la definisce solo dentro `.cta-band`): «Scarta la bozza», «Sospendi», «Togli ruolo» appaiono come pulsanti principali → usare `btn alt small`.
- `catalog.php:8`, `account.php:39`, `saas_manage.php:29`: classe `badge ok` non definita nel CSS (lo stato «Attivo» esce col colore generico).
- `audit.php:3`: «Quando (UTC)» → ora italiana con `date_it`.
- `dashboard.php:5`, `payments.php:80`, `wega.php`, `license.php:16-17,24`: «Wega» compare allo staff. Ammesso dalla regola (solo i clienti non devono vederlo), ma il ruolo «vendita» o «solo supporto» può essere un collaboratore esterno: valutare «server licenze».
- `src/Plugin/Promos.php:136,146`, `SubscriptionNotice.php:41-53`: messaggi con il codice tecnico davanti («unknown_code: …»); se arrivano a una persona, togliere il prefisso.
- `src/App.php:647-669`: `staffLogin()` non è richiamata da nessuna rotta (codice morto, insieme a `views/wordpress-admin/staff_login.php`): da verificare e togliere.

---

# Parte C · Resa da telefono e logica dei percorsi

## C · Bloccante

### C1 · I piani Pro si vedono ma non si comprano (dal vivo)
- **Dove:** `views/buy.php:15-16`: se `Billing::methods()` è vuoto (`src/Billing.php:74-95`: mancano le chiavi Stripe/PayPal della modalità scelta, `src/Settings.php:376-383`) esce «Questo piano non è ancora acquistabile: riprova tra poco o scrivici». Intanto `views/pricing.php:81-82`, `views/_plans.php:24` e l'intestazione del confronto (`views/confronto.php:40`) mostrano prezzo e «Scegli».
- **Perché non va:** è il punto in cui il cliente ha deciso di pagare. «Riprova tra poco» è falso (non è un guasto momentaneo).
- **Proposta:** (1) configurare i pagamenti in modalità reale; (2) finché non lo sono, non mostrare «Scegli»: in `pricing.php:81-82` e `_plans.php:24` se `! $billing->methods( $price )` mostrare «Avvisami quando si può acquistare» (link a `/free`) o «Prova gratis per N giorni»; (3) testo di `buy.php:16`: «I pagamenti online non sono ancora attivi. Intanto puoi partire con la licenza gratuita e provare questo piano per N giorni senza carta. Per acquistarlo subito scrivici a info@associazionesemplice.it.» con pulsante «Inizia gratis».

## C · Importante

### C2 · Il gestionale online è fuori dal percorso
- **Dove:** menu `views/layout.php:11,39` (nessuna voce), home `views/home.php` (nessun cenno); ci si arriva solo dal piè di pagina (`layout.php:58`) e dai due link in cima a `/prezzi` (`pricing.php:45`). In `/saas`: fascia finale `src/Site.php:88` → «Vedi i prezzi» = `/prezzi` (in alto ci sono i piani del plugin) e «Confronta le versioni» = confronto del plugin; barra da telefono `Site.php:113` → «Prova la demo» = demo del plugin WordPress, mentre la demo del gestionale è un link esterno (`saas_section.php:9`). `/prova` (`demo.php:7`) e `/funzioni` descrivono solo il plugin.
- **Proposta:** voce di menu «Gestionale online» accanto a «Funzioni» (oppure in home un bivio subito sotto la promessa: «Hai un sito WordPress? → Plugin» · «Non hai un sito? → Gestionale online»). `Site.php:88` → `array( 0, 'Vuoi provarlo?', 'Apri la demo del gestionale: dati inventati, niente da installare.', array( 'Prova la demo del gestionale', $demoSaas ), array( 'Vedi i prezzi', '/prezzi#prezzi-saas' ) )`. In `Site::bar()` un caso per `/saas` con gli stessi due link.

### C3 · `/prova` da telefono: 12.000 px e una tabella a 9 colonne (dal vivo)
- **Dove:** `views/demo.php`: paragrafo lungo (`:8`), tre schede di accesso con elenco (`:15-26`), riepilogo piani (`:30-33`), quattro tabelle di collegamenti (`:38-45`), tabella ruoli a 11 righe (`:51-55`), matrice «Chi può fare cosa» a 9 colonne (`:58-64`, colonne in `src/Demo.php:74`), con intestazioni che non vanno a capo (`public/assets/portale.css:196` `white-space:nowrap`). La prima colonna non resta ferma durante lo scorrimento laterale; `:42` ha `width:34%` fisso. La sezione «scegli il piano» (`:30`) sta in mezzo, prima di «Dove andare».
- **Proposta:** in alto solo titolo, due righe e i tre pulsanti «Entra come segreteria / socio / amministratore» con le credenziali; «Dove andare» e «I ruoli» dentro `<details>` chiusi («Cosa guardare nella demo», «Chi può fare cosa»); la matrice da telefono diventa un elenco per ruolo (un `<details>` per ruolo con le cose che può fare) oppure, come minimo, `.tbl th:first-child,.tbl td:first-child{position:sticky;left:0;background:var(--surface)}` e intestazioni che vanno a capo. I piani in fondo, dopo le istruzioni.

### C4 · Acquisto del gestionale (`views/saas_buy.php`)
- `:31` `<select id="plan">` e `views/saas_manage.php:58` senza classe: il CSS dà stile solo a `.toolbar select` e `select.field` (`portale.css:199,205`). Risultato: menu piccolo, non largo quanto gli altri campi, carattere sotto i 16 px → su iPhone la pagina si ingrandisce al tocco. Proposta: `class="field"` (o regola generale `select{…}`).
- `:44-45` righe dei pacchetti con `style` in linea e campo numerico da `6rem`; l'etichetta lunga va a capo male a 360 px.
- `:50-52`: il prezzo si vede solo dopo «Calcola il totale» (ricarica la pagina) e solo allora compaiono i dati da scrivere: due passaggi dove ne basta uno. Mostrare subito il totale del piano arrivato da `?plan=` (la pagina lo calcola già in GET, `src/App.php:755-762`: verificare che il riepilogo compaia; il pulsante «Calcola il totale» resta comunque sopra il riepilogo).
- `:53` riepilogo dentro un riquadro `.summary` che contiene altri `.summary` (riquadri annidati con lo stesso sfondo).
- `:66-68` «Indirizzo del gestionale» senza `inputmode`/`pattern`; l'errore arriva dopo l'invio (`src/SaasCheckout.php:81`).
- `:70,72` mancano i `placeholder`/aiuti presenti in `buy.php:22-24`; manca `autocomplete="organization"` su `org_name` (`:65`).
- `:81` e `views/buy.php:34`: accanto a «Ordina e paga» non c'è nessun link a termini, privacy e diritto di recesso (`src/Legal.php:149`). Proposta sotto il pulsante: `<p class="hint">Ordinando accetti i <a href="/termini">termini del servizio</a> e l'<a href="/privacy">informativa sulla privacy</a>. Hai 14 giorni per ripensarci.</p>`.
- `:91-95`: elenco «Cosa succede dopo» senza icone, diverso da `buy.php:40-44`.
- `views/buy.php:26`: il dominio non viene precompilato se l'utente arriva da un account o da `?domain=` (lo fa solo `free.php:13`).

### C5 · Codici promo del plugin senza campo
- **Dove:** `views/wordpress-admin/promos.php:9` «Il cliente scrive il codice all'acquisto»; in `views/buy.php:18-35` e in `src/Billing.php:127` (`startPaid`) non c'è nessun campo o parametro promo.
- **Proposta:** aggiungere il campo «Codice promozionale (facoltativo)» come in `saas_buy.php:48-49`, oppure togliere la frase dal pannello finché non c'è.

### C6 · Indirizzi che danno 404 (dal vivo) e pagine raggiungibili solo sapendo l'indirizzo
- `/saas/prezzi` e `/wordpress/confronto`: previsti in `docs/CONFRONTO_PIANI.md:13-14,134` ma non esistono nel router (`src/Sections.php:12-16`, `src/App.php:82-372`). Nessuna vista del portale li collega (cercato in `portale/`, `supporto/`, `saas/`, `wordpress/`): arrivano da documenti o da altri sistemi. Proposta: rinvii 301 in `Sections::legacy()` → `/saas/prezzi` ⇒ `/prezzi#prezzi-saas`, `/wordpress/confronto` ⇒ `/confronto`.
- `/partner`: nessuna rotta e nessun riferimento nel codice letto; se è citato fuori (email, social, supporto) serve una pagina o un rinvio a `/`.
- `/plugin` è nel piè di pagina ma non in `/funzioni` né in home; `/wordpress/changelog` si raggiunge solo da `/wordpress` (`wordpress_section.php:6`); `/termini` non è collegato dai moduli d'acquisto (C4).
- `/twofactor`: unico indirizzo in inglese (`src/App.php:189`); non urgente.
- `/account/gestionale/{id}` esiste solo con `saas_v2.enabled` (`src/App.php:306`); il pulsante «Gestisci» lo rispetta (`account.php:49`): bene.

### C7 · Listino del gestionale: «—» senza prezzo e riquadro vuoto
- Dal vivo: in `/saas` i pacchetti hanno «—» al posto del prezzo. Nel codice la tabella (`views/_catalog_saas.php:29-41`) mostra solo incluso/limite/non incluso: i pacchetti aggiuntivi e il loro prezzo non sono previsti (in `saas_buy.php:42-46` c'è solo il nome, senza prezzo unitario). Un «—» in una colonna di prezzi si legge «gratis» o «non disponibile». Proposta: riga per pacchetto con «+ X € al mese» preso da `Catalog::price( $addon, 'month' )`; se il prezzo non c'è, «su richiesta».
- `_catalog_saas.php:3`: senza catalogo esce `<div class="panel">` fuori da `section`/`.wrap`: riquadro largo tutto lo schermo, attaccato ai bordi, in `/prezzi`, `/saas` e `/confronto`. Testo «saranno qui a breve» → in `/confronto` non mostrarlo affatto; altrove: «Il listino del gestionale online arriva a breve. Scrivici per un preventivo.»
- `_catalog_saas.php:12`: «Il prezzo cresce con i soci: nessun limite blocca la segreteria.» poco chiaro → «Paghi in base al numero di soci. Se crescete, il gestionale non si ferma: cambia solo la fascia di prezzo.»
- `_catalog_saas.php:37`: celle «limite» con testo libero nella tabella a più colonne: a 360 px le colonne dei piani restano strette (nessuna vista «un piano per volta» come nel confronto del plugin, `public/assets/confronto.js:73-81`).

### C8 · Passaggi che non portano avanti
- `views/home.php:27`: le sei schede sono tutte link a `/funzioni` con la stessa etichetta «Scopri le funzioni»; `/funzioni` (`features.php:4-6`) mostra **le stesse sei schede con lo stesso testo**. Sei clic possibili, nessuna informazione in più. Proposta: in `/funzioni` ogni scheda apre il dettaglio (le righe della sua area nel confronto, già scritte in `content/confronto.md`); in home l'etichetta diventa specifica («Vedi il libro soci →») con ancora `#soci`.
- `views/pricing.php:79` «Inizia gratis» e fascia `src/Site.php:83` «Ottieni la licenza gratuita» nella pagina prezzi: chi voleva comprare il Pro viene spinto due volte verso il gratuito (anche la barra da telefono, `Site.php:107-108`, mette «Licenza gratuita» come pulsante principale).
- `views/confronto.php:40`: «Scegli» porta sempre al periodo annuale; chi vuole il mensile deve tornare a `/prezzi`. In `buy.php` manca il cambio mensile/annuale (solo «Confronta di nuovo i piani», `:45`).
- `src/Site.php:16`: `/accedi` è tra le pagine di presentazione → sotto il modulo di accesso compaiono fascia promozionale e barra «Prova la demo · Prezzi». Anche in `/free` la barra fissa resta sopra il modulo mentre si scrive (`portale.js:121-125` la nasconde solo quando la fascia è visibile). Proposta: niente barra in `/free` e `/accedi`.
- `views/free.php:12-13`: il sito WordPress è obbligatorio; chi non ha ancora il sito (o sta valutando) non può proseguire e non c'è scritto cosa fare → sotto il campo: «Non hai ancora un sito? Guarda il <a href="/saas">gestionale online</a>, che non ne ha bisogno.»
- `src/App.php:185-186` `/grazie`: stesso testo per plugin e gestionale, nessun passo successivo (come si installa, dove si scarica). Aggiungere tre passi numerati e il pulsante «Vai al tuo account».
- Dopo `/account/dominio`, `/account/annulla`, `/account/prova` (`src/App.php:338-346`) si torna all'account senza messaggio di conferma (gli altri lo hanno: `:324,334,355,359`). Aggiungere: «Dominio aggiornato.», «Rinnovo disdetto: la licenza vale fino al {data}.», «Prova attivata: la chiave di prova è qui sotto.»

## C · Miglioria (telefono, 360–390 px)

| Dove | Problema | Proposta |
|---|---|---|
| `portale.css:30-31` | h1 minimo 2,2rem (≈35 px) e h2 1,7rem; la regola di `docs/GRAFICA_UX.md` §1 dice titolo 24/30 e sezione 17/24 sul telefono. Il titolo della home occupa 5–6 righe a 360 px | `h1{font-size:clamp(1.5rem,5.2vw,3.6rem)}`, `h2{font-size:clamp(1.25rem,3.4vw,2.3rem)}` |
| `portale.css:26` | testo base 16,5 px contro i 15/22 della regola | allineare o aggiornare la regola |
| `portale.css:39` | intestazione alta 66 px (regola: non oltre 56) e fissa in alto: con la barra in basso da 84 px (`:305`) restano circa 490 px utili su 640 | 56 px sotto 860 px |
| `portale.css:135,157,99` | riquadri con 32/28/30 px di margine interno anche da telefono: a 360 px restano 256 px per i campi | `@media(max-width:480px){.panel,.sub{padding:20px}.plan{padding:22px 18px}}` |
| `portale.css:309` | link del piè di pagina alti circa 36 px (11 link fitti) | `padding:10px 0` (44 px) e raggruppamento |
| `portale.css:244` | pulsante «?» dei fumetti 22×22 px; nuvoletta `left:-8px` larga `min(340px,78vw)` che può uscire dallo schermo (solo pagine staff: `prices.php`, `payments.php`, `cookies.php`) | area di tocco 44 px; nuvoletta ancorata a destra quando serve |
| `portale.css:234` | schede secondarie dello staff alte circa 39 px | `min-height:44px` |
| `portale.css:96,73,159,196,284-286` | testi molto piccoli: «-20%» 11,5 px, pillole 12 px, etichette di stato 12,5 px, intestazioni di tabella 12 px maiuscole, tappe del percorso 12,5 px | minimo 13 px (regola: dettagli 13/18) |
| `portale.css:222-229` + `layout.php:72-74` | banner cookie: tre pulsanti a tutta larghezza impilati + testo ≈ 300 px, sopra la barra in basso: al primo ingresso copre mezzo schermo e i pulsanti della promessa | due pulsanti affiancati («Rifiuta», «Accetta tutti») e «Personalizza» come link sotto, stesso peso visivo |
| `layout.php:36-43` | il menu `<details>` non si chiude toccando fuori né con Esc (nessuno script in `portale.js`) | chiusura al tocco esterno e con Esc |
| `portale.css:42-46,259-264` | tra 861 e circa 1000 px la barra ha logo + 3 voci + «Gestione» + 2 pulsanti + «Il mio account»: probabile sovrapposizione o a capo (da verificare a 900 px con staff connesso) | passare al menu compatto sotto 1024 px, come dice `GRAFICA_UX` §2 |
| `confronto.js:74-75` | selettore del piano da telefono: tre pulsanti da ≈100 px con carattere 13 px; un nome come «Contabilità & Fiscale» va su tre righe o esce | nome breve nel selettore (`short` in `data-cmp`) |
| `views/confronto.php:117-118`, `content/confronto.md` | senza JavaScript: tabelle a 4 colonne con intestazioni che non vanno a capo e prima colonna lunghissima: scorrimento laterale su tutte le 9 tabelle | `white-space:normal` sulle intestazioni e prima colonna `min-width:11rem` |
| `views/features.php:10`, `_plans.php:30`, `confronto.php:133` | `confronto.js` caricato senza `?v=` (gli altri file lo hanno, `layout.php:23,79`): dopo un rilascio i telefoni tengono la versione vecchia | aggiungere `?v=` |
| `_plans.php:30` | lo script è incluso due volte nella pagina se il riepilogo piani compare con altre viste che lo caricano (in `/prova` una volta sola: bene) | spostarlo in `layout.php` |
| `portale.js:16-17` | «Copia»: se `navigator.clipboard` manca non succede nulla e nessun messaggio | selezionare il testo e scrivere «Tieni premuto per copiare» |
| `views/account.php:145-146`, `buy.php:26`, `free.php:13`, `saas_manage.php:34` | campi dominio: `type="text"` senza `inputmode="url"` (solo `saas_manage` lo ha) e senza `autocomplete`; `account.php:146` senza `spellcheck="false"` | `inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false"` |
| `views/saas_buy.php:49` | codice promo: `autocapitalize="characters"` bene; manca `autocomplete="off"` | aggiungerlo |
| `views/account.php:19,30-32` | bene (`autocomplete` giusti) | — |
| `views/twofactor.php:10`, `profile.php:46,54` | `maxlength="12"` su un codice a 6 cifre; manca `pattern="[0-9 ]*"` | `maxlength="7" pattern="[0-9 ]{6,7}"` come `mfa_verify.php:8` |
| `views/demo.php:20-21` | riga con etichetta + credenziale + «Copia»: l'email `amministratore@demo.invalid` va a capo dentro il riquadro a 360 px (ammesso da `word-break`), ma la password «Amministratore-Demo-2026» spezza a metà | etichetta sopra, credenziale e pulsante sotto |
| `views/home.php:13-20` | finta schermata `aria-hidden` sotto la promessa: da telefono allunga la prima pagina di ≈380 px prima dei contenuti | nasconderla sotto 860 px |
| `views/layout.php:28` | due immagini del logo con dimensioni dichiarate: bene. Nessun'altra immagine nel portale | — |
| `views/wordpress-admin/catalog.php:45-54`, `licenses.php:10`, `dashboard.php:23` | tabelle staff a 6–7 colonne (e una matrice con menu e campo in ogni cella): solo scorrimento laterale | accettabile per lo staff; almeno prima colonna fissa |

---

## Note sul metodo
- Letti per intero: tutte le viste pubbliche e dell'area clienti, `views/wordpress-admin/*` (in parte per estratti: `payments.php`, `prices.php`, `settings.php`, `releases.php`, `security.php`, `staff.php`, `utenti.php`, `wega.php`), `content/*.md`, `src/App.php`, `Site.php`, `Sections.php`, `Emails.php`, `Settings.php`, `Auth.php`, `Legal.php`, `Demo.php`, `SaasAccount.php`, parti di `Billing.php`, `PlanChange.php`, `SaasCheckout.php`, `public/assets/portale.css`, `portale.js`, `confronto.js`, `docs/GRAFICA_UX.md`, estratti di `docs/PORTALE.md` e `docs/CONFRONTO_PIANI.md`.
- `views/admin/` non esiste: l'amministrazione è tutta in `views/wordpress-admin/`.
- Non verificato in un browser: tutte le misure in pixel della parte C sono calcolate dal CSS, tranne i fatti «dal vivo».
- I testi legali proposti (IVA, recesso, responsabile del trattamento) vanno confermati da un consulente.
