=== Associazione Semplice ===
Contributors: wegastudios
Tags: association, members, nonprofit, events, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.37
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Member register, membership cards, events, courses and a cash book for small Italian non-profit associations (APS, ETS and the third sector).

== Description ==

**Associazione Semplice** keeps members, activities and accounts in order, right from your association's WordPress site: no external software and no subscription. It is built for **small Italian associations** (APS, ETS, ASD and other third-sector entities) and follows Italian rules: member register, fiscal years, privacy.

Optional features are off at first. A **guided setup** asks a few questions and turns on only what is needed: nothing complicated to configure and nothing to learn before you start.

👉 **[Try the live demo](https://associazionesemplice.it/prova)**: a real WordPress site with made-up data, where you can click and type without breaking anything.

**Please note:** the plugin is made for Italian associations and Italian third-sector rules, so its interface and texts are in Italian. An English text pack is included in the Pro add-on and can be chosen in the settings. The plugin does not use the WordPress translation files.

= What you can do, for free =

* **A member register in order**: records of members and guests, progressive numbering, terminations, a member register per fiscal year with PDF and CSV, members that are organisations, minors with parents or guardians, import from CSV or Excel files, export.
* **Membership cards with QR code**: digital and printable cards with your colours and logo. The code is checked live and can be regenerated if a phone is lost.
* **Courses and events**: registrations, limited seats with a waiting list, single or recurring events, tickets with QR code, participant lists, an internal calendar and publishing to Google Calendar, Apple and Outlook.
* **Public event pages**: a page for every event or course, a card list, a monthly calendar, structured data for Google. People can book from your site even **without being members**, with email confirmation.
* **Cash book**: income and expenses in a single cash account, expenses with receipts and invoices attached, advances and discounts, export. **Fiscal years** with tracked closing and reopening.
* **Members' area** on your site: card, notices, rules to accept, events and courses, profile. Added with blocks, shortcodes and Elementor widgets. Members do not need a WordPress user.
* **Roles and permissions**: president, vice president, treasurer, councillors, secretariat and employees, each with the permissions of only the areas they need.
* **Registers and privacy**: minutes book, privacy notice, consents, anonymisation and download of a member's personal data.
* **Donations with PayPal**: a form that takes the donor to PayPal's own donation page, and a donation invitation to show where it is needed.
* **Backup**: download all your data whenever you like, or reset them to start over.
* Works with **WP All Import** and **Elementor** if you use them.

= Built to be safe =

Members' data is personal data: the plugin is written with this in mind.

* **No data leaves your site.** The free plugin contacts no external service and sends no member or association data.
* **Permissions by area.** Only the administrator sees everything; the others see only the areas they are given. A separate permission ("View confidential data and export") protects tax codes, notes and exports.
* **Protected member sign-in.** Link or password, with per-member rate limits on requests, cancellations, profile changes and receipts. Suspended members and former members cannot sign in.
* **Verified membership applications.** An application sent from the site reaches the secretariat only after the applicant confirms by email; applications never confirmed are deleted after 30 days.
* **Checked input.** Every form is validated and sanitised, every text shown is escaped, and every action needs the right permission and a security token (nonce).
* **Closed fiscal years cannot be changed.** Closings and reopenings leave a permanent trace.
* **Safer cards.** The QR code can be regenerated in one click; the old one stops working at once.
* **Privacy by design.** Privacy notice, recorded consents, anonymisation and download of personal data.

= Want more? Associazione Semplice Pro =

The free plugin is complete for managing members, activities and the cash book, and stays free for ever. When you need online payments or accounting, add **Associazione Semplice Pro**, a separate plugin with a licence per domain (**Pro Contabile** and **Pro Fiscale** plans):

* **Online payments**: Stripe, PayPal, SumUp, Satispay and WooCommerce, with refunds and a guided setup.
* **Several member levels**, family units, membership fee by instalments.
* **Several accounts, set-aside funds and management reports.**
* **PDF receipts** numbered per fiscal year, and an annual statement.
* **Messages to members**: email, text messages, newsletters (Brevo, Mailchimp, MailPoet).
* **Installable app** for the phone, with notifications, and cards in Apple Wallet and Google Wallet.
* **Bookable resources**: courts, rooms, parking spaces, lockers and boat berths.
* **Insurance and attendance registers**, customisable texts and more languages.
* With **Pro Fiscale** also **VAT, the "5 per mille" tax allocation and a cash-basis statement**.

[Compare the plans, feature by feature](https://associazionesemplice.it/confronto) · [Prices](https://associazionesemplice.it/prezzi) · [Free licence](https://associazionesemplice.it/free)

= Help and resources =

* [Features of the plugin](https://associazionesemplice.it/funzioni)
* [Try the demo](https://associazionesemplice.it/prova)
* [Frequently asked questions](https://associazionesemplice.it/domande)
* [Support and guides](https://supporto.associazionesemplice.it)
* [All versions](https://associazionesemplice.it/wordpress/changelog)

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

= Is the plugin only in Italian? =
Yes: it is made for Italian associations and Italian third-sector rules, so its interface and texts are in Italian. An English text pack can be chosen in the settings (included in the Pro add-on). The plugin does not use the WordPress translation files.

= What happens to the data if I delete the plugin? =
By default it stays. In the settings you can choose to delete it permanently when the plugin is deleted.

= Can I import my existing member list? =
Yes, from a CSV or Excel file, with a preview before confirming.

= Where do the donations go? =
Straight to the PayPal account you enter in the settings. They are recorded in the cash book by hand.

= Do members need a WordPress user? =
No. Members sign in to the members' area with email and password, or with a link sent by email.

= What data does the plugin send to external services? =
None by default. The only optional features that involve third parties (PayPal, Google place search) are off at first and described in the "External services" section.

= What is the difference with the Pro version? =
The free plugin is complete for members, activities and the cash book. Pro adds online payments, several accounts and funds, receipts, messages to members, app and wallet cards, bookable resources and, with Pro Fiscale, VAT and the "5 per mille". The [full comparison](https://associazionesemplice.it/confronto) is on our website.

= Where can I get help? =
On the [support site](https://supporto.associazionesemplice.it) you find guides and can open a request.

== Screenshots ==

1. The plugin's dashboard: payments to collect and things to do.
2. Members and guests, with search, member type and card status.
3. A member's record: members' area access, card and enrolment, member register.
4. Courses and events: registrations, seats and the accounts of each activity.
5. The cash book, with income and expenses in a single cash account.
6. The public event and course pages on your site.

== Credits ==

The interface uses the Montserrat typeface (SIL Open Font License 1.1, copy in `assets/fonts/OFL.txt`) and icons from Lucide (ISC license, https://lucide.dev), both bundled with the plugin; nothing is loaded from external servers.

The front-end style sheet and script (`assets/frontend.css` and `assets/frontend.js`) are shipped minified to load faster. The readable sources are in the public repository: https://github.com/wegastudios/associazionesemplice.it (folder `wordpress/associazionesemplice/assets`); the minification step is `wordpress/build.sh` with `wordpress/minify-assets.pl`.

== Changelog ==

= 1.3.37 =
* Altro: nelle pagine delle attività e degli eventi le locandine non diventano più enormi nei siti con WordPress 6.7 o successivo.
* Ottimizzazione: le schede delle attività sono uniformi: immagine della stessa misura, fascia colorata con l'icona se manca la locandina, date e posti in evidenza, pulsante in fondo.
* Ottimizzazione: in Corsi ed eventi gli importi e i pulsanti delle schede sono allineati.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

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
