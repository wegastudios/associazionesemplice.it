# Prova da telefono sul sito vero (13/11/2026)

Come è stata fatta: le pagine pubblicate sono state caricate in uno schermo simulato largo 375 px (alto 812) dentro Chrome,
misurando con uno script larghezza, elementi fuori schermo, grandezza dei pulsanti e dei campi, e guardandole a vista.
Limiti: non è un telefono vero (niente tocco, niente tastiera a schermo); non sono entrato in nessuna area con password
(area clienti, bacheca della demo, area soci, gestionale), quindi quelle parti sono coperte solo dalla lettura del codice
negli altri report.

Pagine provate: `/`, `/funzioni`, `/domande`, `/prezzi`, `/prova`, `/accedi`, `/confronto`, `/free`, `/acquista`, `/saas`,
`/saas/acquista`, `/wordpress`, `/wordpress/changelog`, `/plugin`, `/privacy`, `/demo/` e le sue pagine pubbliche,
`app.associazionesemplice.it/accesso`, `supporto.associazionesemplice.it`.

## Bloccanti (si perde il cliente)

| # | Dove | Cosa succede | Proposta |
|---|---|---|---|
| M1 | `/acquista?plan=procontabile` e `profiscale`, mensile e annuale | La pagina dice «Questo piano non è ancora acquistabile: riprova tra poco o scrivici». I pulsanti «Scegli PRO…» di home, prezzi e confronto portano tutti qui, mentre il sito annuncia una promozione che scade il 26/11. | Se la vendita non è ancora aperta: sostituire i pulsanti con «Avvisami quando apre» o «Prova gratis 30 giorni», e togliere la scadenza della promo. Se doveva essere aperta: manca il collegamento con Stripe/PayPal. |
| M2 | `/saas/acquista` | In fondo al modulo: «Il pagamento online non è ancora attivo: scrivici e ti aiutiamo noi», ma solo dopo aver compilato soci, pacchetti e dati. | Dirlo in cima, prima del modulo, e trasformare il modulo in «Richiedi l'attivazione». |
| M3 | `/saas` → «Prova la demo del gestionale» | Porta a `demo.associazionesemplice.it`, che non esiste in rete (errore DNS). | Togliere il pulsante finché la demo non c'è (basta svuotare `saas_demo_url`), oppure attivare il sottodominio. |
| M4 | `/saas`, tabella «Funzione / Gestionale» | Tutti i pacchetti (Area soci, Risorse, Sito, Fattura elettronica, Contabilità avanzata…) hanno solo un trattino «—», senza prezzo. Chi legge capisce «non c'è». Il prezzo dei pacchetti non compare in nessuna pagina pubblica: si scopre solo nel modulo d'acquisto, premendo «Calcola il totale». | Scrivere «a parte, da X € al mese» in ogni riga e aggiungere 3 esempi già calcolati (40, 120, 400 soci). |

## Importanti

