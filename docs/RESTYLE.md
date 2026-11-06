# Restyle del plugin: piano

Piano per portare nel plugin WordPress (gratuito e Pro) lo stile del gestionale SaaS (`saas/docs/DESIGN.md`, `saas/resources/css/app.css`), per quanto WordPress lo permette. **È solo un piano: nessun file del plugin è stato toccato. Si implementa dopo l'approvazione del titolare.**

Stato di partenza: gratuito 1.3.15, Pro 1.3.6 (API 18), pubblicati.

## 1. In breve

- Il plugin ha **due superfici** con regole diverse. Dentro **wp-admin** si ridisegnano solo i contenuti delle pagine (`.wrap.asem`); menu, barra e colori di WordPress restano. Sul **sito** (area riservata, blocchi, shortcode) lo stile si adotta quasi per intero, ma il tema può sovrascrivere caratteri e margini.
- Il lavoro è quasi tutto **CSS con variabili** (prefisso `--asem-` in admin, `--asemf-` sul sito) più qualche piccolo helper PHP per i pezzi che il CSS da solo non sa fare (avatar con iniziali, icone, badge, fogli).
- **Nessuna rottura**: il nuovo aspetto nasce dietro un interruttore (**Impostazioni → Aspetto → «Nuovo aspetto»**), spento per chi ha già il plugin finché non è completo; poi si accende per tutti per un rilascio e le vecchie regole restano un'altra versione come ripiego.
- **Quattro rilasci del gratuito (1.3.16 → 1.3.19) e due del Pro (1.3.7, 1.3.8)**, un solo numero aperto alla volta. Dettaglio al §6.
- Vale per i due plugin insieme: il CSS è nel gratuito e il Pro lo eredita. Il Pro si rilascia solo quando cambiano file elencati in `tests/pro-files.txt` (pagine Conti, Rendiconto, Pagamenti, Cassa di gruppo…).

## 2. Cosa è fattibile in WordPress e cosa no

| Elemento dello stile SaaS | Esito | Come / perché |
|---|---|---|
| Colori, raggi (12/16/24), niente ombre né sfumature, tipografia 24/17/15/13, campi a 16 px e 48 px, tocchi da 44 px | **Sì** | CSS sotto `.asem-ui` (admin) e `.asemf-ui` (sito). Si vince sulla specificità di WordPress (`.button`, `.widefat`, `.form-table`) con selettori sotto il nostro contenitore; mai regole globali. |
| Montserrat | **Sì** in admin e nelle parti con il marchio (tessera) | File ospitati nel plugin, in **woff2 ridotto al solo latino** (≈ 20–30 KB ciascuno invece dei 450 KB dei TTF del SaaS), licenza OFL da citare in `docs/CREDITI.md` e `readme.txt`. Sul sito il carattere del tema resta il predefinito (vedi decisione D2). |
| Icone Lucide | **Sì** | SVG in linea, solo quelle usate, generate da un helper (`Icons::svg('nome')`) con tratto 1,75; `aria-hidden`, sempre con la parola accanto. Nessuna richiesta esterna. Il filtro `Html::allowed()` va esteso a `svg`, `path`, `circle`… e controllato con `wp_kses`. |
| Elenchi con avatar al posto delle tabelle sul telefono | **Sì, con markup nuovo** | La tabella-a-schede di oggi (`asem-cards`) si estende, ma l'avatar con iniziali e la riga «titolo / dettaglio / stato» chiedono un piccolo helper PHP (`Ui::list_row()`). Da 1024 px resta la tabella (la stessa pagina, due viste). |
| Un riquadro con molte righe separate da una linea; moduli a sezioni | **Sì** | Classi `.asem-group` e `.asem-section` al posto dei 110 `asem-card` ripetuti; i `form-table` restano nel markup ma sul telefono diventano campo-sotto-etichetta (già in parte oggi). |
| Foglio dal basso («Altro») per le azioni secondarie | **Sì, solo dentro la pagina** | Elemento `<dialog>` + poche righe in `admin.js`/`frontend.js`; senza JavaScript le azioni restano in vista come oggi. Movimento spento con `prefers-reduced-motion`. |
| Barra in basso con voci e pulsante **Nuovo**, barra laterale nuova, intestazione propria | **No in wp-admin** | Menu e barra di WordPress non si toccano (regola di progetto) e una barra fissa in basso si scontrerebbe con il menu mobile di WP. In admin si compensa con le schede in alto già a scorrimento (`asem-tabs`) rese più chiare e con i pulsanti principali ben visibili in cima. |
| Barra in basso dell'area soci (Tessera, Eventi, Prenota, Quote, Profilo) | **Sì, con cautela** | Sostituisce sul telefono la barra di salto `asemf-jump`. Rischi: footer fissi e banner cookie del tema. Si usa `position: fixed` con `env(safe-area-inset-bottom)`, spazio in fondo al contenitore e ripiego sulla barra attuale se il tema dichiara già un elemento fisso in basso (non rilevabile con certezza: interruttore in Aspetto). |
| Tessera come carta indaco con testo bianco e QR su pannello bianco | **Sì** | `.asemf-memcard` ha già colori da impostazioni; si aggiunge la variante «Carta» e si lascia la tessera su immagine propria (`CardLayout`) com'è. Stampa invariata. |
| Tema scuro | **Admin: no. Sito: isole coerenti** | WordPress non ha tema scuro. Sul sito i riquadri nuovi dichiarano da soli sfondo e testo (come la tessera), così restano leggibili su un tema chiaro o scuro; niente `prefers-color-scheme` automatico, che su un sito chiaro scurirebbe i riquadri. |
| Colore principale | **Admin: indaco fisso. Sito: a scelta** | Il front oggi segue il colore del tema/Elementor (`--asemf-accent`). Nel nuovo stile resta una scelta: «segui il sito» (come ora) oppure «indaco Associazione Semplice» (D1). In admin l'indaco è fisso, a prescindere dallo schema colori scelto dall'utente in WP. |
| Animazioni di ingresso, sfumature, ombre su riquadri | **Non si fanno** | Come nel SaaS. Ombra solo su fogli e barra in basso. |
| Tailwind, Node, build | **No** | CSS semplice a mano, un file per superficie, nessun passaggio di compilazione. |

