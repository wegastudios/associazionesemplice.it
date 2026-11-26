=== Associazione Semplice ===
Contributors: wegastudios
Tags: association, members, nonprofit, events, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.41
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
* **Members' area** on your site: card, notices, rules to accept, events and courses, profile. Members can book for several people in one form: themselves, guests, minors and other members. Added with blocks, shortcodes and Elementor widgets. Members do not need a WordPress user.
* **Roles and permissions**: president, vice president, treasurer, councillors, secretariat and employees, each with the permissions of only the areas they need.
* **Registers and privacy**: minutes book, privacy notice, consents, anonymisation and download of a member's personal data.
* **Donations with PayPal**: a form that takes the donor to PayPal's own donation page, and a donation invitation to show where it is needed.
* **Backup**: download all your data whenever you like, or reset them to start over.
* Works with **WP All Import** and **Elementor** if you use them.

= Built to be safe =

Members' data is personal data: the plugin is written with this in mind.

* **Your data stays on your site.** By default the copy of the plugin on WordPress.org contacts no external service and sends no member or association data. Emails are sent by your own site's mail setup; the only optional features that involve third parties are listed under "External services".
* **Permissions by area.** Only the administrator sees everything; the others see only the areas they are given. A separate permission ("View confidential data and export") protects tax codes, notes and exports.
* **Protected member sign-in.** Link or password, with per-member rate limits on requests, cancellations, profile changes and receipts. Suspended members and former members cannot sign in.
* **Verified membership applications.** An application sent from the site reaches the secretariat only after the applicant confirms by email; applications never confirmed are deleted after 30 days.
* **Checked input.** Submitted data is validated and sanitised, text shown is escaped, and actions are protected by permission checks and security tokens (nonces).
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
None by default in the copy on WordPress.org. The only optional features that involve third parties (PayPal, Google place search) are off at first and described in the "External services" section.

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

= 1.3.41 =
* Nuovo: dall'area soci si chiede l'iscrizione a un corso per più persone in un solo modulo: per sé, ospiti (anche nuovi), figli minori e altri soci con cognome e numero di tessera. Accanto a ognuno si vede il contributo.
* Nuovo: la segreteria vede le richieste arrivate insieme e chi le ha mandate, e le approva una per una o tutte con «Approva tutte». Gli ospiti nuovi restano anche se la richiesta non è accolta.
* Nuovo: chi iscrive un altro socio paga il primo mese; dal mese dopo paga la persona iscritta, che riceve un'email e può ritirarsi dalla sua area soci. Ricevute a chi paga quel mese, con l'altro socio indicato solo col numero di tessera.
* Altro: nel profilo la scelta «Altri soci possono prenotarmi agli eventi e iscrivermi ai corsi» vale anche per i corsi.
Da fare dopo l'aggiornamento:
* Se usi il Pro, aggiorna anche quello.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.40 =
* Nuovo: giorni di prova gratuiti per i corsi. La prova si registra dalla scheda del corso; in Bacheca chi l'ha fatta e non si è ancora iscritto. Chi si iscrive nello stesso mese paga dal giorno della prova.
* Nuovo: primo mese in proporzione, dalla prima lezione vera dell'iscritto. Vale per tutti i corsi mensili, anche quelli già creati, per chi si iscrive da ora; le iscrizioni già fatte non cambiano.
* Nuovo: corsi a lezioni. Il contributo è per lezione e il mese costa le lezioni effettive del calendario. Le lezioni del mese si fissano a inizio mese, o prima al primo incasso o al promemoria di quel mese, e si vedono nella scheda del corso; nell'area soci il socio vede lezioni e importo di questo mese e del prossimo.
* Nuovo: giorni rossi. Festività nazionali (anche il 4 ottobre dal 2026), festa del patrono e chiusure dell'associazione. Il corso sceglie se saltarli; la scelta vale dal giorno in cui si fa, le lezioni passate non cambiano.
* Nuovo: lezione annullata in un mese già pagato. Chi aveva pagato ha un importo a favore, che si scala dal rinnovo oppure si restituisce dalla scheda del corso, con conto e data. Si può segnare un recupero. Per i soci l'importo vale fino alla fine del primo mese dell'anno fiscale dopo, con un avviso prima; poi si chiude dalla Bacheca come contributo per le attività.
* Ottimizzazione: un solo calendario per lezioni, presenze, quote e cassa; la quota del mese in corso si conta dalla prima lezione vera, anche nei corsi mensili (gli importi non cambiano).
* Altro: la quota dell'istruttore su una lezione annullata torna all'associazione; se il fondo è già stato liquidato la differenza resta scritta sulla lezione.
* Altro: i promemoria dei corsi tengono conto della variante del contributo; i lavori lunghi avanzano dalla pagina e si fermano con «Annulla»; la Bacheca e l'area soci sul telefono restano dentro lo schermo.
Da fare dopo l'aggiornamento:
* Se usi il Pro, aggiorna anche quello.
* Se un corso deve far pagare il mese intero a chi si iscrive a mese iniziato, aprilo e scegli «Paga il mese intero».
* In Impostazioni, «Giorni senza lezioni», scrivi la festa del patrono e le chiusure; nei corsi che devono saltarli spunta «Nessuna lezione nei giorni festivi e di chiusura».
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.39 =
* Nuovo: dall'area soci si prenota un evento per più persone in un solo modulo: per sé, ospiti (anche nuovi), minori e altri soci con cognome e numero di tessera. Prima di confermare si vede il totale; si prenotano tutti o nessuno.
* Nuovo: se un altro socio prenota per te ricevi un'email e puoi confermare o toglierti fino all'inizio; dal profilo scegli se permetterlo. Il contributo è a carico di chi prenota.
* Sicurezza: i tentativi di prenotare altri soci hanno un limite, modificabile in Limiti e soglie.
* Altro: in «Nuovo incasso» e negli elenchi si vede a carico di chi è il contributo.
Da fare dopo l'aggiornamento:
* Se usi il Pro, aggiorna anche quello: fino ad allora la prenotazione per altri soci non si offre.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.38 =
* Altro: nelle pagine di segreteria e tesoriere chi non ha fatto l'accesso trova l'accesso dello staff e poi torna alla pagina di partenza.
* Altro: nel modulo di accesso dei soci c'è il collegamento «Segreteria e staff».
* Altro: origine della tessera in italiano, «1 partecipante» al singolare, spazio nel pulsante «Dettagli e prenotazione».
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog


Le versioni precedenti sono nel file CHANGELOG.md del plugin.
