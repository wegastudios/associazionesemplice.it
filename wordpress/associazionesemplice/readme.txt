=== Associazione Semplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.33
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

== Changelog ==

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

= 1.3.30 =
* Nuovo: la tessera dei soci, le quote e gli incassi seguono l'anno fiscale; l'«anno delle attività» vale solo per corsi ed eventi. Le tessere già emesse con un pagamento si riallineano da sole (mai accorciate, anni chiusi intatti); se cambi il mese dell'anno fiscale vedi prima cosa succede alle tessere.
* Nuovo: le pagine in bozza di eventi e corsi, anche quelle importate, si pubblicano tutte insieme.
* Ottimizzazione: il report delle attività divide entrate e uscite generali per anno fiscale.
* Altro: nella finestra «Visualizza i dettagli» di WordPress il changelog compare sempre, anche dopo l'aggiornamento.
Da fare dopo l'aggiornamento:
* In Bacheca controlla l'esito del riallineamento delle tessere
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.29 =
* Nuovo: gli eventi su più giorni compaiono in ognuno dei loro giorni nei calendari, nella Bacheca e nel calendario dei soci, e l'importazione da The Events Calendar porta anche la data di fine.
* Altro: passando l'anno sociale a gennaio, i corsi e gli eventi creati con l'anno a cavallo (per esempio 2025/2026) restano nell'elenco.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.28 =
* Nuovo: eventi su più giorni (una regata, un torneo) o che finiscono dopo mezzanotte, con il campo «fino al» nella data. Online si prenotano fino al giorno prima, poi ci pensa la segreteria; gli eventi di un giorno restano come prima.
* Nuovo: l'elenco degli eventi si sfoglia mese per mese, anche all'indietro, e gli eventi già svolti restano visibili con le loro date.
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog

= 1.3.27 =
* Ottimizzazione: Bacheca, elenco e schede di corsi ed eventi, rendiconto, calendario, elenco soci, Prima nota ed esportazioni molto più rapidi, con gli stessi risultati di prima
* Ottimizzazione: «Soci e ospiti» a 50 righe per pagina e Prima nota a 100, con i totali sempre dell'anno intero
* Ottimizzazione: le pagine pubbliche degli eventi restano in memoria per i visitatori e si aggiornano a ogni prenotazione o modifica
* Nuovo: l'importazione di file grandi procede a piccoli giri, con avanzamento e pulsanti per riprendere o fermare
Changelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog


Le versioni precedenti sono nel file CHANGELOG.md del plugin.
