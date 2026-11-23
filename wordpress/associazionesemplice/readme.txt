=== Associazione Semplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.36
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
* **Members' area** on the site: notices, rules to accept, membership card, events and courses. Available as shortcodes, blocks and Elementor widgets.
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

The front-end style sheet and script (`assets/frontend.css` and `assets/frontend.js`) are shipped minified to load faster. The readable sources are in the public repository: https://github.com/wegastudios/associazionesemplice.it (folder `wordpress/associazionesemplice/assets`); the minification step is `wordpress/build.sh` with `wordpress/minify-assets.pl`.

== Changelog ==

= 1.3.36 =
* Nuovo: scheda «Stato del server» negli Strumenti, con i consigli per l'hosting, e cinque controlli in Salute del sito.
* Nuovo: in Bacheca «Ospiti appena entrati», con l'avviso «da invitare ad associarsi» da togliere con «Fatto».
* Nuovo: tetto orario delle email in Limiti e soglie; le email singole rifiutate dal server di posta si riprovano da sole.
* Ottimizzazione: molto più veloce con tanti dati: pagine degli eventi in cache, ricerca delle persone al posto delle tendine, copia di sicurezza ed esportazioni a tappe, libro soci e tessere a pagine, meno richieste al database.
* Altro: conversione delle tabelle a InnoDB dal pannello della copia di sicurezza; aggiornamenti del database a tappe.
Da fare dopo l'aggiornamento:
* Apri «Stato del server» e guarda le righe «Da sistemare».
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.35 =
* Nuovo: «Rigenera il codice» della tessera QR, per il socio nell'area soci e per la segreteria dalla scheda. Il codice vecchio, anche stampato o nel Wallet, smette subito di valere.
* Sicurezza: limiti di frequenza per socio su richieste, annullamenti, profilo, ricevute e rigenerazione del codice. I valori si cambiano in Limiti e soglie.
* Altro: controlli contro gli errori di digitazione: note fino a 2.000 caratteri, date tra il 1900 e il 2100, al massimo 10.000.000 € e 200 voci per incasso.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.34 =
* Sicurezza: la domanda di adesione fatta dal sito si conferma dall'email: arriva alla segreteria solo dopo il clic sul link (vale 48 ore; un invio all'ora per indirizzo).
* Altro: le domande mai confermate si cancellano dopo 30 giorni; di una domanda respinta con quota pagata, dopo 90 giorni restano solo nome e cognome (i movimenti contabili restano).
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.33 =
* Sicurezza: chi è sospeso o ha lasciato l'associazione non entra più nell'area soci. Un ex socio tutore di un minorenne ancora socio entra solo per il figlio: lo vede, prenota e paga per lui.
* Sicurezza: nuovo permesso dei dipendenti «Vedere dati riservati ed esportare»: senza, non si vedono codice fiscale e note dei soci e non si esporta. I dipendenti già presenti restano come sono.
* Sicurezza: un incasso registrato a mano non può superare quanto resta da pagare, e un doppio clic non lo registra due volte.
* Altro: «Quota attività / corso» ora si chiama «Contributo attività / corso».
Da fare dopo l'aggiornamento:
* I dipendenti che aggiungi da ora partono senza il permesso «Vedere dati riservati ed esportare»: dallo a chi deve vedere i codici fiscali o esportare.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.32 =
* Nuovo: invito a donare («Non puoi partecipare? Sostienici con una donazione»), da accendere dove serve: pagina dell'evento o del corso, conferma dell'iscrizione, area soci, email ai soci.
* Altro: la pagina Donazioni spiega meglio come ricevere le donazioni con PayPal, mandare la ricevuta e registrarle in Prima nota.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.31 =
* Ottimizzazione: rivisti tutti i testi del plugin, più chiari e coerenti: «area soci», messaggi di errore che dicono cosa fare, singolare e plurale giusti, un solo formato per l'oggetto delle email, «rendiconto» al posto di «bilancio».
* Altro: corretto un errore che bloccava il salvataggio dei dati dell'ente quando si conferma il cambio del mese dell'anno fiscale.
* Altro: «Controlla di nuovo» in Bacheca → Aggiornamenti ricontrolla subito anche il portale.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog


Le versioni precedenti sono nel file CHANGELOG.md del plugin.
