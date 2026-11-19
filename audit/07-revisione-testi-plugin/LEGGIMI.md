# Audit 07 — Revisione dei testi, parte plugin (19/11/2026)

Richiesta del titolare: rivedere tutti i testi della parte plugin (sito, plugin, guide di supporto) per renderli più umani e chiari, e insieme professionali e concisi.
Questa cartella contiene solo **proposte**: nessun file del plugin, del portale o del supporto è stato modificato.
Codice letto: ramo `main` al commit `afa4d7be` (plugin 1.3.30, Pro 1.3.17).

## Numeri

912 proposte: 37 di gravità Alta, 519 Media, 356 Bassa. In più 121 punti «Da verificare» (sospetti errori di fatto, non riscritti).

| File | Area | Proposte | Alta | Da verificare |
|---|---|---|---|---|
| `00-sito-portale.md` | Pagine del portale sul plugin, confronto, email delle licenze | 49 | 4 | 11 |
| `01-plugin-bacheca-A-L.md` | Bacheca del plugin, `includes/Admin/` A–L | 126 | 5 | 13 |
| `02-plugin-bacheca-M-Z.md` | Bacheca del plugin, `includes/Admin/` M–Z | 127 | 3 | 13 |
| `03-plugin-area-soci-e-readme.md` | `includes/Frontend/`, testi JS, readme e intestazioni | 81 | 5 | 11 |
| `04-plugin-servizi-A-D.md` | `includes/*.php` A–D: errori, esiti, email | 51 | 2 | 9 |
| `05-plugin-servizi-E-L.md` | `includes/*.php` E–L | 77 | 1 | 9 |
| `06-plugin-servizi-M-P.md` | `includes/*.php` M–P | 84 | 4 | 9 |
| `07-plugin-servizi-Q-Z.md` | `includes/*.php` Q–Z | 83 | 4 | 10 |
| `08-guide-supporto-1-7.md` | Guide `part1`–`part7` (55 guide) | 125 | 5 | 18 |
| `09-guide-supporto-8-14-e-sito.md` | Guide `part8`–`part14` senza `part12`, pagine ed email del sito di supporto | 109 | 4 | 18 |

Ogni voce ha: file e riga, gravità, dove appare, testo di oggi, testo proposto, motivo in una riga. Ogni file apre con i «Difetti ricorrenti» della sua area.

## Come è stato fatto e cosa non copre

