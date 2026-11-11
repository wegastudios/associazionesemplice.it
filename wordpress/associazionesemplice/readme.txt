=== Associazione Semplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.19
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Members, events and courses, and a cash book for small Italian non-profit associations (APS and other third-sector entities).

== Description ==

Associazione Semplice helps a small association keep members, activities and accounts in order directly from its own WordPress site. Optional features are off at first: a guided setup asks a few questions and turns on only what is needed.

The plugin is built around Italian third-sector rules (APS, ETS, registers, tax rules), so its interface and texts are in Italian. An English text pack is included and can be selected in the settings, and further language packs can be uploaded as CSV, Excel or JSON files; the plugin does not use the WordPress translation files.

* **Member register**: member records, one membership fee (with an option for founding members), QR membership cards, guests, import from CSV or Excel files, export of the register.
* **Courses and events**: registrations, limited seats with a waiting list, a payment grace period for bank transfer and cash, participant lists, a calendar.
* **Cash book**: income and expenses in a single cash account, bank transfer details for members, export of the cash book.
* **Registers and privacy**: minutes book, privacy notice, consents, anonymisation, personal data download.
* **Members' area** on the site, with notices, the rules to accept, the card and the events and courses, through shortcodes, blocks and Elementor widgets.
* **Roles**: president, vice president, treasurer, councillors and secretary, with separate permissions.
* **Donations with PayPal**: a form that takes the donor to PayPal's own donation page.
* **Backup and reset**: download a full copy of the data; reset the data to start over.
* Works with WP All Import and Elementor if you use them.

Advanced features (online payments, several accounts and funds, accounting and reports, mass emails, app and wallet cards, receipts, VAT) are in the separate Associazione Semplice Pro add-on.

== External services ==

The plugin works fully on its own and contacts no external service by default. The optional features that involve a third party are described below.

= PayPal donations (optional) =
If you turn on donations, your website shows a form whose button opens PayPal's donation page (www.paypal.com/donate) in the visitor's browser, with the PayPal account, the purpose and the chosen amount you set. Nothing is sent from your server to PayPal. Terms: https://www.paypal.com/legalhub - Privacy: https://www.paypal.com/privacy

= Google Places search (optional) =
If you turn on place search and enter your own Google Maps API key, the "Location" fields of courses and events in the administration screens load Google's script (maps.googleapis.com) and show place suggestions while you type. The text typed in the field is sent to Google from the administrator's browser; no member data is sent. Off by default. Terms: https://cloud.google.com/maps-platform/terms - Privacy: https://policies.google.com/privacy

== Installation ==

1. Upload the `associazionesemplice` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it from the Plugins screen.
3. Follow the guided setup that opens on first activation (it can be started again from the Tools menu).

== Frequently Asked Questions ==

= What happens to the data if I delete the plugin? =
By default it stays. In the settings you can choose to delete it permanently when the plugin is deleted.

= Can I import my existing member list? =
Yes, from a CSV or Excel file, with a preview before confirming.

= Where do the donations go? =
Straight to the PayPal account you enter in the settings. They are recorded in the cash book by hand.

== Credits ==

