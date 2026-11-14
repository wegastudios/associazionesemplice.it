=== Associazione Semplice Pro ===
Contributors: wegastudios
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced features for Associazione Semplice: online payments, accounts and funds, accounting and reports, insurance and attendance registers, mass communications, app and wallet cards.

== Description ==

Associazione Semplice Pro adds to Associazione Semplice (required) the features that go beyond the basics: online payments with Stripe and PayPal, several accounts and funds, accounting and reports, insurance and attendance registers, mass emails, installable app with notifications, Apple and Google Wallet cards, receipts in PDF, several member levels, VAT and customisation of every text and language.

== External services ==

With a license key entered in Associazione Semplice Pro, once a day (and when you save the key or press "Check now") the plugin sends the key and your site's domain name to the Associazione Semplice website (https://associazionesemplice.it) to confirm that the key is valid for that domain and to receive the plan and its expiry date. No member data, no content and no other information is sent. The same website is asked for new versions of the plugin and, when you update, for the update package (this can be turned off in Settings → Limits and thresholds). Online payments use Stripe and/or PayPal, only if you set them up: the payer is sent to their payment page and the plugin asks them to confirm the payment.

== Third-party data ==

The plugin includes a copy of the Public Suffix List (https://publicsuffix.org, Mozilla Public License 2.0) to tell which domain a license covers.

== Changelog ==

= 1.3.11 =
* Alla chiusura dell'anno fiscale viene emesso il rendiconto definitivo, con un PDF numerato («n. 1/2026»); vale anche per gli anni già chiusi
* Le ricevute sono numerate per anno fiscale e il numero resta uguale nel tempo; se una ricevuta viene annullata ne resta traccia
* Dopo l'annullo di una voce si può emettere la ricevuta sostitutiva
* Le ricevute annullate non si vedono più nell'area soci

= 1.3.10 =
* Il diritto di voto si decide per ogni livello di socio (sì o no) e vale per votazioni, assemblee e Dati per il RUNTS
* I minorenni non votano mai, qualunque sia il livello
* Se la licenza non comprende i livelli, votano tutti i soci maggiorenni

= 1.3.9 =
* Il nome del piano che vedi nel programma è quello della tua licenza, non più un nome scritto nel programma
* Contabilità: «Anno solare» e «Anni solari» diventano «Riepilogo» ed «Esercizi» (l'anno contabile); creare un esercizio in anticipo chiede conferma
* Rendiconto per cassa: nel titolo e nel PDF compare «Mod. D»; da telefono gli importi restano su una riga e la tabella «Cassa e conti» occupa tutta la larghezza; un avviso ricorda al commercialista le somme accantonate nei fondi
* Il «5x1000» ora si chiama «5 per mille» in menu, pagina pubblica, avvisi e procedura guidata
* Risorse prenotabili: gli orari liberi sono un elenco da scegliere, con un solo pulsante «Prenota» in fondo invece di uno per ogni orario; «pago in segreteria» si legge una volta sola
* Cassa all'ingresso e cassa per più persone: se la tessera non è valida il messaggio dice di rinnovare la quota dalla scheda del socio

= 1.3.8 =
* Anche il programma gratuito ora si aggiorna da Bacheca → Aggiornamenti, insieme al Pro
* Prima di aggiornare, con «Visualizza i dettagli» vedi in italiano cosa cambia

= 1.3.7 =
* Se cambi la risorsa di un'attività e la nuova è già occupata negli stessi orari, vedi cosa si sovrappone e il cambio non viene applicato.
* Spuntando «Sposta sulla risorsa di prima ciò che si sovrappone», le prenotazioni e le altre attività che si sovrappongono passano alla risorsa che si libera; se lì non entrano, non si sposta niente.

= 1.3.6 =
* Anche i corsi indicano la risorsa che usano, così i calendari si incrociano e una risorsa già impegnata non risulta libera.
* Nuova scelta «Occupa la risorsa solo dove ci sono prenotazioni»: per le attività a fasce, gli orari senza prenotazioni restano liberi.
* Una fascia già occupata da un'altra attività non si può prenotare.

= 1.3.5 =
* Testi più chiari per la commissione di incasso dei conti.

= 1.3.4 =
* Nell'area soci la voce «Rimborsi» la vedono solo i volontari: gli altri soci non vedono niente, nemmeno nel menu.

= 1.3.3 =
* Nell'area soci i rimborsi spese li chiedono solo i volontari; per gli altri soci le spese le gestisce la segreteria.
* Su un conto puoi indicare la commissione di incasso (per esempio di un POS fisico): gli incassi sul posto la registrano come spesa.

= 1.3.2 =
* L'app non intercetta più l'amministrazione e l'accesso del sito; testo della pagina App più chiaro

= 1.3.1 =
* App più curata: icona con l'iniziale, pagina offline con «Riprova» e invito a installarla sotto la tessera

= 1.3.0 =
* Lo staff di un evento con l'incasso abilitato può addebitare crediti con il QR anche se entra con il link, senza utente WordPress
* Se un secondo ordine del negozio risulta pagato per lo stesso pagamento, compare tra i pagamenti da controllare e il riferimento del primo incasso non si perde
* Esce insieme al gratuito 1.3.0 (stesso livello di compatibilità: funziona anche con il gratuito 1.2.51 o successivo)

= 1.1.41 =
* Crediti, risorse e notifiche funzionano anche per i soci che entrano con il link, senza utente WordPress
* Richiede il gratuito 1.2.51 o successivo

= 1.1.40 =
* Il pulsante per annullare l'approvazione di una differenza di fine anno si chiama «Revoca l'approvazione»

= 1.1.39 =
* Le spese si attribuiscono da sole ai fondi giusti
* Il volontario usa solo i suoi fondi e, se supera, resta in credito
* Le differenze di fine anno si controllano una per una e si approvano a mano
* Nuovo elenco dei volontari in credito in Cassa e in Bacheca

= 1.1.38 =
* Pro Fiscale: le spese documentate si attribuiscono ai fondi dei rimborsi (anche divise su due fondi), con gli elenchi delle spese non rimborsate e dei fondi non documentati.
* Pro Fiscale: avvisi di bilancio quando le spese superano gli incassi o l'utile è eccessivo.
* Pro Fiscale: bilancio provvisorio sempre aggiornato al mese concluso, con l'andamento del mese in corso e lo storico mensile.
* Pro Fiscale: prospetto dei rimborsi per persona e avviso di discrepanze nei mesi prima della fine dell'anno fiscale.
* Pro Fiscale: mese di inizio dell'anno fiscale separato da quello dell'anno sociale.
* Richiede il gratuito 1.2.47 o successivo.

= 1.1.37 =
* Affiliazioni: tutti gli enti di promozione riconosciuti dal CONI, campo «discipline», e nel file per i portali degli enti le colonne Sport e Disciplina; il tipo di tessera di ripiego è il tipo di tesseramento della scheda.
* Richiede il gratuito 1.2.45 o successivo.

= 1.1.36 =
* Il registro presenze ha una seconda vista a calendario (le date in orizzontale, le persone in verticale) e tiene conto delle lezioni annullate o spostate.
* Richiede il gratuito 1.2.43 o successivo.

= 1.1.35 =
* Le ricevute dei minori sono intestate al genitore che opera (o a chi ha pagato), con i codici fiscali dei genitori e del ragazzo e, dai 5 ai 18 anni, l'indicazione se il pagamento è tracciato (detrazione delle spese sportive).

= 1.1.34 =
* Compatibile con il gratuito 1.2.39: aggiorna insieme gratuito e Pro.

= 1.1.33 =
* Il livello della licenza (Gratuito, Contabile, Pro Full) lo decide solo il portale con una prova firmata: se un piano cambia nome la licenza resta valida e il livello non cambia.

= 1.1.32 =
* Rafforzamenti di sicurezza condivisi con il gratuito (indirizzo del visitatore, ricevute, avvisi dello staff, collegamento degli utenti).

= 1.1.31 =
* Salvando la chiave di licenza non compare più «Errore imprevisto»
* Il periodo di attesa della licenza non si allunga più cambiando la data

= 1.1.30 =
* L'elenco delle integrazioni cita tutti i metodi di pagamento online (Stripe, PayPal, SumUp, Satispay e Klarna).
* Gli avvisi dicono «1 avviso inviato» al singolare.

= 1.1.29 =
* Corretto: con il Pro attivo la scheda «Convenzioni» tra i Registri era sparita.

= 1.1.28 =
* Nuovi SMS ai soci (Brevo o Twilio) con coda, nuovi tentativi, registro e crediti residui; copia SMS degli avvisi automatici, spenta di partenza.
* Newsletter sincronizzata con Brevo, Mailchimp o MailPoet, solo per chi ha dato il consenso; chi lo ritira o cambia indirizzo esce da solo.
* Consensi per SMS e newsletter nella scheda del socio e in «Le mie comunicazioni» nell'area soci, compresi nell'esportazione privacy.
* Nuovo pagamento con Satispay e Klarna tramite Stripe (da 35 € a 10.000 €).
* Richiede il gratuito 1.2.31 o successivo.

= 1.1.27 =
* Nuove assemblee e deleghe: convocazione via email, presenze, deleghe tra soci, numero legale e verbale già compilato.
* Nuovo bilancio preventivo per categoria, confrontato con quello che è successo davvero (Pro Contabile e Fiscale).
* Nuovi rimborsi spese ai volontari: spesa documentata, autocertificazione e chilometrico, con approvazione, pagamento e registrazione in prima nota.

= 1.1.26 =
* Risorse: promemoria prima della prenotazione e voce di prima nota per ogni risorsa.
* Risorse: nuovo report di occupazione e richieste di assegnazione dai soci per le risorse libere.
* Risorse: ingresso con il QR della prenotazione, registrato dalla segreteria.

= 1.1.25 =
* Nuovo registro dei certificati medici: file riservato alla segreteria, scadenze, promemoria, avvisi in Bacheca e nell'area soci.
* Nuove affiliazioni agli enti con le tessere dei soci, una alla volta o in blocco, ed elenco da caricare sui portali degli enti.
* Richiede il gratuito 1.2.29 o successivo.

= 1.1.24 =
* Le commissioni di incasso partono dalle tariffe standard pubblicate da Stripe, PayPal e SumUp. Puoi cambiarle o svuotarle dalle impostazioni dei pagamenti.

= 1.1.23 =
* Nuovo metodo di pagamento: SumUp. Il socio paga su una pagina sicura di SumUp e l'incasso entra in prima nota da solo.
* Commissioni di incasso per ogni metodo: se vuoi, la spesa stimata si registra da sola in prima nota a ogni pagamento, e la commissione si può ribaltare su chi paga (spento di partenza).
* Aggiorna insieme ad Associazione Semplice 1.2.26.

= 1.1.22 =
* Pagamento online della quota quando si chiede di iscriversi (carta o PayPal): se la domanda viene rifiutata la somma resta da rimborsare.
* Aggiorna insieme ad Associazione Semplice 1.2.25.

= 1.1.21 =
* Aggiornamento tecnico insieme ad Associazione Semplice 1.2.23: pagine e moduli più sicuri e compatibili.

= 1.1.20 =
* Risorse: una risorsa non può più essere occupata due volte. Collegare un corso o un evento, aggiungere o spostare date con un'occupazione già presa (allestimenti compresi) viene rifiutato.
* Le sovrapposizioni già presenti non cambiano da sole: compaiono in Bacheca e vanno sistemate a mano.
* Aggiorna insieme ad Associazione Semplice 1.2.22.

= 1.1.19 =
* Risorse: allestimento prima e dopo corsi ed eventi, che si somma a quello della risorsa; un evento ha 3 ore prima e dopo se non indichi altro, un corso nessuna.
* Un evento deve dire quale risorsa occupa; quelli già salvati la scelgono alla prima modifica.
* Aggiorna insieme ad Associazione Semplice 1.2.21.

= 1.1.18 =
* Funziona con la nuova versione di Associazione Semplice (1.2.19): aggiorna entrambi i plugin.
* Gli anni solari ora sono anche nella versione gratuita; qui restano la tabella dei fondi da rimborsare per anno e le voci in Contabilità.
* In prenotazione la segreteria può indicare un «ospite dell'associazione» o un «ospite del volontario»: lo sconto sulla loro parte si calcola da solo e il fondo del volontario non si alimenta quando ospita lui.

= 1.1.17 =
* Cash > Book also shows the resources available on a day, and the free days of a resource.

= 1.1.16 =
* Prepaid credits: an activity paid with credits now sets aside the volunteer's share in their fund, like a payment in money.

= 1.1.15 =
* Resources: each resource can set aside a share of every payment (fixed amount or percentage) into a fund, for resources managed by third parties.

= 1.1.14 =
* Texts reviewed: clearer license, limits and payment messages.

= 1.1.13 =
* Reminder to open the next calendar year from September (dismissible each month, always visible in December).

= 1.1.12 =
* Update list shows the plugin icon and banner.

= 1.1.11 =
* Works with Associazione Semplice 1.2.11 or later: update both together. Nothing changes in how you use it; your data stays as it is.

= 1.1.10 =
* The license check and the update notices now live in this add-on; requires Associazione Semplice 1.2.10 or later.

= 1.1.9 =
* Prepaid credits: packages, live QR charge, credit ledger and admin page.

= 1.1.8 =
* Bookable resources for the Pro Lite and Pro Full plans: management pages, bookings and guests.

= 1.1.7 =
* Safer updates: the plugin checks that the downloaded package really comes from Associazione Semplice and refuses older versions.

= 1.1.6 =
* Direct updates from WordPress (Dashboard → Updates) for the Pro add-on, served by the Associazione Semplice portal.

= 1.1.5 =
* Includes the English language pack, previously shipped in the free plugin.

= 1.1.4 =
* Pro features follow your plan: Pro Lite (without accounting) or Pro Full.

= 1.1.3 =
* Renamed to Associazione Semplice Pro (follows the main plugin rename).

= 1.1.2 =
* Customisation of every text, additional languages, entity type and terms (moved here from the free edition).

= 1.1.1 =
* First release.
