=== Associazione Semplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.22
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

= 1.3.22 =
* Informativa privacy: ora si parla di «Referente per la privacy», con una frase più chiara su a chi rivolgersi per i propri dati
* Parole più semplici: «non si può annullare», «Cedi il posto a un'altra persona», «Non è ancora entrato nell'area soci», «Scarica per Excel», «Scarica i dati», «la domanda viene cancellata»; il prezzo a fasce si legge «all'ora» o «per turno»
* Tessera non valida: ogni messaggio spiega dove e come rinnovare; il socio trova il link per rinnovare online, oppure l'indicazione di andare in segreteria, e la segreteria viene mandata alla scheda del socio
* Bacheca da telefono: «Incassa» e «Sospendi» sono più grandi e facili da toccare, e «Sospendi» sta dentro «Altro» così non si preme per sbaglio
* Tessera: la frase sotto il codice QR è in un blocco ordinato e «Apri la verifica» va a capo

= 1.3.21 =
* Area soci: le sezioni lunghe (attività, calendario, avvisi, pagamenti, ospiti, profilo) si aprono e si chiudono toccando il titolo; da telefono si parte con una sola sezione aperta e la barra in basso apre quella scelta
* Area soci: c'è il pulsante «Esci» accanto al saluto
* Area soci: parole più chiare («Scarica i miei dati», «sei iscritto» nel calendario, «puoi portare 2 ospiti») e, da telefono, «Stampa la tessera» e «Scarica il PDF» a tutta larghezza
* Moduli «Diventa socio» e prenotazione: Cellulare, Data di nascita e gli altri campi hanno lo stesso aspetto e testo da 16 px, così sull'iPhone la pagina non si ingrandisce da sola; le caselle di consenso sono più grandi
* «Diventa socio»: la parte per il genitore compare solo se dalla data di nascita risulta un minorenne; email e cellulare sono segnati con l'asterisco
* Da telefono il pulsante WhatsApp non finisce più sotto la barra in basso; filtri, calendario e comandi «apri e chiudi» sono più facili da toccare
* Le locandine si vedono sempre intere e, nella pagina dell'evento, un tocco le apre a grandezza intera in un'altra scheda
* Bacheca: «Da fare» sta in cima e gli avvisi stanno sotto, raccolti in «N cose da sistemare» quando sono più d'uno
* Da telefono le schede in alto mostrano una freccia quando ce ne sono altre di lato, e Rubrica soci, Prima nota e Corsi ed eventi mostrano 20 righe alla volta con «Mostra altri»
* Impostazioni: la pagina è divisa in sezioni che si aprono e si chiudono, con un solo «Salva»; il sito ricorda quali hai lasciato aperte
* «Anno solare» e «Anni solari» diventano «Riepilogo» ed «Esercizi» (l'anno contabile); creare un esercizio in anticipo chiede conferma
* Se un socio ha già pagato anche l'anno dopo, la tessera mostra e usa la scadenza più lontana (fine di quell'anno); nelle schermate l'anno della tessera non si chiama più «anno sociale»
* I nomi dei piani non sono più scritti nel plugin: si vede il nome che arriva dalla licenza
* Scheda socio, riquadro Tesoriere: testo e pulsante «Nomina tesoriere» dicono chiaramente che può incassare, registrare spese e iscrivere soci, e chiedono conferma
* L'invito «Collega la licenza gratuita» lo vede solo l'amministratore e, una volta chiuso, non ricompare per tre mesi
* Rendiconto: avviso per il commercialista sulle somme accantonate nei fondi
* Guida: le domande frequenti mostrano solo ciò che la tua versione ha davvero, e la copia di sicurezza è indicata in Strumenti; i rimandi «Impostazioni → Tecniche → …» ora portano alla scheda giusta
* Email di prenotazione per i non soci: la data è scritta per esteso e i passi per il bonifico sono numerati, con la scadenza e a chi mandare la ricevuta; se il posto torna libero per un pagamento non arrivato, l'email lo dice e invita a scrivere se hai già pagato
* Con la tessera non valida il messaggio dice come rinnovare la quota e dove; nell'accesso dei soci errore di password e blocco per troppi tentativi hanno messaggi diversi
* «Elimina una data» diventa «Annulla una data»; parole uniformi: «contributo», «tariffa» e «importo» al posto di prezzo, biglietto e listino, «quota» solo per la quota associativa
* Privacy: «Titolare del trattamento» al posto di «Responsabile del trattamento» nella pagina delle impostazioni
* Il «5x1000» ora si chiama «5 per mille» nei testi (menu, pagina pubblica, avvisi e procedura guidata), senza modifiche ai dati e ai collegamenti esistenti


Le versioni precedenti sono nel file CHANGELOG.md del plugin.
