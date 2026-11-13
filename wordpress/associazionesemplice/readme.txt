=== Associazione Semplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.23
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

= 1.3.23 =
* Libro soci per esercizio: segue l'esercizio e alla chiusura viene fotografato, così da quel momento non cambia più
* Ogni socio ha una matricola fissa e ogni riga un numero; «Fotografa gli anni già chiusi» ricostruisce i libri passati con la dicitura «ricostruito il…»
* Il libro soci di ogni esercizio si scarica in PDF e in CSV
* Un esercizio chiuso si può riaprire solo se è l'ultimo, scrivendo il motivo; resta una traccia permanente di chiusure e riaperture; gli esercizi si chiudono in ordine
* Il mese di inizio dell'esercizio si sceglie in tutte le edizioni; le tessere di un esercizio chiuso non si modificano
* L'esportazione dei dati personali di un socio comprende anche i libri soci chiusi
* Nuova voce Registri → «Dati per il RUNTS»: numeri al 31 dicembre, soci che sono enti, avviso se si scende sotto il minimo di soci per ODV e APS, promemoria del 30 giugno
* Diritto di voto: votano i soci maggiorenni
* Soci che sono enti: scheda dedicata, importazione da CSV e colonna «Tipo» nel libro soci
* Nuovo tipo di utente «Dipendente»: permessi che si possono togliere, periodo di servizio e conteggio dei lavoratori per il RUNTS
* Trasparenza: è scritto cosa il programma invia al sito associazionesemplice.it per gli aggiornamenti (solo prodotto e versione); si spegne da Impostazioni → Tecniche → Limiti e soglie, e il testo è in Strumenti → Privacy

= 1.3.22 =
* Informativa privacy: ora si parla di «Referente per la privacy», con una frase più chiara su a chi rivolgersi per i propri dati
* Parole più semplici: «non si può annullare», «Cedi il posto a un'altra persona», «Non è ancora entrato nell'area soci», «Scarica per Excel», «Scarica i dati», «la domanda viene cancellata»; il prezzo a fasce si legge «all'ora» o «per turno»
* Tessera non valida: ogni messaggio spiega dove e come rinnovare; il socio trova il link per rinnovare online, oppure l'indicazione di andare in segreteria, e la segreteria viene mandata alla scheda del socio
* Bacheca da telefono: «Incassa» e «Sospendi» sono più grandi e facili da toccare, e «Sospendi» sta dentro «Altro» così non si preme per sbaglio
* Tessera: la frase sotto il codice QR è in un blocco ordinato e «Apri la verifica» va a capo


Le versioni precedenti sono nel file CHANGELOG.md del plugin.
