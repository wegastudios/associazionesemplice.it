=== Associazione Semplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.27
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

= 1.3.27 =
* Ottimizzazione: Bacheca, elenco e schede di corsi ed eventi, rendiconto, calendario, elenco soci, Prima nota, esportazioni, promemoria e comunicazioni molto più rapidi, con gli stessi risultati di prima
* Ottimizzazione: «Soci e ospiti» a 50 righe per pagina e Prima nota a 100, con i totali sempre dell'anno intero
* Ottimizzazione: le pagine pubbliche degli eventi restano in memoria per i visitatori e si aggiornano a ogni prenotazione o modifica
* Nuovo: l'importazione di file grandi procede a piccoli giri, con avanzamento e pulsanti per riprendere o fermare
* Nuovo: verifica delle ricevute a richiesta, a lotti, con l'esito salvato per anno fiscale
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.26 =
* Nuovo: permessi dei dipendenti per area. I certificati medici hanno un permesso a parte, e chi non ha l'anagrafica cerca i soci per nome o tessera
* Nuovo: negli eventi su richiesta prenota solo la segreteria; la lista d'attesa ricorda la tariffa scelta
* Nuovo: l'adesione di un minore aspetta la conferma del genitore già registrato
* Nuovo: limiti alle richieste dei non soci, regolabili in Limiti e soglie
* Sicurezza: password per scaricare le copie di sicurezza, aggiornamenti solo firmati, chiave di licenza cifrata
* Sicurezza: dopo un ripristino i soci scelgono una nuova password; i ruoli standard di WordPress non danno più accesso al plugin
* Sicurezza: i contenuti riservati non compaiono più in ricerca, commenti e anteprime
* Altro: con bonifico, assegno o contrassegno la quota risulta pagata a ordine «completato»; gli errori imprevisti mostrano un codice per il supporto
Da fare dopo l'aggiornamento:
* Stripe: aggiungi al webhook gli eventi charge.refunded e charge.dispute.created
* Se sposti il sito, reinserisci la chiave di licenza in Impostazioni → Licenza
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog


Le versioni precedenti sono nel file CHANGELOG.md del plugin.