**Contrasto: attenzione al salvia.** `#5F8579` su bianco fa circa 4,0:1: non va bene per il testo. Resta per riempimenti e icone (soglia 3:1); il testo «in regola» usa `#3D6256` su `#E6EFEB`, come nel SaaS. Il giallo `#EFCA5C` mai come colore di testo (testo `#6B4F00` su `#FCF3D3`). I valori vanno comunque verificati con un test, non a occhio (§4).

## 3. Come è fatto oggi (misure per stimare il lavoro)

- `assets/admin.css` 132 righe, `assets/frontend.css` 220 righe, `assets/verify.css` 12 righe. Nessun carattere ospitato.
- Admin: ~90 file in `includes/Admin/`, 310 pulsanti `.button`, 110 `asem-card`, 93 `widefat`, 61 `form-table`, **220 stili in linea in 58 file** (da portare nelle classi dove toccano l'aspetto).
- Le pagine usano gli stili di WordPress (`.button`, `.widefat`, `.form-table`, `.notice`, `.nav-tab`) più `Ui` (intestazione `Ui::header`, `Ui::notices`, `Ui::confirm_button`…). La struttura è `<div class="wrap asem">` ripetuta: un solo punto (`Ui::header`) per agganciare il nuovo contenitore.
- Sito: `Frontend/Views.php` (1476 righe) e `ProViews.php`, con `.asemf-section`, `.asemf-card`, `.asemf-list`, `.asemf-table`, la barra di salto, il menu laterale, la tessera. Colori dal tema via `Assets::inline_css()` e dalla pagina Aspetto.
- Il CSS è accodato in due punti soltanto (`Admin::assets`, `Frontend\Assets::enqueue`), più la pagina Aspetto (anteprima tessera).
- Il Pro **non ha CSS proprio**: usa quello del gratuito.

## 4. Metodo e verifiche comuni a ogni passo

1. **Si disegna a 390 px**, poi si controlla a **768** e **1280** (e 360 per il telefono stretto).
2. **Pagina di prova** `tests/ui-kit.html` (non entra nei pacchetti: `build.sh` toglie `tests/`) con tutte le classi (pulsanti, campi, badge, gruppo di righe, foglio, tessera, schermata vuota) per guardarle a tre larghezze senza avviare WordPress. Si apre nel riquadro del browser di Claude.
3. **Contrasto**: test PHPUnit nuovo (`tests/unit/UiContrastTest.php`) che calcola il rapporto per ogni coppia testo/sfondo dichiarata nei token e fallisce sotto 4,5:1 (testo) o 3:1 (icone e bordi dei campi). Nessun controllo a occhio.
4. **Tastiera**: giro con Tab e Maiusc+Tab su ogni schermata toccata; focus sempre visibile (`outline` 2 px indaco, offset 2); foglio apribile e chiudibile con tastiera (Esc), il focus torna al pulsante.
5. **Movimento**: con `prefers-reduced-motion` nessuna transizione (stesso blocco del SaaS).
6. **Regressioni**: `phpunit`, `tests/smoke*.php`, `phpcs`; **Plugin Check a zero errori e zero avvisi per gratuito e Pro** (si legge il log del job, non basta il verde). Cura speciale per: font (`@font-face` con URL del plugin), SVG in linea e `wp_kses`, uscite sempre protette.
7. **Prova su staging** (= demo, `associazionesemplice.it/demo`) con `update.sh` a ogni rilascio; mai `reset.sh` senza chiedere. Controllo con il tema del sito demo e con almeno un tema a blocchi e uno classico, e con Elementor per l'area soci.
8. **Compatibilità**: con l'interruttore spento l'HTML e il CSS visibili restano quelli di oggi (si confronta uno screenshot prima/dopo di cinque pagine campione). Con l'interruttore acceso, prima di tutto non si rompe niente di funzionale (moduli, `data-confirm`, `data-label`, ancore).
9. **Stesse regole di sicurezza**: ogni nuovo helper che stampa HTML esce con `esc_*`/`wp_kses`; nessun nuovo parametro in ingresso; nessuna richiesta esterna.

## 5. Passi

I passi sono in ordine; ogni passo è un blocco di lavoro con le proprie verifiche. Un **rilascio** (§6) può riunirne più d'uno.

### Passo 0: censimento e ripiego (mezza giornata)

- **File**: nuovo `docs/RESTYLE.md` (questo); un elenco temporaneo (nelle note, non nel repository) delle 220 occorrenze di `style=` da portare in classi.
- **Cosa cambia**: niente nel plugin. Si fissano 5 pagine campione per i confronti prima/dopo: Soci (elenco), Scheda socio, Cassa/incassi, Dashboard, Area soci (sito).
- **Rischio**: nessuno.
- **Verifica**: screenshot «prima» a 390/768/1280 delle 5 pagine, salvati fuori dal repository.

### Passo 1: token, carattere, icone, classi base (fondamenta)

- **File toccati**: `assets/admin.css` (blocco nuovo in testa, tutto sotto `.asem-ui`), `assets/frontend.css` (blocco nuovo sotto `.asemf-ui`), nuovi `assets/fonts/Montserrat-{Medium,SemiBold,Bold}.woff2` + `OFL.txt`, `includes/Icons.php` (SVG Lucide in linea), `includes/Admin/Ui.php` (helper `Ui::avatar`, `Ui::badge`, `Ui::icon`, `Ui::list_row`, `Ui::group_open/close`, `Ui::sheet`), `includes/Admin/Admin.php` (classe `asem-ui` sul contenitore se l'interruttore è acceso), `includes/Settings.php` + pagina Aspetto (interruttore), `includes/Html.php` (`svg`/`path` consentiti), `docs/CREDITI.md`, `readme.txt` (OFL).
- **Cosa cambia**: variabili `--asem-accent: #1F218C`, `--asem-ok/warn/danger` e relative `-soft`, `--asem-bg/ink/muted/line`, raggi `--asem-r-field: 12px`, `--asem-r-group: 16px`, `--asem-r-sheet: 24px`. Classi `.asem-btn` (e stile dei `.button` di WP **solo dentro** `.asem-ui`: primario pieno indaco, secondario con filetto, pericolo rosso), campi (48 px, 16 px, filetto, focus), `.asem-badge-ok|warn|danger|info|neutral`, `.asem-group` + `.asem-row`, `.asem-title/.asem-section/.asem-caption`, `.asem-empty`, `.asem-sheet`. Nulla di questo cambia l'aspetto finché l'interruttore è spento.
- **Rischio**: **medio**. WordPress ha regole forti sui campi e sui pulsanti: i selettori vanno scritti con la specificità giusta, mai con `!important` sparso. Rischio secondario: file font troppo pesanti o non caricati su alcuni host (`font-display: swap` e carattere di ripiego di sistema già nell'elenco).
- **Verifica**: `tests/ui-kit.html` a tre larghezze; test di contrasto; Tab sul kit; Plugin Check (font, SVG); interruttore spento = pagine identiche ai «prima».
- **Fattibile/no**: tutto fattibile; la barra e il menu di WP non cambiano.

### Passo 2: Soci (elenco e scheda), la schermata più usata

- **File**: `includes/Admin/PeoplePage.php` (elenco, scheda, import), `assets/admin.css`, `assets/admin.js` (ricerca immediata, foglio «Altro»), `includes/Admin/Ui.php`.
- **Cosa cambia**: sul telefono l'elenco diventa un gruppo di righe: avatar con iniziali (colore sempre lo stesso per la stessa persona, preso dai tre colori del marchio), nome, riga di dettaglio («n. 124 – Socio ordinario – email»), badge «In regola / Scaduta / Non iscritto / Sospeso» a destra con la parola e non solo il colore; da 1024 px resta la tabella con le stesse colonne e l'ordinamento attuale. Ricerca e filtri sopra («Tutti, Ordinari, Volontari, Ospiti» come chip a scorrimento), azioni secondarie (importa, esporta, sospendi scaduti) nel foglio «Altro»; **Nuovo socio** primario in vista. Scheda socio a sezioni (Dati, Tessera e quote, Famiglia, Contatti, Attività) con titoli di sezione e il pulsante **Salva** sempre raggiungibile. Schermata vuota che propone il passo successivo («Aggiungi il primo socio» / «Importa da Excel»).
- **Rischio**: **medio-alto**: è la pagina con più JavaScript e più casi (famiglia, ospiti, livelli, tessere). Il markup della tabella non si tocca per le colonne: si aggiunge, non si sostituisce, così `data-label`, ordinamento e `data-confirm` continuano a funzionare.
- **Verifica**: 390/768/1280; elenco con 0, 1 e 500 soci; nomi molto lunghi e senza email; tastiera: Tab nell'elenco, apertura del foglio con Invio, Esc; contrasto dei badge nel test; salvataggio di un socio nuovo e di uno esistente (nessun campo perso); filtri e ordinamento invariati.
- **Fattibile/no**: tutto, tranne «Nuovo» fisso in basso (D3: resta il pulsante in cima alla pagina).

### Passo 3: Incassi e Prima nota / Cassa

- **File**: `includes/Admin/IncomePage.php`, `LedgerPage.php`, `MoneyPage.php`, `ExpensePage.php`, (Pro: `AccountsPage.php`, `GroupCashPage.php`, `PaymentsPage.php`), `assets/admin.css`.
- **Cosa cambia**: movimenti come righe (descrizione, conto/categoria nel dettaglio, importo con `tabular-nums` a destra, rosso per le uscite **e** segno «−», non solo colore); modulo «Registra l'incasso» a sezioni (Chi paga, Quanto, Dove va); totali in testa come riquadro unico; filtri per periodo in un foglio; tabella da 1024 px. Frasi come «Registra l'incasso» e «Registra la spesa» al posto di «Salva».
- **Rischio**: **alto per i dati, basso per il codice**: sono le pagine con i numeri veri. Si cambia solo la presentazione; calcoli, IVA e `Money` non si toccano. Il rischio reale è un campo spostato che sparisce dall'invio del modulo.
- **Verifica**: stessi 3 controlli del passo 2 più: registrare un incasso e una spesa prima e dopo (stesso movimento in prima nota, stessi importi), importo con virgola, IVA attiva/spenta, esportazione invariata. Test unitari esistenti su `LedgerService` e `Money` devono passare senza modifiche.
- **Pro**: i file Pro (Conti, Cassa di gruppo, Pagamenti) cambiano solo nel markup → il Pro 1.3.7 esce in questo passo.

### Passo 4: Area riservata sul sito (soci)

- **File**: `assets/frontend.css`, `assets/frontend.js` (barra in basso con voce attiva, foglio), `includes/Frontend/Views.php` (struttura e barra), `ProViews.php`, `includes/Frontend/Assets.php` (variabili, tema), `includes/Admin/AppearancePage.php` (scelta colore, anteprima).
- **Cosa cambia**: area soci con **barra in basso a icone** (Tessera, Eventi, Prenota, Quote, Profilo, secondo cosa è disponibile), la tessera come carta indaco con testo bianco e **QR su pannello bianco** grande, ricevute e quote da pagare come elenco di righe, pulsanti da 44 px, campi da 48 px, avvisi senza colore soltanto. L'area oggi è **una pagina con sezioni** (ancore) e un menu laterale per i ruoli: la barra segue le sezioni con evidenziazione della voce attiva, non cambia pagina. Con più ruoli (volontario, segreteria) il menu laterale resta su schermo largo e diventa la voce «Altro» sul telefono.
- **Rischio**: **alto**: il tema può sovrascrivere caratteri, margini e `button`; un footer fisso o un banner cookie del tema può coprire la barra. Mitigazioni: tutto sotto `.asemf-ui`, `all: revert-layer` non usato (si dichiara ciò che serve), `padding-bottom` sul contenitore, interruttore «Barra in basso» (spegnibile, ripiega sulla barra di salto di oggi). I valori `--asemf-accent` del tema restano l'impostazione predefinita (D1).
- **Verifica**: area soci con i temi di prova (classico, a blocchi, uno scuro) e Elementor; 360/390/768/1280; telefono con tastiera aperta (la barra non deve coprire il campo); stampa della tessera invariata; Wallet e QR invariati; accesso con tastiera e screen reader (`nav` con nome, `aria-current`); utenti senza tessera valida.
- **Fattibile/no**: la barra è fattibile ma dipende dal tema; sul sito il carattere resta quello del tema salvo scelta (D2).

### Passo 5: Blocchi, shortcode e pagine pubbliche

- **File**: `assets/frontend.css`, `Frontend/Views.php` (`attivita`, `prossimi_eventi`, `accesso`), `assets/verify.css` (pagina di verifica tessera), `assets/blocks.js` (anteprima nell'editor).
- **Cosa cambia**: attività ed eventi come righe/schede con data, luogo, posti e pulsante «Prenota»; accesso e verifica in stile coerente; schermate vuote che dicono cosa fare.
- **Rischio**: medio-alto per l'impatto sui temi; le pagine sono spesso incorporate in layout altrui.
- **Verifica**: come passo 4; l'anteprima nell'editor a blocchi e in Elementor.

### Passo 6: Il resto dell'amministrazione

- **File**: `ActivitiesPage.php`, `ResourcesPage.php`, `BookDeskPage.php`, `CalendarPage.php`, `DashboardPage.php`, `CertificatesPage.php`, `AffiliationsPage.php`, `AssembliesPage.php`, `DocumentsPage.php`, `ReportsPage.php`, `AccountingPage.php`, `LimitsPage.php`, impostazioni, procedure guidate (`ProWizard.php` e configurazione guidata), `admin.css`.
- **Cosa cambia**: stessi componenti dei passi 2–3 (elenchi al posto delle tabelle sul telefono, moduli a sezioni, azioni secondarie nel foglio, schermate vuote, badge). Cruscotto (`DashboardPage`) come **elenco di cose da fare** (domande da esaminare, tessere e certificati in scadenza, pagamenti da controllare). I limiti di legge restano fissi, i limiti operativi si leggono da `Limits` e non si scrivono nei testi.
- **Rischio**: medio, ma ripetitivo e su molte pagine. Si procede a gruppi di 4–6 pagine con prima/dopo.
- **Verifica**: come passo 2, per ogni pagina; si rilegge `docs/PLUGIN_WORDPRESS.md`, la guida e le FAQ del Supporto per i testi cambiati.
- **Pro**: file Pro toccati (Rendiconto, Report, Cassa) → Pro 1.3.8.

### Passo 7: Accensione per tutti e pulizia

- **File**: `Settings.php` (predefinito acceso), `admin.css`/`frontend.css`, documentazione.
- **Cosa cambia**: per le installazioni nuove l'interruttore nasce acceso; per le esistenti si propone con un avviso dismissibile («Nuovo aspetto disponibile, prova») e un rilascio dopo diventa predefinito. Si tolgono gli stili in linea rimasti e le regole morte. Il ripiego resta spegnibile da Aspetto per una versione e poi si valuta di toglierlo.
- **Rischio**: basso (è già stato provato per tre rilasci), ma è il momento in cui chi non ha seguito lo vede: serve un avviso chiaro e il modo di tornare indietro.
- **Verifica**: aggiornamento da 1.3.18 con dati reali di una copia; interruttore spento e acceso; backup/ripristino invariati.

## 6. Rilasci proposti

Un solo numero aperto per volta; ogni rilascio segue il flusso del progetto (`bump.sh`, PR, CI verde, Plugin Check a zero avvisi per gratuito **e** Pro, zip, cartella principale su `main`, file TEST su OneDrive, demo aggiornata con `update.sh` e **mai** `reset.sh` senza chiedere; la pubblicazione sul Portale solo con un «sì» scritto).

| Rilascio | Contenuto | Visibile? | Pro |
|---|---|---|---|
| **Gratuito 1.3.16** | Passi 1 + 2: fondamenta, interruttore, Soci | solo con l'interruttore acceso (spento di default) | nessun rilascio (il Pro eredita il CSS) |
| **Gratuito 1.3.17 + Pro 1.3.7** | Passo 3 (incassi e prima nota) + passo 4 (area riservata) | interruttore | Pro 1.3.7 per le pagine Pro dei soldi |
| **Gratuito 1.3.18 + Pro 1.3.8** | Passi 5 e 6 (blocchi, resto dell'amministrazione) | interruttore | Pro 1.3.8 per Conti, Rendiconto, report |
| **Gratuito 1.3.19** | Passo 7: acceso per tutti, pulizia | sì, per tutti | solo se serve |

Stima di impegno: 1.3.16 la più lunga (fondamenta + kit di prova), 1.3.17 la più delicata (soldi + tema), 1.3.18 la più lunga per numero di pagine, 1.3.19 breve. Se il titolare preferisce meno rilasci si può unire 1.3.18 e 1.3.19 a costo di accendere il nuovo aspetto con meno prove.

Se in un rilascio nel mezzo spunta un nuovo helper in `Ui` usato da file Pro, si valuta il numero di **API** del gratuito richiesto dal Pro (oggi 18): un Pro nuovo su un gratuito vecchio non deve rompersi, al massimo mostrare l'aspetto di prima.

## 7. Cosa si comunica alle altre sessioni

A ogni rilascio che cambia **regole** (non solo aspetto): Portale, Supporto e SaaS vanno avvisate. Per il restyle ci sono tre punti da dire:

- **Supporto**: guide, FAQ e tabella di confronto citano schermate e nomi di pulsanti; i testi nuovi («Registra l'incasso», «Altro», la barra dell'area soci) cambiano le istruzioni e le immagini. Servono le schermate nuove a 390 px.
- **Portale**: nessuna dipendenza, ma la scheda del plugin e le immagini (screenshot) cambiano; il testo di «novità» si scrive dal Portale.
- **SaaS**: stessa lingua visiva, nessun codice condiviso; i token restano duplicati (SaaS in Tailwind, plugin in CSS semplice) e un cambio di colore va fatto in entrambi. Si propone un breve elenco dei token comuni nella documentazione per non farli divergere.

## 8. Decisioni per il titolare

1. **D1. Colore sul sito.** Il front oggi segue il colore principale del tema (`--asemf-accent`). Con il nuovo stile: **(a)** resta «segui il sito» di default e l'indaco è una scelta in Aspetto; **(b)** indaco di default per le installazioni nuove, «segui il sito» per quelle esistenti. *Proposta: (b).*
2. **D2. Montserrat sul sito.** Il tema decide il carattere; imporre il nostro dà coerenza di marchio ma cozza con temi che hanno già il loro. *Proposta: sul sito Montserrat solo su tessera e titoli del nostro riquadro; il corpo del testo resta quello del tema. In admin sempre Montserrat.*
3. **D3. Pulsante «Nuovo» e barra in basso in wp-admin.** Non fattibili senza toccare menu e barra di WordPress. *Proposta: nessuna barra in admin; «Nuovo…» primario in cima a ogni elenco.*
4. **D4. Interruttore e tempi.** *Proposta:* nuovo aspetto spento di default fino a 1.3.18, acceso per tutti in 1.3.19 con possibilità di tornare indietro per una versione.
5. **D5. Una pagina alla volta o tutto insieme.** *Proposta:* come nel §6, con Soci e Incassi per primi perché sono i gesti di ogni giorno, e l'area riservata subito dopo.
6. **D6. Dimensione del font.** woff2 ridotto al latino (Medium, SemiBold, Bold): ≈ 80 KB in tutto nel pacchetto. Va bene per WordPress.org? *Proposta: sì; OFL ammessa, crediti in `readme.txt`.*

## 9. Cosa è stato scartato

- **Barra in basso e barra laterale in wp-admin**: si scontrano con menu e barra di WordPress, che non si cambiano.
- **Tailwind o Node nel plugin**: due strumenti da mantenere per un plugin che oggi non ne ha nessuno; il CSS semplice con variabili basta.
- **Rifare tutte le pagine in un solo rilascio**: troppo rischio sui soldi e sul tema del sito; meglio l'interruttore e i passi.
- **Caricare Montserrat da Google Fonts**: richieste esterne e dati verso terzi; si ospita nel plugin.
- **Tema scuro automatico sul sito**: su un sito chiaro scurirebbe i riquadri a caso.