The interface uses the Montserrat typeface (SIL Open Font License 1.1, copy in `assets/fonts/OFL.txt`) and icons from Lucide (ISC license, https://lucide.dev), both bundled with the plugin; nothing is loaded from external servers.

== Changelog ==

= 1.3.19 =
* Eventi e corsi hanno una pagina pubblica sul sito, collegata all'attività e creata da sola: scrivi la descrizione e scegli la locandina, data, luogo, contributo e posti li prende dall'attività
* Dalla pagina dell'evento i soci si prenotano e possono salvare le date nel proprio calendario
* Nuovo elenco degli eventi a schede, per mese, con data in evidenza e locandina: inseriscilo con lo shortcode [associazionesemplice_eventi], con il blocco «Associazione Semplice» o con Elementor
* La segreteria può modificare le pagine degli eventi e caricare le locandine senza toccare gli altri contenuti del sito

= 1.3.18 =
* Accesso soci più semplice: una sola schermata con password o link via email
* Con la password puoi spuntare «Ricordami» e restare entrato 30 giorni
* Con il link o il codice l'accesso dura meno (12 ore, si cambia dai Limiti)
* La vecchia grafica è stata tolta: resta solo il nuovo aspetto e l'opzione «Torna alla grafica precedente» non c'è più
* Il programma si aggiorna da Bacheca → Aggiornamenti: con «Visualizza i dettagli» vedi in italiano cosa cambia prima di installare

= 1.3.17 =
* Più sicurezza dopo una revisione: collegamenti tra tutore e minore più rigorosi, il modulo del profilo accetta solo l'invio normale, la protezione della cartella degli allegati viene controllata, negli elenchi esportati le formule non vengono eseguite.

= 1.3.16 =
* Nuovo aspetto: elenchi a righe sul telefono, scheda socio a sezioni, foglio «Altro» nell'elenco soci, prima nota e incasso più leggibili, tessera a carta e barra in basso nell'area soci.
* Anche la Bacheca e le schermate vuote hanno il nuovo stile, con il carattere Montserrat incluso nel programma (niente richieste a siti esterni).
* Il link di attivazione abilita l'accesso ma non fa entrare da solo: il socio torna all'area riservata e accede.
* Più sicurezza: contenuti filtrati, link dei documenti protetti, copie di sicurezza più ordinate.
* Tolti gli shortcode brevi [convenzioni] e [convenzioni_soci]: usa quelli con il prefisso associazionesemplice_.

= 1.3.15 =
* Nel modulo «Prenota» di un evento, scelto il socio, la casella del pacchetto dice quale pacchetto ha e quanti ingressi gli restano. L'ingresso si scala già alla prenotazione.
* Eventi e corsi: i prezzi non sono più quattro fissi, ne aggiungi quanti servono (uno o più, senza limite).
* Da telefono i moduli nelle schede (conti, attività) vanno su una colonna e non escono più dalla scheda.
* Negli eventi ricorrenti la scheda diventa «Aggiungi o elimina date»: se un giorno è di festa, elimini quella data dall'elenco. Il pulsante «Annulla solo questa data» resta anche nella riga della data.
* Nella pagina di un evento il riepilogo sta prima del calendario. Il calendario ha due viste, mese (di partenza) e giorno; nella vista giorno scegli la data con il selettore, scritta come 06/11/2026. La vista settimana è tolta.

= 1.3.14 =
* Testi più chiari nel calendario dell'evento, nei pacchetti e nella commissione di incasso dei conti.

= 1.3.13 =
* Nella pagina di un evento c'è un calendario con le date, da vedere per mese o per giorno. Toccando una data si aprono le sue prenotazioni.
* La segreteria vede chi ha un pacchetto con ingressi ancora da usare, e quanti ne restano: nella pagina dell'evento e, per la persona scelta, in Cassa → Prenota.
* Su un conto (per esempio un POS fisico) puoi indicare la commissione di incasso, fissa e in percentuale: a ogni incasso la spesa va in prima nota.
* Se non c'è nessun pagamento online, una risorsa da pagare in anticipo si prenota lo stesso e il contributo si salda in segreteria.
* Nel menu del sito la voce «Area riservata» non compare più due volte.

= 1.3.12 =
* Da telefono, nell'area soci la tabella delle attività diventa una scheda per riga (i pagamenti non restano fuori schermo) e il pulsante secondario ha il contorno

= 1.3.11 =
* Da telefono le schede in cima alle pagine di amministrazione stanno su una riga che scorre

= 1.3.10 =
* Nei corsi si può annullare una lezione e tutte le successive dello stesso giorno (le passate restano)

= 1.3.9 =
* Da telefono: la Cassa non ripete più i pulsanti e l'avviso di fine anno sta dopo le cifre; la Rubrica soci è a schede compatte

= 1.3.8 =
* Corretta la barra per saltare alle sezioni dell'area soci: non finisce più sotto l'intestazione fissa del tema

= 1.3.7 =
* Corretta la barra per saltare alle sezioni dell'area soci: da telefono non allarga più la pagina

= 1.3.6 =
* Area soci e app da telefono più comode: icona con l'iniziale, pagina offline con «Riprova», invito a installare l'app sotto la tessera, barra per saltare alle sezioni, pulsanti più grandi, pulsante «Vai alla tua area»

= 1.3.5 =
* Se un corso viene eliminato a metà mese, del mese già pagato si restituisce la quota delle lezioni non ancora tenute
* Nuovo pulsante «Non rinnova» nell'iscrizione a un corso: resta iscritto fino alla fine del periodo pagato e poi non deve più nulla, senza restituzioni

= 1.3.4 =
* Cancellando una singola iscrizione si restituisce o annulla solo il non fruito: gli incassi già fruiti restano in prima nota
* Per il fruito la pagina e il messaggio finale mostrano quanto manca (da incassare) o quanto avanza (da restituire a mano)

= 1.3.3 =
* Corretto l'ultimo avviso di Plugin Check (lettura del cookie di sessione dei soci): nessun cambiamento per chi usa il programma

= 1.3.2 =
* Se elimini un evento o un corso, le somme si restituiscono o si annullano solo per ciò che non è ancora stato fatto: le date e i mesi già svolti restano incassati
* Negli eventi ricorrenti puoi annullare solo una data oppure quella e le successive (le passate restano)

= 1.3.1 =
* Nella pagina di accesso di WordPress i soci vengono indirizzati all'area soci, senza proporre il primo accesso

= 1.3.0 =
* Versione 1.3: l'accesso dei soci senza utente WordPress è completo. I soci entrano con email e password (obbligatoria alla prima entrata), con recupero tramite link e, se preferiscono, con un link o un codice senza password. Gratuito e Pro escono insieme alla 1.3.0.
* Più sicurezza nell'accesso dei soci: i tentativi si contano senza che richieste in parallelo li aggirino, i codici sbagliati hanno un tetto per persona anche chiedendone di nuovi, il tempo di risposta non rivela più se un'email è di un socio (l'email parte dopo la risposta), per cambiare la password serve quella attuale, le password enormi sono rifiutate e chi richiede più volte il link non fa scadere quello già arrivato.
* Un testo iniettato per errore nelle pagine non può più dirottare un modulo né mandare il visitatore su un altro sito.
* Le pagine riservate ai soci e i dati «io» dell'applicazione riconoscono anche chi è entrato con l'accesso proprio.
* Lo staff di un evento con l'incasso abilitato può addebitare crediti con il QR anche se non ha un utente WordPress.
* Se un secondo ordine del negozio risulta pagato per lo stesso pagamento, la segreteria lo vede tra i pagamenti da controllare e il riferimento del primo incasso non viene perso.
* Più veloce: l'anagrafica ha un indice sull'email e la ricerca per cellulare non carica più tutto l'elenco dei soci.

= 1.2.52 =
* I soci entrano con email e password: la password si sceglie alla prima entrata ed è obbligatoria (finché non c'è, l'area riservata resta chiusa). Il modulo dell'area soci è quello con email e password.
* «Password dimenticata»: arriva per email un link che fa entrare ma obbliga a scegliere una nuova password prima di navigare. Cambiata la password, gli altri dispositivi devono rientrare.
* Chi preferisce può ancora entrare con un link o un codice via email, senza password.
* All'attivazione dal link della segreteria la password si sceglie subito.
* L'informativa privacy dichiara l'accesso all'area riservata e il cookie tecnico «asem_s»; nelle impostazioni (Comunicazioni e privacy) si possono elencare altri cookie del sito, che compaiono nell'informativa.

= 1.2.51 =
* L'accesso dei soci con un link o un codice via email (password facoltativa) è acceso di default: i nuovi soci non hanno più un utente WordPress. L'utente WordPress nasce solo quando a un socio si dà un potere (segreteria, tesoriere, staff). Chi ha già un utente WordPress continua a entrare da WordPress.
* Gli amministratori possono scegliere di creare comunque anche un utente WordPress per ogni socio (Impostazioni → Accesso dei soci, oppure la configurazione guidata, dove la scelta è spiegata con vantaggi e svantaggi e con una nota se c'è WooCommerce). A WordPress e a WooCommerce passano solo nome, cognome ed email.
* Quando a un socio si toglie l'ultimo potere, la sua scheda propone di farlo passare all'accesso con link: l'amministratore decide caso per caso e l'utente WordPress viene eliminato solo se non ha contenuti né ordini nel negozio.
* Le pagine dei soci, i moduli, i download (ricevute, tessera PDF, wallet), i pagamenti e le notifiche funzionano anche per chi entra con l'accesso proprio. Resta spento: finché non lo accendi dai Limiti non cambia nulla.

= 1.2.50 =
* Predisposto l'accesso dei soci senza bisogno di un utente WordPress: entrano con un link o un codice che arriva per email, oppure con una password facoltativa. È spento: finché l'amministratore non lo accende dai Limiti («Accesso dei soci indipendente da WordPress») non cambia nulla e i soci entrano come prima.
* Quando sarà acceso, il sito userà un cookie tecnico di sessione («asem_s») e scriverà ai soci per mandare il link di accesso.

= 1.2.49 =
* Nel modulo della nuova spesa il beneficiario si sceglie con più facilità e il programma può proporre l'attribuzione ai fondi.

= 1.2.48 =
* Gli sport del menu delle attività li gestisce l'amministratore: aggiunge, importa un elenco (uno per riga), toglie dal menu e ripristina l'elenco di base.
* Un'attività accetta qualsiasi sport scritto in modo sensato, non solo quelli dell'elenco.

= 1.2.47 =
* Il mese di inizio dell'anno fiscale si può scegliere separato da quello dell'anno sociale (Pro Fiscale).

= 1.2.46 =
* Nell'elenco degli sport sono stati aggiunti Scacchi, Tiro dinamico sportivo e Turismo equestre.

= 1.2.45 =
* Le attività hanno lo sport (elenco CONI) e la disciplina dell'ente, a testo libero.
* L'elenco degli enti per le affiliazioni comprende CONI/RAS e tutti gli enti di promozione riconosciuti dal CONI.

= 1.2.44 =
* Varianti del contributo dei corsi: descrivi le varianti (nome e prezzo, ad esempio «Secondo corso», «Famiglia», «Solo il sabato») nel corso e assegnale all'iscrizione con una nota libera: la quota dell'iscritto segue la variante.
* Chi si iscrive da solo paga la quota base.

= 1.2.43 =
* Le singole lezioni di un corso si possono annullare o spostare (festività, maltempo), con avviso agli iscritti.
* Nuovo pagamento del corso trimestrale: i promemoria mensili saltano i corsi trimestrali.

= 1.2.42 =
* Liberatoria per foto e video nella scheda e nel modulo di domanda online (per i minori risponde il tutore che opera).
* Tipo di tesseramento (atleta, istruttore, dirigente…) nella scheda e nell'esportazione dei soci.
* Nuovo avviso alla segreteria «Schede da completare» in Bacheca e in «Da lavorare» di WordPress.

= 1.2.41 =
* Il libro soci è completo: data della domanda e della delibera, luogo di nascita, residenza, contatti, ultima quota, recesso e tutore (PDF e CSV).
* Fino a due genitori o tutori per ogni minore, anche non soci e senza email: sul sito opera uno solo, il primo.
* Il modulo di domanda online chiede anche luogo di nascita e residenza.
* Nei corsi: «Salva e prossimo inserimento» e «Iscrivi e vai alla cassa».
* Nell'elenco delle attività c'è il collegamento «Riproponi».

= 1.2.40 =
* Pulizia interna del codice dei pagamenti per la revisione di WordPress.org: nessun cambiamento d'uso.

= 1.2.39 =
* Pulizia interna del codice per la revisione di WordPress.org: le pagine si comportano come prima.

= 1.2.38 =
* A 18 anni i permessi del tutore si spengono da soli, anche se il ragazzo non ha ancora un accesso suo: il tutore non lo vede più, non paga per lui e non riceve più le sue email.

= 1.2.37 =
* L'indirizzo del visitatore dietro Cloudflare si legge solo se la richiesta arriva davvero da Cloudflare.
* Il link «torna alla pagina» dopo il login usa sempre l'indirizzo del sito.
* La ricevuta mandata per email non lascia più un PDF dal nome prevedibile.
* Il rendiconto di un ospite lo vedono l'ospite, il suo tutore e la segreteria, non chi lo ospita.
* Lo staff dell'ente manda avvisi solo agli eventi di cui è staff.
* Un socio con la stessa email di un utente del sito con altri ruoli lo collega solo un amministratore.
* In Pagamenti compare un avviso se le chiavi di sicurezza di WordPress mancano o sono quelle di esempio.

= 1.2.36 =
* Dopo un ripristino di fabbrica la configurazione guidata riprende le pagine già presenti invece di crearne di doppie.
* Nella scheda del socio il testo dice «tessera valida fino al 31 dicembre».

= 1.2.35 =
* Il livello della licenza viene dalla prova firmata dal portale, non dal campo salvato
* Un livello o uno stato di licenza sconosciuto non sblocca più le funzioni

= 1.2.34 =
* Più sicurezza: la copia di sicurezza non contiene più la chiave di licenza né i codici dei QR e del calendario, e il file temporaneo si cancella sempre
* La segreteria non può cambiare l'email di accesso di tesoriere, presidente e staff
* Nel voto segreto i risultati e l'elenco di chi ha votato si vedono solo a votazione chiusa
* Una votazione con voti non si può riaprire dopo la chiusura
* I termini personalizzati non accettano più HTML

= 1.2.33 =
* Nella configurazione guidata, scegliendo «Chiunque può chiedere di iscriversi» si accende anche la domanda di adesione online (pagina «Diventa socio»).

= 1.2.32 =
* Su schermi stretti i campi lunghi delle impostazioni non escono più dalla pagina.
* Gli avvisi dicono «1 avviso inviato» al singolare.

= 1.2.31 =
* Aggiornamento di compatibilità con il Pro 1.1.28: aggiorna insieme gratuito e Pro.

= 1.2.30 =
* Nuove convenzioni per i soci (Registri → Convenzioni): sconti dei partner con descrizione, validità, codice, logo e a chi si rivolgono. I soci con tessera valida le vedono nell'area riservata; un elenco pubblico è facoltativo.
* Pulsante «Avvisa i soci» per ogni convenzione, scheda «Convenzioni in scadenza» in Bacheca ed esportazione CSV.
* La quota associativa si può versare a rate: vedi quanto manca e la tessera arriva quando la quota è saldata (si cambia da Limiti e soglie).
* Il saldo si incassa dalla scheda del socio o da «Pagamenti da incassare» con l'importo già pronto.

= 1.2.29 =
* Aggiornamento di compatibilità con il Pro 1.1.25: aggiorna insieme gratuito e Pro.

= 1.2.28 =
* Il registro azioni si pulisce da solo: restano gli ultimi 18 mesi (si cambia da Limiti e soglie).
* Nel registro azioni vedi quanto spazio occupa e puoi liberarlo subito con un pulsante.

= 1.2.27 =
* Nuove votazioni e sondaggi, anche a scrutinio segreto, per tutti i soci o per chi è iscritto a un'attività.
* Nuovo inventario dei beni con prestiti ai soci, scadenze e promemoria automatici.
* Nuovo archivio documenti riservato: ogni file è visibile a segreteria, soci o tutti.

= 1.2.26 =
* Per ogni metodo di pagamento online puoi indicare quanto costa incassare (una cifra fissa e/o una percentuale) e, se vuoi, ribaltare la commissione su chi paga (spento di partenza: la legge lo vieta sulle carte dei consumatori).
* Sotto il pulsante di pagamento il socio legge la commissione, se è a suo carico.
* Gli incassi con SumUp entrano in prima nota sul conto SumUp.

= 1.2.25 =
* Domanda di adesione online: chi vuole iscriversi compila un modulo sul sito e la segreteria la approva o la rifiuta.
* I minori si registrano con i genitori o i tutori: loro non sono soci e non compaiono nel libro soci, e ricevono l'invito per l'accesso.
* La tessera vale dal momento in cui la quota è pagata online o se la quota è zero.

= 1.2.24 =
* Sul telefono le tabelle larghe scorrono da sole e la pagina non si sposta di lato.
* La tabella dei listini si legge bene anche su schermi stretti.
* Il testo di aiuto sull'email del socio ora dice che è facoltativa.
* Le sezioni dell'area soci stanno nella larghezza del telefono.
* Gli elenchi di attività ed eventi e i posti liberi non restano più vecchi nella cache del sito: si aggiornano subito.

= 1.2.23 =
* Il programma è stato rivisto secondo le indicazioni di WordPress.org: pagine e moduli più sicuri e compatibili.
* Tolta la migrazione dal vecchio nome APSemplice: non serve più.

= 1.2.22 =
* Prima di aggiungere o spostare le date di un corso o di un evento il programma controlla che non si sovrappongano a qualcos'altro.

= 1.2.21 =
* Prima di salvare un corso o un evento il programma controlla che la risorsa scelta sia libera.
* Un corso o un evento può avere un tempo di allestimento prima e dopo.
* Gli eventi già salvati senza risorsa indicata dovranno sceglierla alla prima modifica.

= 1.2.20 =
* Nella Bacheca di WordPress ci sono due riquadri nuovi: «Da lavorare» (richieste di accesso, soci da rinnovare, ospiti da invitare) e «Eventi e corsi della settimana», con i pulsanti per le funzioni principali.

= 1.2.19 =
* Gli anni solari si aprono e si chiudono anche nella versione gratuita (Cassa → Anni solari).
* Da settembre compare un avviso in Bacheca per aprire il nuovo anno: lo puoi chiudere e ricompare a inizio mese; a dicembre resta sempre visibile.
* Nell'elenco dei plugin l'autore è Wega Studios, con il link al suo profilo.

= 1.2.18 =
* Eventi con più prezzi (listini): ad esempio individuale, di gruppo, pacchetto da 4 individuale e pacchetto da 4 di gruppo.
* Con un pacchetto si paga una volta sola: le altre date si prenotano «dal pacchetto» senza altro da pagare.
* In area riservata i soci scelgono il listino quando si prenotano.

= 1.2.17 =
* Outgoing mail log and test email (with a warning on the dashboard when messages do not leave); the first-access limit only counts messages actually sent; copy button for the WhatsApp message; new Cash > Book page to book events and courses by date or by item; the cash form shows when a payment is a deposit.

= 1.2.16 =
* Funds: a new method lets add-ons set aside the share of a payment made with prepaid credits.

= 1.2.15 =
* New hook point so that add-ons can set aside a share of a payment into a fund.

= 1.2.14 =
* Texts reviewed: clearer messages, author shown as Associazione Semplice; the readme no longer describes the license service (it now belongs to the Pro add-on).

= 1.2.13 =
* Member book: generate the membership cards of all members as A4 sheets with several cards per page (empty positions stay as blank templates), ready to print and cut.

= 1.2.12 =
* Add a new member from the cash form without leaving the page; the card can show member type, board and staff roles and a free text, and can be downloaded as a PDF for printing; Appearance, Bank and Donations keep the settings tabs.

= 1.2.11 =
* The free plugin no longer contains any code of the Pro add-on: if you use the Pro add-on, update both together.
* Run events and courses again: a new "Riproponi" box on every event and course creates a new edition in a new date or social year with the same data. Import events from The Events Calendar, courses included (Tools > From The Events Calendar).

= 1.2.10 =
* Cleaner structure: the license and the updates of the Pro add-on are now handled by the add-on itself.

= 1.2.9 =
* Bookable resources phase 2 (waiting list, rates, hold times) and prepaid credits charged live by QR in the member area; schema 39.

= 1.2.8 =
* The account activation link now lasts 24 hours by default (Limits: 'Validity of the activation link', 1 to 180 days); the message and the member card say so.

= 1.2.7 =
* Security: the first-access limits can read the visitor's address behind a proxy (new limit), requests from unknown people have their own cap and cannot push real ones out, the old email is told when a change is approved; an existing website user with a visitor-only role who is linked to a member gets a new random password; the update-notice download link must belong to the portal.

= 1.2.6 =
* Bookable resources (courts, rooms, boat berths, parking spaces, lockers): new tables and the members' resources view; the management pages come with the Pro add-on.

= 1.2.5 =
* License domain: the complete Public Suffix List (the same copy as the portal) decides which domain a license covers, so customers sharing a host such as altervista.org no longer share a license.

= 1.2.4 =
* License security: The license signing keys are built into the plugin and a valid license needs the signed proof; the plan level comes from the signed part; the Pro features are suspended when the proof is missing, tampered with or too old (new limits: days offline, days before the first check).

= 1.2.3 =
* One free package for everyone (WordPress.org and the portal): the direct updater and the update notice now belong to the Pro add-on; the free plugin no longer contacts the portal for versions.

= 1.2.2 =
* Official brand: new admin menu icon, logo in the dashboard and brand colours in the plugin screens.

= 1.2.1 =
* Direct updates from WordPress (Dashboard → Updates) for the package downloaded from the portal; the WordPress.org package does not contain the updater.

= 1.2 =
* Donations: every collection you create gets its own shortcode (with a Copy button) and a ready PayPal button; clear notice that donations only work when PayPal is configured.

= 1.1.20 =
* Members list: sort by card number (numeric, people without a card last), name and member type; click the column header again to reverse.

= 1.1.19 =
* Import: membership years from year columns (2024, 2025… or ANNO CORRENTE / ANNO SCORSO), 'Anno di iscrizione', 'N. Telefono'; guests get a 'Venute' counter (added to the registered participations) and notes for past events. New limits let you register guests without a mobile number or a host (for importing a history); both rules stay on by default.

= 1.1.18 =
* Member import: columns titled with a year (2023, 2024…) record the actual membership years; 'N. Telefono' is recognised as the phone column.

= 1.1.17 =
* Settings → License: 'Check now' also looks for new plugin versions (skipping the wait between checks) and shows the latest known versions and the date of the last check.

= 1.1.16 =
* Dashboard: links to your associazionesemplice.it account area and to support.

= 1.1.15 =
* License check now goes through the Associazione Semplice website (no credential in the plugin); a license covers the registered domain and all its subdomains and subfolders (staging and test sites included).

= 1.1.14 =
* Update notice: with a license key entered, the plugin asks the Associazione Semplice website for the latest versions and shows a dismissible notice when a newer one exists (can be turned off or tuned in Settings, no data is sent).

= 1.1.13 =
* Member record: Detach email and Detach phone number buttons (office and administrators only); the member keeps their history but loses the access or the number.

= 1.1.12 =
* Moved the English language pack to the Pro plugin (translations belong to text customisation, a Pro feature).

= 1.1.11 =
* License: optional free license key tied to the domain, checked daily with the license server (plan, expiry, signed response); license settings panel with a free key link; the free plugin never loses a basic feature because of a license. Pro Lite and Pro Full levels.

= 1.1.10 =
* Role names are now simply Member and Office (Socio, Segreteria); existing roles are renamed automatically.

= 1.1.9 =
* Renamed to Associazione Semplice (database prefix asem_); installations using the old name are converted automatically on first run.

= 1.1.8 =
* Privacy notice (GDPR) generated from the organisation data: responsible person (president, otherwise the secretariat member), data shared with third parties only for the events attended, no commercial transfer. Available as a site page and as a PDF.

= 1.1.7 =
* Text customisation, languages, entity type and terms are now part of Associazione Semplice Pro; the free edition shows the original texts.

= 1.1.6 =
* Card preview in Appearance: it now has the card border and colours and a real sample QR code.

= 1.1.5 =
* Card editor: the card can be saved after dragging fields (positions with decimals were rejected by the browser), and changing anything selects the custom card.
* Event management: no misleading message for those who manage events without being their referent.

= 1.1.4 =
* Custom member card: choose the size in centimetres (landscape, portrait, square or your own), start from the card generated by the site or from your own image, and place the logo too. The preview shows the saved card. Fixed the image picker button and added a "Save card" button.

= 1.1.3 =
* Member area with a small side menu: "My space", plus Secretariat, Course management and Event management for those who have the role.
* Printable card: no "valid card" badge (only "expired" when needed); the QR instructions are not printed.

= 1.1.2 =
* Card editor: the empty preview keeps the card proportions, so the fields no longer overlap before an image is chosen.
* The administration menu ends with Settings again; the administrative pages are reached from the Settings tabs.

= 1.1.1 =
* First release.