| # | Dove | Cosa succede | Proposta |
|---|---|---|---|
| M5 | `/prezzi`, `/confronto`, `/acquista` | La promozione si chiama ancora «PROMO HALLOWEEN» il 13 novembre (vale fino al 26/11). | Rinominarla («Promo di lancio», «Promo d'autunno»). |
| M6 | `/prezzi` | Per il plugin non è scritto se i prezzi sono con o senza IVA («Prezzi in euro»); per il gestionale sì («+ IVA»). Il tesoriere non sa quanto pagherà. | Stessa dicitura per entrambi, vicino al prezzo. |
| M7 | `/prezzi` | Listino barrato 180 € e 290 € all'anno, ma «risparmi 24 € / 44 € rispetto al mensile» è calcolato sul mensile in promozione (12 € e 22 €). Tre numeri diversi di «risparmio» nello stesso riquadro (barrato, -17%, risparmi X €). | Tenere un solo messaggio: prezzo di oggi, prezzo barrato, e «2 mesi in regalo con l'annuale». |
| M8 | `/prezzi` e `/saas` | In fondo alla pagina prezzi (dopo il gestionale) il riquadro «Prossimo passo: la licenza» parla solo della licenza gratuita del plugin. In `/saas` la barra fissa in basso «Prova la demo» apre la demo del plugin e «Vuoi vedere i piani?» porta al confronto del plugin. Chi cerca il gestionale viene riportato sul plugin. | Barra e riquadro finale diversi nelle pagine del gestionale: «Prova 30 giorni» / «Calcola il prezzo». |
| M9 | Home | Titolo della scheda «Il gestionale per le associazioni», ma tutta la prima schermata parla solo di «plugin per il tuo sito WordPress». Il gestionale online compare solo nel piè di pagina. Chi non ha WordPress (la maggior parte dei non tecnici) esce subito. | Prima schermata con la scelta: «Hai già un sito WordPress? → Plugin» / «Non hai un sito? → Gestionale online». |
| M10 | Home, prima schermata | Tre pulsanti quasi uguali visibili insieme: «Prova la demo» nel testo, «Prova la demo» + «Prezzi» nella barra fissa. | Nascondere la barra fissa finché i pulsanti della prima schermata sono visibili. |
| M11 | `/prova` | Lunga 12.000 px (15 schermate di telefono). Contiene tre riquadri di accesso, i piani, «Dove andare», «I ruoli del plugin» (3.400 px) e una tabella «Chi può fare cosa» a 9 colonne larga 1.230 px che scorre di lato. È un manuale, non una pagina «prova». | Tenere solo i tre accessi e 3 cose da provare; spostare ruoli e permessi in una guida del supporto. |
| M12 | `/prova` | Email e password di prova vanno a capo a metà parola («segreteria@de / mo.invalid», «Amministra / tore-Demo- / 2026»). | Carattere più piccolo o riga intera per il valore, con «Copia» sotto. |
| M13 | `/acquista` | Il prezzo si spezza su tre righe («120 / € / anno»). Titolo della scheda e descrizione sono quelli della home. | `white-space: nowrap` sul prezzo; titolo «Acquista PRO Contabilità». |
| M14 | `/saas/acquista` | 14 campi numerici «0» uno sotto l'altro per i pacchetti (anche per cose sì/no come «Sito» o «Contabilità avanzata»), con nomi tecnici («Risorse orarie, Web», «Database dedicato»), senza prezzo accanto e senza spiegazione. Il totale si vede solo premendo «Calcola il totale». Il menu «Piano» è un menu a tendina non stilizzato, alto 19 px con testo da 13 px. | Caselle da spuntare con prezzo e una riga di spiegazione; totale che si aggiorna da solo; nascondere il menu «Piano» se ce n'è uno solo. |
| M15 | `/saas` | «per qualunque dubbio c'è **il elenco** delle domande» (errore di grammatica); il pulsante «Prova la demo del gestionale» è attaccato al riquadro sopra, senza spazio. | «c'è l'elenco delle domande»; margine sopra il pulsante. |
| M16 | `/saas/prezzi`, `/wordpress/confronto`, `/partner` | Pagine previste nei documenti di progetto: oggi danno «Pagina non trovata». `/acquista` senza piano dà «Non è andata a buon fine» (errore 422). | Finché non esistono, rinviare a `/prezzi` e `/confronto`; `/acquista` senza piano → `/prezzi`. |
| M17 | Demo, «Diventa socio» (modulo del plugin) | I campi «Data di nascita», «Cellulare» e «Cellulare del genitore» non hanno lo stile degli altri: alti 21 px, testo da 13 px (su iPhone la pagina si ingrandisce da sola quando li tocchi). Le caselle di consenso sono 13×13 px. «Email» non ha l'asterisco. | Lo stile dei campi deve coprire anche `type=date` e `type=tel`; caselle da almeno 20 px con tutta la riga cliccabile. |
| M18 | Demo, tutte le pagine | La fascia blu fissa in basso occupa tre righe (circa 90 px) e copre il contenuto e i pulsanti in fondo ai moduli. | Una riga sola, richiudibile: «Demo: dati inventati · Entra». |
| M19 | Demo, piè di pagina | Restano le voci del tema di WordPress che non portano da nessuna parte (Blog, Chi siamo, FAQ, Autori, Negozio, Pattern, Temi) e «Scrivici su WhatsApp» con un numero finto. | Togliere il piè di pagina del tema. |
| M20 | Demo, «Eventi in programma» | Pagina lunga 16.000 px: ogni evento ha un'immagine segnaposto alta circa 400 px. Dicitura «Tutti i lunedì · e così via». | Immagine più bassa su telefono (rapporto 16:9) o miniatura a lato; «Ogni lunedì». |
| M21 | Supporto | Le guide sono tutte sul plugin: nessuna sezione per il gestionale online. «Registri» compare due volte (in «Plugin Pro» e in «Altro»). I collegamenti delle sottosezioni sono alti 24 px. | Sezione «Gestionale online»; un solo «Registri»; righe più alte. |

## Migliorie

- Collegamenti nel piè di pagina alti 36 px e link nel testo alti 18–20 px («Tutti i dettagli dei prezzi», «Leggi tutte le domande», «Confronta tutte le funzioni…»): portarli a 44 px o trasformarli in pulsanti secondari.
- `/prezzi`: nomi dei riquadri con stile diverso («tutto l'indispensabile!» minuscolo, «Per una gestione completa!», «Pronto anche per il RUNTS!»); «Free» in inglese accanto a «licenza gratuita».
- `/confronto`: il campo «Cerca una funzione» ha testo da 15 px (ingrandimento automatico su iPhone); l'intestazione fissa con piano e prezzo più la barra in basso lasciano poco spazio su telefoni piccoli. La vista a un piano per volta funziona bene.
- `/free`: la barra fissa «Prova la demo / Prezzi» resta sopra il modulo mentre si compila; il campo «Indirizzo del sito WordPress» non ha il suggerimento automatico (`autocomplete="url"`).
- `/wordpress/changelog`: «Versioni precedenti» alto 27 px.
- `app.associazionesemplice.it/accesso`: pulito e leggibile; manca un collegamento «Non hai ancora il gestionale?» verso il sito.

## Cosa va bene

- Nessuna pagina del portale esce dallo schermo in larghezza; un solo titolo principale per pagina; descrizioni presenti.
- Menu da telefono chiaro (7 voci, «Accedi» separato).
- Campi dei moduli del portale e del gestionale da 16 px, alti 48 px.
- Tono della demo («Clicca, inserisci, cancella: non rompi niente») adatto al pubblico.
