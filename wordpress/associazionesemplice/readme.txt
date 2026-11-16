=== Associazione Semplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.26
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

= 1.3.26 =
* Prima di aggiornare conviene fare una copia di sicurezza. Lo schema dei dati passa alla versione 67: l'aggiornamento è automatico alla prima apertura del sito e non modifica i dati esistenti.
* Permessi dei dipendenti per area: la pagina Dipendenti ha 15 spunte (anagrafica, certificati medici, corsi, eventi, risorse, incassi, spese, rimborsi, fondi, comunicazioni, documenti, assemblee, inventario, convenzioni, importa ed esporta); un permesso tolto vale in tutte le strade verso gli stessi dati. I dipendenti già presenti mantengono l'accesso di prima; in Bacheca compare l'avviso «Rivedi i permessi dei dipendenti: ora si danno per area.»
* Certificati medici: senza l'area dedicata, chi ha Corsi o Eventi vede solo i certificati degli iscritti alle attività di cui è istruttore e di quelle senza istruttore; ogni apertura di un file resta nel registro delle azioni
* Chi non ha l'area «Anagrafica soci» sceglie le persone con una ricerca («Cerca per nome o tessera (almeno 3 lettere)»), al massimo 10 risultati
* Ruoli di WordPress: il plugin non dà più accessi ai ruoli standard di WordPress (Editore, Autore e simili); vedono il pannello l'amministratore di WordPress, la Segreteria, i Dipendenti e presidente e vicepresidente. Chi entrava con un ruolo di WordPress lo trova in un avviso in Bacheca; la pagina «Ruoli e accessi» non c'è più
* Eventi su richiesta: la prenotazione diretta è della sola segreteria e la lista d'attesa non avanza da sola; richieste con link di conferma, tetto alle richieste in attesa e ai posti chiedibili in 24 ore per email e per connessione, con messaggi chiari e un avviso in Bacheca; se il codice fiscale è già in anagrafica, chi chiede non vede né il nome né lo stato della persona e la richiesta passa alla segreteria
* Prenotazione dal sito: se l'attività ha dei listini va scelta la tariffa e la lista d'attesa la ricorda
* Adesione di un minore: se il genitore è già in anagrafica, i consensi gli vengono attribuiti e il collegamento al minore nasce solo dopo la sua conferma da un link ricevuto per email
* Moduli pubblici: nome e cognome accettano solo lettere, spazi, apostrofi e trattini
* Password dei soci dopo un ripristino: tornano come nella copia, ma al primo accesso ogni socio sceglie una nuova password
* Copia di sicurezza: lo scarico chiede la password dell'amministratore; le copie non contengono chiavi cifrate; nel ripristino i testi semplici vengono ripuliti
* Aggiornamenti firmati: il programma accetta solo pacchetti firmati e non installa versioni più vecchie di quelle già viste
* Documenti: per Word, Excel e LibreOffice si accettano solo file davvero di quel formato; un foglio Excel con troppe colonne viene rifiutato con un messaggio chiaro
* Contenuti riservati: chi non può leggerli non li trova con la ricerca del sito né in commenti, allegati e file del calendario
* Registro delle azioni più completo (provenienza, impostazioni cambiate, scarichi); il cambio del conto che riceve i soldi è sempre notificato agli amministratori
* Pagamenti: l'incasso si registra una sola volta anche con avvisi ripetuti; con bonifico e altri metodi fuori linea la quota risulta pagata a ordine «completato»; rimborsi con autocertificazione e massimo di prenotazioni valgono anche con più richieste insieme
* Disinstallazione più completa: si tolgono lavori programmati, chiavi e permessi del plugin dai ruoli; un errore imprevisto in amministrazione mostra un messaggio con un codice da citare al supporto


Le versioni precedenti sono nel file CHANGELOG.md del plugin.