- Dieci lotti letti in parallelo con le stesse istruzioni e lo stesso glossario (`audit/03-plugin.md`, righe 35–73).
- Nel plugin (circa 80.000 righe) i testi sono stati estratti con una ricerca sulle righe che contengono frasi italiane, poi letti nel contesto. **Etichette di una o due parole possono essere sfuggite.** Le guide sono state lette per intero.
- Controllo automatico delle citazioni: su 912 voci, 734 trovate alla riga indicata, 111 non controllabili in automatico (voci su più righe o su intere sezioni), 67 segnate discordanti; un campione di 8 di queste era corretto (la differenza era l'apostrofo scritto `\'` nel PHP). Le restanti non sono state ricontrollate una per una.
- Niente è stato provato nel browser: dove una proposta dipende da come il testo appare a schermo, è detto nella voce.
- Fuori dal lavoro: gestionale SaaS (`saas/`, `part12.php`, email e pagine SaaS del portale), amministrazione del portale, testi legali del portale, sezione Changelog dei readme, `includes/languages/en.json`.

## Urgente, non è una questione di testi

1. **Errore di codice, verificato leggendo il file (non eseguito).** `wordpress/associazionesemplice/includes/Frontend/CreditsFront.php:148` chiama `AssociazioneSempliceCsrf::field(` senza barre rovesciate; alla riga 224 dello stesso file la forma è `\AssociazioneSemplice\Csrf::field(`. Dentro lo spazio dei nomi `AssociazioneSemplice\Frontend` quella classe non esiste: quando il socio ha crediti sufficienti la pagina «Conferma e paga» con i crediti si ferma con un errore. Nessun `class_alias` nel plugin.
2. **Note interne visibili nella guida `cifratura-dei-file-riservati`.** `supporto/database/guides/part14.php:165-166` contiene due commenti HTML con numeri di PR. `ArticleBody.php` dichiara che qualsiasi HTML nel testo compare come testo: i commenti quindi si leggono nella pagina. Da confermare nel browser.
3. **Guide: corsivo ed elenchi rientrati non resi.** Lo stesso `ArticleBody.php` non gestisce il corsivo con un asterisco né gli elenchi rientrati: asterischi visibili in `part8:106`, `part9:36`, `part13:50`, `part14:196`; i tre passi di `part11:79-81` escono come un paragrafo unico.

## Errori di lingua visibili al pubblico (Alta)

- `Polls.php:331`: «è aperta un sondaggio» nell'email ai soci.
- `PublicEvents.php:1113`: «Tutti i domenica» nell'elenco degli eventi.
- `Resources.php:423`: «occupata da la prenotazione di…».
- `FiscalYears.php:280`: frase rotta alla chiusura dell'anno fiscale.
- `Rules.php:59-61`: produce «L'email è troppo lungo» (in «Da verificare»).
- Singolare e plurale con i numeri («1 posti liberi», «1 prenotati», «1 crediti», «1 persone»): una trentina di punti tra area soci, `EventRequests.php`, `Holds.php`, `Inventory.php`. Serve una piccola funzione nel codice, non solo un testo.

## Contraddizioni tra testi (in «Da verificare» nei singoli file)

- `BackupPage.php:39` dice che prima del ripristino si salva lo stato attuale; l'esito in `Actions.php:1056` dice che lo stato precedente non è stato conservato.
- «Troppi tentativi: riprova tra qualche minuto», ma il blocco dura un'ora (area soci).
- `Visibility.php:28` e `86`: la stessa regola ha due etichette, «Solo soci e volontari» e «Solo volontari».
- `part9:232` dice che il sito non conserva il luogo di nascita; `part8:21` e `part9:473` dicono che il modulo lo chiede e il libro soci lo riporta.
- Portale: `account.php:87` dice che il gratuito si aggiorna caricando lo zip; `confronto.md:173` dice che si aggiorna dalla Bacheca di WordPress.
- Percorsi di menu che non tornano: «Impostazioni → Copia di sicurezza» (`FileCryptPanel.php:34, 43, 51` e altri) mentre la voce è sotto Strumenti; «Prima nota» sotto «Contabilità»; IBAN «in Pagamenti online».
- Elenco dei sistemi di pagamento diverso da testo a testo: a volte solo Stripe e PayPal, a volte anche SumUp, Satispay e WooCommerce (plugin, guide e portale).
- Le due tabelle di confronto (`portale/content/confronto.md` e `supporto/database/guides/confronto.md`) non sono più uguali.

## Decisioni trasversali da prendere una volta sola

Sono scelte di nome che toccano centinaia di righe: conviene deciderle prima di applicare le singole proposte. Le prime tre sono scelte di prodotto e spettano al titolare.

1. **«Area riservata» o «area soci».** Nel plugin «area riservata» compare in 54 file e convive con «area soci», anche nella stessa schermata; il plugin crea una voce di menu «Area riservata» (`Pages.php:156`). Sul portale «area riservata» è l'account del cliente, chiamato a volte anche «area clienti»; in quattro guide lo stesso account è chiamato «area soci» (`part1:278`, `part6:59, 141, 253`). Proposta: **«area soci»** per la pagina dei soci nel plugin, **«area riservata»** solo per l'account sul portale.
2. **«Anno sociale».** Dopo la decisione del 18/11 (tessera sull'anno fiscale, corsi ed eventi sull'«anno delle attività») restano 51 righe con «anno sociale» nel PHP del plugin, più `admin.js`, `Wizard.php:76`, il confronto del portale (righe 18 e 119) e la riga 51 del glossario in `audit/03-plugin.md`. Da riallineare tutte insieme.
3. **«Piani» o «versioni».** Sul sito Gratuito / Pro Contabile / Pro Fiscale sono chiamati piani, versioni, «Free», «versioni evolute»; «versione» è anche il numero di rilascio. Proposta: «piani» per i tre livelli, «versione» solo per i numeri.
4. **Cancellare / eliminare / annullare / disdire.** Oggi «cancellare» copre tutte e quattro. Proposta: eliminare (per sempre), annullare (una prenotazione, una data), disdire (un rinnovo).
5. **Errori di salvataggio.** «(errore del database)» in oltre venti messaggi, in cinque forme. Proposta di formula unica: «<Cosa> non salvato: riprova.»
6. **«Non autorizzato.»** in una ventina di punti. Proposta: dire chi può farlo («Solo l'amministratore può…»).
7. **«Confermi?»** come unica domanda di conferma. Proposta: la domanda dice cosa succede («Annullare la prenotazione di sabato?»).
8. **Il programma in prima persona** («Non ho trovato», «Ti propongo», «Non riesco a», «ti ricordo»). Proposta: forma impersonale o seconda persona.
9. **Oggetti delle email**: il nome dell'associazione è scritto in tre formati (« — », « · », «[Associazione]»). Proposta: uno solo.
10. **Gergo tecnico**: database, record, token, gateway, webhook, sandbox, impronta, manifest, in background, wp-admin, di default. Ogni file propone la parola comune.
11. **Guide: frasi da nota di rilascio** («come prima», «ora puoi», «non… più», «Dopo l'aggiornamento»). La guida dice solo come funziona oggi.
12. **Guide: grassetto** solo per menu e pulsanti, non per enfasi.
13. **Forma**: virgolette «…» ovunque, niente trattino lungo come inciso, niente maiuscole gridate, pulsanti con l'iniziale maiuscola.

## Avvertenze per chi applica

- I testi italiani del plugin sono anche le chiavi di `includes/languages/en.json`: ogni frase cambiata va aggiornata anche lì.
- Alcuni testi sono cercati per nome dal codice e si rinominano solo insieme al codice: «Restituzione quote» e «[Iscrizione cancellata]» (`ActivityReset.php:314, 245`), il pezzo «scrivi alla segreteria. —» (`PaymentService.php:678`, sostituito alla riga 680).
- L'informativa privacy (`PrivacyNotice.php`) e la riscrittura delle istruzioni sulla cifratura nella copia di sicurezza (`Backup.php:257`) sono testi legali o tecnici delicati: le proposte vanno lette da chi ne risponde prima di applicarle.
- Il readme del plugin gratuito va su WordPress.org: le proposte restano dentro le sue regole, ma vanno ricontrollate alla pubblicazione.
- Le proposte del plugin diventano visibili ai clienti solo con un rilascio: valgono le regole solite (un rilascio solo con il sì scritto del titolare, guide e confronto riallineati allo stesso rilascio).
