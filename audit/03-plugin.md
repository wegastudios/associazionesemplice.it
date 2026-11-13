# Audit 03 — Plugin: testi (UX), resa da mobile, logica

> Revisione in sola lettura del codice in `wordpress/associazionesemplice/` (versione 1.3.20) e `wordpress/associazionesemplice-pro/` (1.3.8).
> I percorsi sono relativi a `wordpress/associazionesemplice/`. Le righe sono quelle del ramo `claude/associazionesemplice-audit-4d0a79`.
> Nota di metodo: i testi del plugin sono letterali italiani nel PHP (non passano da `__()`), con traduzione in `includes/languages/en.json`: ogni riscrittura qui sotto va riportata anche come chiave in `en.json`.

Cosa è stato letto: menu e schede (`Admin/Admin.php`, `Admin/ProMenu.php`), Bacheca, configurazione guidata e guida, soci, impostazioni, cassa/prima nota/conti e fondi/anni/report/preventivo/5 per mille/rimborsi, corsi ed eventi, eliminazioni e azzeramento, copia di sicurezza, importazione, comunicazioni, ruoli, pagamenti online, licenza e pagina Pro; tutta la parte `Frontend/` (accesso, primo accesso, attivazione, domanda di adesione, area socio, tessera, prenotazioni, pagamenti, risorse, crediti, rimborsi), i moduli pubblici (`EventRequests.php`, `PublicEvents.php`), le email (`MemberLogin`, `Applications`, `Reminders`, `EventRequests`, `Holds`, `Waitlist`, `ActivityReset`, `PaymentService`, `Bank`) e i fogli di stile `assets/frontend.css`, `assets/admin.css`, `assets/frontend.js`.
Non letti a fondo (solo a campione): `Limits.php` (testi dei limiti), `Admin/ResourcesPage.php`, `Admin/TextsPage.php`, `Admin/AppearancePage.php`, registri Pro (assemblee, votazioni, inventario, convenzioni, certificati, affiliazioni), `PrivacyNotice.php`.

## I 20 problemi più gravi

| # | Area | Problema | Dove | Gravità |
|---|------|----------|------|---------|
| 1 | Pro | I nomi dei piani nel plugin sono «Pro Lite», «Pro (contabile)», «Pro Full»: non sono quelli venduti («Contabilità», «Contabilità & Fiscale») | `License.php:71`, `Admin/ProPage.php:17,27,63`, `ProBoot.php:206-208` | Bloccante (P1) |
| 2 | Pro | «Wega Studios» e `wegastudios.com` compaiono al cliente nell'elenco dei plugin | `associazionesemplice-pro.php:9-10`, `associazionesemplice.php:9` | Bloccante (P2) |
| 3 | Bacheca | Le «Domande frequenti» del gratuito mandano a schede che esistono solo con Pro, e a «Impostazioni → Copia di sicurezza» che è in Strumenti | `Admin/GuidePage.php:14-20` | Bloccante (A1) |
| 4 | Bacheca | Messaggi che rimandano a «Impostazioni → Tecniche → …», sezione che non esiste | `Admin/ImportPage.php:42`, `PeopleService.php:589`, `Donations.php:125`, `PeopleCsv.php:437,445`, `UpdateCheck.php:114` | Bloccante (A3) |
| 5 | Bacheca | Riquadro «Tesoriere»: il pulsante dice «Permetti di registrare spese» ma dà anche il permesso di incassare e iscrivere soci | `Admin/PeoplePage.php:610-615` | Bloccante (A5) |
| 6 | Logica | La tessera scade sempre il 31 dicembre, ma l'ente imposta «l'anno sociale inizia a settembre» e le schermate chiamano «anno sociale» l'anno della tessera | `Settings.php:338-341`, `Admin/PeoplePage.php:339,354,482`, `Admin/ImportPage.php:151`, `Guide.php:25` | Bloccante (L1) |
| 7 | Email | Data in formato tecnico (2026-11-20) nelle email di prenotazione ai non soci | `EventRequests.php:278,434,507`, `Holds.php:60` | Bloccante (E1) |
| 8 | Frontend | Modulo pubblico di prenotazione: frase sul pagamento incomprensibile («da inviare in ricevuta») | `EventRequests.php:151` | Bloccante (F1) |
| 9 | Frontend | «Tessera non valida: va rinnovata» senza dire come né dove | `Frontend/Actions.php:163,177,195,221,301`, `Frontend/Views.php:1436` | Bloccante (F2) |
| 10 | Frontend | Un solo errore di accesso per password sbagliata e per blocco da troppi tentativi | `Frontend/MemberLoginPage.php:26` | Bloccante (F3) |
| 11 | Mobile | Nei moduli pubblici (adesione, prenotazione) i campi Cellulare e Data di nascita non ricevono lo stile dei campi: testo sotto 16 px (zoom su iPhone), larghezza e altezza diverse | `assets/frontend.css:89,253,203` + `Frontend/Application.php:135,140`, `EventRequests.php:129,132` | Bloccante (M1) |
| 12 | Mobile | Sul telefono la barra in basso dell'area soci copre il pulsante WhatsApp fisso | `assets/frontend.css:278-282`, `Frontend/WhatsappFloat.php:44` | Importante (M2) |
| 13 | Bacheca | «Elimina una data» e «Annulla solo questa data» sono la stessa azione con due nomi e due descrizioni diverse | `Admin/ActivitiesPage.php:787-795,1006` (entrambe `asem_cancel_session`) | Importante (A9) |
| 14 | Bacheca | Rendiconto: gli accantonamenti nei fondi sono contati come «uscite dell'anno» anche se non pagati (non è «per cassa») | `Admin/ReportsPage.php:79,111` | Importante (A15/L2) |
| 15 | Bacheca | «Prezzo», «biglietto», «listino», «vendere gli eventi»: lessico commerciale accanto a «contributo» | `Admin/ActivitiesPage.php:524,560,573,896`, `Admin/PeoplePage.php:610` | Importante (A10) |
| 16 | Bacheca | «Quota» usata per quota associativa, contributo del corso, parte accantonata, restituzioni | `Admin/ActivitiesPage.php:53,110,590`, `Admin/AccountsPage.php:67` | Importante (A11) |
| 17 | Logica | Creare in anticipo un anno solare sposta da subito tutte le nuove quote sull'anno dopo, senza conferma | `Admin/YearsPage.php:45,69-72` | Importante (L3) |
| 18 | Frontend | Modulo «Diventa socio»: email e cellulare obbligatori per i maggiorenni ma senza asterisco; il riquadro del genitore è visibile finché non si scrive la data di nascita | `Frontend/Application.php:140,152`, `assets/frontend.js:175-181` | Importante (F8/L4) |
| 19 | Pro | L'invito «Collega la licenza gratuita» ricompare a ogni pagina (non resta chiuso) e lo vede anche la segreteria, che non può farci nulla | `Admin/LicenseNotice.php:19-25,48` | Importante (P4) |
| 20 | Bacheca | Privacy: «Responsabile del trattamento è il presidente» (il termine corretto è un altro) | `Admin/CommsPage.php:55-56` | Importante (A39) |

## Glossario proposto (da usare uguale ovunque)

| Termine tecnico (resta, per il commercialista) | Spiegazione semplice da affiancare la prima volta | Da non usare al suo posto |
|---|---|---|
| **Prima nota** | l'elenco di tutte le entrate e le uscite, in ordine di data | registro movimenti, cassa |
| **Movimento** | una riga della prima nota: un'entrata, un'uscita o un giroconto | registrazione, operazione, transazione |
| **Incasso / Spesa** | soldi che entrano / soldi che escono | entrata/uscita vanno bene nei totali e nel rendiconto |
| **Conto** | dove stanno i soldi: la cassa contanti, il conto in banca, il POS | cassa (se si intende un conto qualsiasi) |
| **Cassa** | i contanti dell'associazione (è uno dei conti) | — |
| **Giroconto** | spostamento di soldi da un conto all'altro (es. contanti versati in banca): non è né entrata né uscita | trasferimento, «girare denaro» |
| **Fondo accantonato** | una somma che è in cassa ma è già destinata a qualcosa (es. il rimborso a un volontario): non si può spendere per altro | «fondi» nel senso di «soldi» |
| **Fondi vincolati** | somme ricevute per uno scopo preciso (es. 5 per mille, un bando) | — |
| **Disponibilità reale** | quanto l'associazione può davvero spendere: i saldi dei conti meno i fondi accantonati | liquidità |
| **Verifica del saldo (riconciliazione)** | controllare che il saldo scritto qui sia uguale ai contanti contati o all'estratto conto | quadratura |
| **Rettifica** | movimento di correzione che riallinea il saldo dopo una verifica | — |
| **Esercizio (anno contabile)** | l'anno di cui si fa il rendiconto, dal 1° gennaio al 31 dicembre | «anno solare» da solo |
| **Anno sociale** | l'anno delle attività dell'associazione (es. da settembre ad agosto): serve per corsi ed eventi | — |
| **Rendiconto per cassa (Mod. D)** | il riepilogo annuale di entrate e uscite, contate quando i soldi entrano ed escono davvero | bilancio |
| **Preventivo / Consuntivo** | quanto si prevede di incassare e spendere / quanto si è incassato e speso davvero | — |
| **Avanzo / Disavanzo** | entrate più alte delle uscite / uscite più alte delle entrate | utile, perdita |
| **Quota associativa** | quanto si paga ogni anno per essere soci | «quota» per corsi, eventi, fondi |
| **Contributo** | quanto si paga per partecipare a un corso o a un evento | prezzo, biglietto, tariffa, quota del corso |
| **Mensilità** | il contributo di un mese di corso | rata |
| **Ricevuta** | il documento numerato che attesta un pagamento ricevuto | «ricevuta» per l'email di conferma |
| **5 per mille** | la parte delle tasse che i contribuenti possono destinare all'associazione | 5x1000, 5×1000 nei testi |
| **IVA contenuta** | la parte di IVA compresa in un importo | — |
| **Socio** | chi è iscritto all'associazione e ha la tessera | iscritto, membro, tesserato (salvo ASD verso l'ente) |
| **Ospite** | chi partecipa senza essere socio | non socio, accompagnatore, esterno |
| **Iscritto** | solo «iscritto a un corso» | per «socio» o «in regola con la quota» |
| **Prenotazione** | il posto riservato a una data di un evento | iscrizione all'evento |
| **Rinnovo** | pagare la quota associativa dell'anno nuovo (rinnovo della tessera) | tesseramento |
| **Annullare / Disdire / Eliminare** | annullare = non vale più ma resta traccia; disdire = fermare il rinnovo di un corso; eliminare = togliere per sempre | cancellare, cancellabile |
| **Segreteria** | le persone che gestiscono soci, incassi e attività | gestori, operatori, staff (che è solo per gli ingressi) |
| **Area soci** | la pagina del sito dove il socio vede tessera, prenotazioni e pagamenti | «area riservata» per l'account sul portale |
| **Collegamento (link)** | scegliere una sola parola: «link» | alternare «link» e «collegamento» |

Tono: il plugin dà già del **tu** quasi ovunque (bene). Eccezioni da uniformare: impersonale burocratico («si evadono», `Frontend/Views.php:449`), prima persona del programma («Ho rilevato WooCommerce», `Admin/MemberAccessChoice.php:35`), doppie forme con la barra («registrato/a come socio/a», `Frontend/Activation.php:43`).

---

## 1. Bacheca (admin)

### Bloccante

**A1 — Le «Domande frequenti» della versione gratuita mandano a pagine che non esistono**
`includes/Admin/GuidePage.php:14-20`
- `:16` «Registri → Presenze: scegli il corso, il mese e la lezione…» → nel menu gratuito (`Admin.php:44`) «Presenze» non c'è: è una scheda aggiunta da Pro (`ProMenu.php:21`).
- `:17` «Rubrica soci → Comunicazioni» → scheda solo Pro (`ProMenu.php:14`).
- `:18` «Il rendiconto per cassa è in Contabilità → Rendiconto» → il menu «Contabilità» esiste solo con Pro (`ProMenu.php:20`).
- `:14` «Per più persone insieme usa «Cassa per più persone»» → solo Pro (`ProMenu.php:19`).
- `:20` «Impostazioni → Copia di sicurezza» → la scheda è in **Strumenti** (`Admin.php:42`), non in Impostazioni (sbagliato in entrambe le edizioni).

Perché non va: il volontario segue la guida, non trova la voce e pensa che il plugin sia rotto. È la prima pagina che legge.
Proposta: filtrare le domande con `Edition::has()` e correggere i percorsi. Testi pronti:
- `:16` (solo con Pro) invariato; **senza Pro**: «Come registro le presenze? — Il registro delle presenze fa parte di Associazione Semplice Pro. Nella versione gratuita puoi segnare l'ingresso agli eventi dalla scheda dell'evento (Corsi ed eventi → apri l'evento → «Registra ingresso»).»
- `:17` senza Pro: «Come invio un messaggio ai soci? — Dalla scheda di un corso o evento, riquadro «Avvisi agli iscritti». L'invio a gruppi di soci (in regola, scaduti…) fa parte di Associazione Semplice Pro.»
- `:18` senza Pro: «Dove trovo l'elenco di entrate e uscite? — In Cassa → Prima nota (la prima nota è l'elenco di tutte le entrate e uscite). Per il commercialista: Strumenti → Esporta. Ricevute e rendiconto per cassa fanno parte di Associazione Semplice Pro.»
- `:20` «Come faccio una copia dei dati? — Strumenti → Copia di sicurezza: scarichi un file con tutti i dati; da lì puoi anche ripristinarlo.»

**A2 — Avviso in Bacheca con il nome di una scheda che nella versione gratuita si chiama in un altro modo**
`includes/Admin/DashboardPage.php:62` — «…inseriscilo in Impostazioni → Dati e fiscalità».
Nel gratuito la scheda si chiama «Dati dell'ente» (`Admin.php:45`); «Dati e fiscalità» è il nome con Pro (`ProMenu.php:22`).
Proposta: prendere l'etichetta dal menu (`Admin::groups()`), oppure testo neutro: «**Manca il nome dell'associazione.** Compare su ricevute, tessere e messaggi ai soci: scrivilo nei dati dell'ente.» (con il link già presente).

**A3 — Importazione: rimando a una sezione inesistente**
`includes/Admin/ImportPage.php:42` — «…si importa spegnendo le due regole in Impostazioni → Tecniche → Limiti e soglie.»
Non esiste una sezione «Tecniche»: la scheda è in Impostazioni → sezione «Sistema» → «Limiti e soglie» (`Admin.php:53`).
Proposta: «…per importare chi non ha il cellulare o il socio ospitante, spegni prima le due regole in Impostazioni → Limiti e soglie.»
Lo stesso percorso inesistente è in altri messaggi mostrati all'utente: `includes/PeopleService.php:589` («L'accesso con link è spento (Impostazioni → Tecniche → Limiti e soglie): accendilo prima.»), `includes/Donations.php:125`, `includes/PeopleCsv.php:437,445`, `includes/UpdateCheck.php:114`, e una chiave in `includes/languages/en.json:1706` («Impostazioni → Tecniche → Integrazioni», che oggi è Strumenti → Integrazioni). Sostituire ovunque con «Impostazioni → Limiti e soglie» (e «Strumenti → Integrazioni»).

**A4 — Cassa (gratuito): rimando a «Contabilità», che senza Pro non c'è**
`includes/Admin/MoneyPage.php:44` — «Per cassa: conta quando i soldi entrano ed escono, nell'anno sociale. La contabilità per anno solare è in Contabilità.»
Proposta (senza Pro): «Calcolato per cassa: contano i soldi quando entrano ed escono davvero, nell'anno sociale in corso. Per vedere i movimenti di un anno solare apri la Prima nota.» Con Pro: «…Il rendiconto dell'anno solare (esercizio) è in Contabilità.»

**A5 — Scheda socio, riquadro «Tesoriere»: stato, pulsante e spiegazione dicono tre cose diverse**
`includes/Admin/PeoplePage.php:610-615`
- stato attivo: «Può incassare, registrare spese, iscrivere soci e vendere gli eventi dall'area riservata»
- pulsante per attivarlo: «Permetti di registrare spese»
- spiegazione: «…compare la pagina "Spese" (shortcode `[associazionesemplice_spese]`): scatta lo scontrino e registra la spesa. Vede solo le spese che ha registrato…»
- e in `SettingsPage.php:98` lo stesso shortcode è descritto come «Nome precedente di «Area tesoriere»».

Perché non va: chi preme «Permetti di registrare spese» sta in realtà dando anche il permesso di **incassare denaro e iscrivere soci**; il nome della pagina («Spese») è quello vecchio.
Proposta:
- pulsante: «Nomina tesoriere» / «Togli l'incarico di tesoriere»
- stato spento: «Non è tesoriere: dall'area riservata non può incassare né registrare spese.»
- stato acceso: «È tesoriere: dall'area riservata può incassare quote e contributi, registrare spese e iscrivere nuovi soci.»
- spiegazione: «Nell'area riservata gli compare la pagina «Area tesoriere». Vede solo gli incassi e le spese che registra lui, non i saldi dei conti.» (togliere lo shortcode: resta nell'elenco in Impostazioni).

### Importante

**A6 — L'anno della tessera è chiamato «anno sociale», ma è l'anno solare**
`includes/Admin/PeoplePage.php:339` dice (giustamente) «Anno della tessera (scade il 31 dicembre)», ma la tabella subito sotto ha la colonna «Anno sociale» (`:354`), il passaggio da ospite a socio dice «Segna l'iscrizione all'anno sociale …» (`:482`), l'importazione «Segna i soci come iscritti all'anno sociale …» (`Admin/ImportPage.php:151`) e la guida «Quota associativa e anno sociale» (`Guide.php:25`). Nel codice la tessera segue sempre l'anno solare (`Settings.php:338-341`), mentre l'anno sociale parte dal mese scelto (predefinito settembre, `Settings.php:35`).
Proposta: in tutti e quattro i punti «anno della tessera» (es. colonna «Anno della tessera», «Segna la quota del 2026 come pagata»). Vedi Logica L1 per la spiegazione da dare all'utente.

**A7 — «Segna iscritto» / «Togli iscrizione»: non si capisce che si parla della quota dell'anno**
`includes/Admin/PeoplePage.php:340-342`
«Iscritto» nel plugin significa tre cose: socio dell'associazione, iscritto a un corso, in regola con la quota dell'anno.
Proposta: pulsanti «Segna la quota come pagata» / «Togli la quota di quest'anno»; aiuto: «Quando incassi una «Quota associativa» la tessera si rinnova da sola. Usa questi pulsanti solo per chi ha già pagato fuori dal programma: non registrano nessun incasso in prima nota.»

**A8 — Vocabolario per annullare/cancellare: sei verbi diversi per azioni simili**
`includes/Admin/ActivitiesPage.php`: «Cancellabile anche se a pagamento» (`:26`), «si può sempre annullare» (`:28`), «Cancella l'iscrizione» (`:412`), «Cancella» (`:720`), «Non rinnova» (`:731`), «Disdici il rinnovo» (`:738`), «Elimina una data» (`:787`), «Annulla solo questa data» (`:1006`), «Annulla prenotazione» (`:968`), «Elimina l'evento» (`:512`); `SettingsPage.php:64-66`: titolo «Eventi: cancellazioni», campo «Termine predefinito per annullare», testo «eventi creati come «cancellabili»».
Proposta di regola unica (vedi glossario):
- **Annullare** = la prenotazione, la data, la lezione, il movimento (resta traccia, non conta più).
- **Disdire** = il rinnovo di un corso (lo fa l'iscritto o la segreteria).
- **Eliminare** = cancellazione definitiva di evento, persona, preventivo (non si recupera).
- Mai «cancellare/cancellabile» per le prenotazioni.

Riscritture: `:26` «Si può annullare anche se a pagamento»; `SettingsPage.php:64` «Eventi: annullare una prenotazione»; `:66` «Vale per gli eventi in cui hai scelto «Si può annullare anche se a pagamento» senza indicare un termine. Le prenotazioni gratuite si possono sempre annullare; quelle a pagamento no, ma si può cambiare il nominativo.»; `:412` e `:720` «Elimina l'iscrizione…» (apre la pagina con doppia conferma); `:731` pulsante «Non rinnovare» → «Ferma il rinnovo a fine periodo».

**A9 — «Elimina una data» e «Annulla solo questa data» convivono nella stessa scheda con lo stesso effetto dichiarato**
`includes/Admin/ActivitiesPage.php:787-795` («Elimina una data… la data sparisce dal calendario e le prenotazioni non contano più; i pagamenti già ricevuti vanno rimborsati a mano») e `:1006` («Annullare questa data? Le prenotazioni non contano più; i pagamenti già ricevuti vanno rimborsati a mano.»).
Verificato: i due comandi chiamano la stessa azione (`asem_cancel_session`), quindi l'effetto è identico; ma il primo promette che «la data sparisce dal calendario», mentre la data annullata resta in elenco con la scritta «ANNULLATA» (`:943`).
Proposta: un solo comando «Annulla questa data» (resta in elenco con l'etichetta «annullata», i prenotati vengono avvisati); togliere «Elimina una data» o rinominarlo «Togli una data inserita per errore (senza prenotazioni)». Inoltre `:943` «ANNULLATA» → «annullata» (maiuscole incoerenti con `:962` «prenotazione annullata»).

**A10 — «Prezzo», «biglietto», «listino», «vendere»: termini commerciali in un ente non commerciale**
- `includes/Admin/ActivitiesPage.php:560` «Prezzo in euro», `:569` «Variante / Prezzo», `:573` «+ Aggiungi un prezzo», `:524` «Listino», `:585` «il prezzo del pacchetto», `:896` «far pagare il biglietto sul posto»
- `includes/Admin/PeoplePage.php:610` «vendere gli eventi», `:630` «l'incasso del biglietto sul posto»
- `includes/Frontend/ProViews.php:246,250` «biglietto socio», «Incassa il biglietto»; `includes/Frontend/Views.php:1427` `aria-label="Listino"`

Perché non va: nello stesso modulo il campo si chiama «Contributo soci / Contributo ospiti» (`:201-202`). Per il commercialista «prezzo» e «biglietto» richiamano un'attività commerciale (corrispettivi, IVA, SIAE), mentre «contributo» è il termine corretto per l'attività istituzionale. Va usato un solo termine.
Proposta: «Contributo» ovunque; «Listino» → «Tariffe»; «Prezzo» → «Importo»; «+ Aggiungi un prezzo» → «+ Aggiungi una tariffa»; «biglietto» (inteso come costo) → «contributo d'ingresso»; «Biglietto QR» può restare (è il titolo d'ingresso). `PeoplePage.php:610` → vedi A5.

**A11 — «Quota» usata per quattro cose diverse**
- quota associativa (corretto): `SettingsPage.php:52`
- contributo del corso: `ActivitiesPage.php:53` «la quota indicata è il totale», `:590` «Quota base —», `:351` «Le quote non cambiano», `BookDeskPage.php:177` colonna «Quota»
- parte accantonata: `ActivitiesPage.php:110` «Quota per il rimborso», `AccountsPage.php:67` «Libera una quota», `DeleteActivityPage.php:45` «le quote accantonate»
- restituzioni: `DeleteActivityPage.php:105` voce «Restituzione quote» (usata anche per contributi di eventi).

Proposta: «quota» **solo** per la quota associativa. Corsi/eventi: «contributo» («Contributo base —», colonna «Contributo», «I contributi non cambiano»). Fondi: «Parte per il rimborso», «Libera una parte del fondo», «le somme accantonate». Voce contabile: «Restituzione di quote e contributi».

**A12 — «Ente» e «associazione» alternati nella stessa schermata**
`MoneyPage.php:43` «Resta all'ente» contro `DashboardPage.php:48` e `ActivitiesPage.php:180` «Resta all'associazione»; `DashboardPage.php:62` «nome dell'ente»; `AccountsPage.php:86` «Disponibilità reale dell'associazione»; `SettingsPage.php:56` «L'ente accetta ospiti»; `PeoplePage.php:624` «Staff dell'ente».
Proposta: «associazione» in tutti i testi rivolti a volontari e soci; «ente» solo nei titoli fiscali («Dati dell'ente», «Ente del Terzo settore»).

**A13 — «Anno solare», «anno sociale», «esercizio»: tre concetti, mai spiegati insieme**
`YearsPage.php:43-45`, `LedgerPage.php:55` («anno solare» / «esercizio»), `ReportsPage.php:23` («Anno sociale (attività)», «Anno solare (commercialista)»), `Admin.php:43` scheda «Anni solari» dentro «Cassa».
Il volontario non sa perché deve «aprire» un anno; il commercialista cerca «esercizio».
Proposta:
- scheda «Anni solari» → «Esercizi (anni contabili)»
- `YearsPage.php:44-45` (oggi 5 frasi dense) → «**L'esercizio è l'anno contabile dell'associazione**: tutte le entrate e le uscite di quell'anno finiscono nel suo rendiconto. Puoi registrare movimenti solo in un esercizio **aperto**. L'esercizio nuovo si apre da solo il 1° gennaio; quello in corso non si può chiudere. Un esercizio chiuso si può riaprire.» e, in un secondo paragrafo: «**Quote pagate in anticipo.** Se crei in anticipo l'esercizio dell'anno prossimo (per esempio a novembre), chi si iscrive da quel momento paga la quota dell'anno prossimo e ha in omaggio i mesi che restano di quest'anno.»
- `YearsPage.php:44` «girare denaro» → «spostare denaro tra i conti (giroconto)».
- `ReportsPage.php:23` schede: «Conti e liquidità» · «Per anno sociale (attività e soci)» · «Per esercizio (rendiconto per il commercialista)».

**A14 — «Conti e fondi»: i termini contabili non sono spiegati e alcuni sono impropri**
`includes/Admin/AccountsPage.php`
- `:72,76` «Estingui e paga il rimborso», «Estingui il fondo» → «Paga il rimborso e chiudi il fondo»
- `:67` «Libera una quota» → «Rimetti una parte a disposizione dell'associazione»
- `:141` «Saldo iniziale attuale» (contraddittorio; a `:35` è «Saldo di partenza») → «Saldo di oggi (quello da cui parti)»
- `:19-24` «Verifica saldo» / «Registra una rettifica per riallineare il saldo» → titolo «Verifica saldo (riconciliazione)»; casella «Se il saldo vero è diverso, correggi quello registrato (rettifica in prima nota)»
- `:106` titolo «Fondi per i rimborsi» → «Fondi accantonati (somme messe da parte)» con la frase «Un fondo è una somma che hai in cassa ma è già destinata a qualcosa, per esempio il rimborso di un volontario: non la puoi spendere per altro.»
- `:50,76` usano `onclick="return confirm(...)"` mentre il resto usa `data-confirm`: comportamento e grafica diversi per la stessa conferma.

**A15 — Gli accantonamenti contati come «uscite dell'anno» in un rendiconto per cassa**
`includes/Admin/ReportsPage.php:79` «Gli accantonamenti sono uscite dell'anno solare in cui si registrano, anche se il rimborso non è ancora stato pagato.» e `:111` «Le uscite comprendono i fondi accantonati nell'anno…».
Per un commercialista, nel rendiconto per cassa (Mod. D, DM 5/3/2020) è uscita solo ciò che è stato **pagato**: un accantonamento non pagato non è un'uscita di cassa. Il testo dichiara apertamente il contrario.
Proposta: far verificare la regola a un commercialista (vedi Logica L2). Se la regola resta, il testo deve avvisare: «Attenzione per il commercialista: in questo prospetto le somme accantonate nei fondi sono mostrate tra le uscite anche se non ancora pagate. Nel rendiconto per cassa (Mod. D) vanno considerate solo quando il rimborso viene pagato: trovi il dettaglio nella tabella «Fondi per esercizio» qui sopra.»

**A16 — Gergo informatico mostrato a chi gestisce**
- `SettingsPage.php:63` «…al posto di wp-admin» → «…al posto del pannello di WordPress»
- `SettingsPage.php:87` paragrafo di 6 righe su Gutenberg, Elementor, Divi, Bricks, «attributi `titolo`, `vuoto`, `pulsante`» → spostare in un riquadro chiuso «Per chi costruisce il sito»; in chiaro lasciare: «Le pagine per i soci si creano con il pulsante qui sotto. Non serve altro.»
- `SettingsPage.php:102-103` «capability `asem_manage`», «API REST» → togliere o spostare sotto «Informazioni tecniche (per chi gestisce il sito)».
- `SettingsPage.php:53` «spente di default… invisibile a soci e gestori» → «All'inizio sono tutte spente: accendi solo quelle che ti servono. Ciò che è spento non lo vede nessuno.»
- `PeoplePage.php:523` e `Frontend/Views.php:695` «Scarica i dati (JSON)» → «Scarica tutti i dati di questa persona (file per la richiesta privacy)» / «Scarica i miei dati».
- `PeoplePage.php:378-380` «Passa all'accesso con link… l'utente WordPress viene eliminato» → titolo «Accesso più semplice (senza utente del sito)»; conferma: «Da ora entrerà con un link che riceve per email. I suoi dati di socio non cambiano. Continuare?»
- `WizardPage.php:142` e `Guide.php:28` «Le pagine con gli shortcode già inseriti», «Pagine del sito e shortcode» → «Le pagine già pronte» / «Pagine del sito».
- `Admin.php:42` schede «Da The Events Calendar», «WP All Import», «Integrazioni» → riunirle sotto «Altre importazioni» / «Collegamenti con altri plugin».
- `BankPage.php:31`, `FivePmPage.php:39` shortcode nel testo principale → «Per mostrarlo in una pagina del sito usa il blocco «Associazione Semplice»» (shortcode tra parentesi, in piccolo).
- `BackupPage.php:20` «Questo server non ha l'estensione zip di PHP: la copia non si può creare.» → «La copia non si può creare perché sul server manca un componente (estensione «zip» di PHP). Chiedi a chi ospita il sito di attivarlo: basta girargli questo messaggio.»
- `FivePmPage.php:25`, `BankPage.php:30` «Spento di default.» → «All'inizio è spento.»

**A17 — Menu: nomi poco chiari o doppi**
`includes/Admin/Admin.php:40-45`, `ProMenu.php:14-22`
- «Cassa → Cassa» (voce e prima scheda uguali) → scheda «Riepilogo».
- «Prenota» (scheda di Cassa; non si capisce cosa) → «Prenota per un socio».
- «Rubrica soci» + scheda «Soci e ospiti» + titolo pagina «Soci e ospiti»; in Bacheca il pulsante è «Rubrica» (`WpDashboard.php:140`) → un solo nome: «Soci e ospiti».
- «Registro azioni» → «Registro delle modifiche (chi ha fatto cosa)».
- «Limiti e soglie» → «Regole e limiti».
- «Adempimenti (5x1000)» e ovunque «5x1000» (`FivePmPage.php`, `AccountingPage.php:36`, `ResetPage.php:46`, `Frontend/ProViews.php:313`) → la dicitura ufficiale è «5 per mille»; proposta «5 per mille (5×1000)» nel titolo, «5 per mille» nei testi.
- «Bilancio provvisorio» accanto a «Rendiconto» e «Preventivo»: un ETS sotto soglia fa il **rendiconto per cassa**, non il bilancio → «Andamento dell'anno (provvisorio)» oppure «Rendiconto provvisorio».
- `Admin.php:104-105` titoli «Elimina l'evento» / «Cancella l'iscrizione» usati anche per i corsi: il riquadro «Elimina l'evento» (`ActivitiesPage.php:508-513`) viene mostrato nella scheda di qualunque attività (`:248`), corsi compresi → «Elimina l'attività» (o «Elimina il corso» / «Elimina l'evento» secondo il tipo).
- `WpDashboard.php:25` «Associazione Semplice — Da lavorare», `:90` «Niente da lavorare» contro `DashboardPage.php:127` «Da fare», `:130` «Tutto in ordine»; `SettingsPage.php:71` «riquadro «Da lavorare»» → ovunque «Da fare» / «Niente da fare: è tutto in ordine.»

**A18 — Bacheca: «Area riservata» usata per due cose opposte**
`includes/Admin/DashboardPage.php:29` «Il tuo account su associazionesemplice.it: Area riservata (licenza, dominio, pagamenti, download) · Supporto».
In tutto il plugin «area riservata» è quella dei soci sul sito dell'associazione.
Proposta: «Il tuo abbonamento ad Associazione Semplice: Il mio account (licenza, pagamenti, download) · Assistenza».

**A19 — Nuovo socio: campi con testi d'aiuto troppo lunghi e tipi di campo sbagliati**
`includes/Admin/PeoplePage.php`
- `:211` cellulare con `type="text"` → `type="tel" inputmode="tel" autocomplete="off"` (in area soci è già `tel`).
- `:231` aiuto «Tutore»: un paragrafo di 9 righe. Proposta: «Per un minorenne indica i genitori (o il tutore legale): devono essere già registrati, con un'email. Uno solo «opera sul sito»: vede tessera, prenotazioni e pagamenti del minore, paga per lui e riceve le sue email. Serve la data di nascita del minore.» + «Altri dettagli» in `<details>`.
- `:210` aiuto email (3 frasi) → «Facoltativa. Con l'email il socio riceve subito l'accesso all'area riservata; senza, gli mandi tu il link su WhatsApp.»
- `:213` codice fiscale → «Facoltativo. Se lo scrivi, il socio dovrà ripeterlo uguale quando attiva l'accesso (serve a essere sicuri che sia lui).»
- `:205` «Assegnata a mano, univoca, modificabile.» → «Il numero lo scegli tu: non può essere uguale a quello di un altro socio. Si può cambiare.»
- `:63` filtro «Tessera scaduta / senza tessera» → «Tessera scaduta o mai fatta».
- `:272` conferma «Eliminare questa persona? L'utente WordPress collegato non viene cancellato.» → non dice cosa succede a pagamenti e iscrizioni. Proposta: «Eliminare [Nome Cognome]? Sparisce dall'elenco; i suoi incassi restano in prima nota. Non si può annullare.» (il nome della persona nella conferma, come già fa `DashboardPage.php:391`).

**A20 — «Non autorizzato» senza indicazione di cosa fare**
`includes/Admin/IncomePage.php:27,52`, `includes/Admin/RefundsPage.php:27`.
Proposta: «Non hai il permesso per questa operazione. Se ti serve, chiedilo a un amministratore dell'associazione.»

**A21 — Scelta dell'accesso dei soci: testo molto lungo e tecnico, con voce in prima persona**
`includes/Admin/MemberAccessChoice.php:24-40` (oltre 250 parole; «Ho rilevato WooCommerce», «la pagina di accesso di WordPress è esposta a tentativi di indovinare le password»).
Proposta: due righe per scelta e il resto in «Più dettagli»:
- «**Accesso semplice (consigliato).** I soci entrano con la loro email e una password, oppure con un link che ricevono per email. Non diventano utenti del sito.»
- «**Accesso con utente del sito.** Sceglilo solo se i soci devono usare anche altre parti del sito con lo stesso accesso (per esempio un negozio con prezzi riservati).»
- `:35` «Ho rilevato WooCommerce.» → «Su questo sito c'è un negozio (WooCommerce).»

### Miglioria

- **A22** `DashboardPage.php:86` «Dal "Primo accesso" del sito…» virgolette dritte, altrove «caporali» (`:152` «Diventa socio»): uniformare a «…». Stesso problema in `PeoplePage.php:342,615`, `AccountsPage.php:88,114`, `SettingsPage.php:59,63`, `ImportPage.php:38,46`.
- **A23** `DashboardPage.php:152` aiuto «Domande di adesione» di 4 frasi → «Arrivano dal modulo «Diventa socio». **Approva**: scegli il numero di tessera e il nuovo socio riceve il link per entrare. **Rifiuta**: la domanda viene eliminata; se la quota era già stata pagata online resta da rimborsare.»; `:173` conferma «Rifiutare la domanda?» → «Rifiutare la domanda di [Nome]? Riceverà un'email. Non si può annullare.»
- **A24** `DashboardPage.php:383` «Soci da rinnovare» → «Tessere da rinnovare»; `:116` «Ospiti da invitare a iscriversi» e `:415` «Ospiti attesi che dovrebbero iscriversi» → «Ospiti da invitare a diventare soci» (qui «iscriversi» = diventare socio, altrove = iscriversi a un corso).
- **A25** `BookDeskPage.php:125-127,187` «Parto dalla cosa», «Scegli una cosa dall'elenco» → «Parto dall'attività» / «Scegli un corso, un evento o una risorsa».
- **A26** `IncomePage.php:163` «Da chi» / «— nessuno / anonimo —» → «Chi paga» / «— non indicato —»; `:183` «N. ricevuta (facoltativo)» → «N. della ricevuta cartacea, se l'hai già fatta (facoltativo)».
- **A27** `ExpensePage.php:36` «controlla che il conto abbia fondi sufficienti» → «soldi sufficienti» («fondi» nel plugin sono un'altra cosa).
- **A28** `LedgerPage.php:87` campo «Motivo» senza indicazione se obbligatorio; `:92` → «I movimenti non si modificano: se uno è sbagliato lo annulli (scrivendo il motivo) e lo registri di nuovo. Quelli annullati restano visibili, barrati.»
- **A29** `LedgerPage.php:24` «Togliere questo allegato dall'elenco? Resta nel registro azioni.» → «Togliere questo allegato? Il file non si potrà più aprire da qui.»
- **A30** `ImportPage.php:40-43` un solo paragrafo di ~200 parole → elenco puntato: «Obbligatorie: Nome, Cognome» · «Per i soci almeno una tra: Email, Cellulare, Numero tessera» · «Per gli ospiti: Cellulare e Ospite di» · «Facoltative: …».
- **A31** `ImportPage.php:89` «Annullare TUTTO questo import?… Non si può rifare con un clic.» → «Annullare tutta questa importazione? … Per rifarla dovrai caricare di nuovo il file.»; `:72` «Import già fatti» → «Importazioni già fatte»; `Admin.php:139` «Import con WP All Import».
- **A32** `ActivitiesPage.php:40-41` aiuto delle date ricorrenti (2 paragrafi con tre esempi) → «Ogni riga è un giorno con il suo orario. Spunta «ricorrente» per ripeterla ogni settimana fino a una data. Esempio: corso il martedì e il giovedì alle 15 = due righe ricorrenti.»
- **A33** `ActivitiesPage.php:907` «Togliere questo gestore?» (il riquadro si chiama «Staff dell'evento») → «Togliere questa persona dallo staff dell'evento?»; `:871` «i gestori dell'evento» → «lo staff dell'evento».
- **A34** `ActivitiesPage.php:686-689` «Iscrivi e vai alla cassa» / «Salva e prossimo inserimento» → «Iscrivi e incassa subito» / «Iscrivi e passa al prossimo».
- **A35** `RefundsPage.php:37` aiuto di 4 frasi: i limiti di legge sono corretti (10 € al giorno, 150 € al mese) ma annegati. Proposta in due blocchi: «I volontari chiedono il rimborso dall'area riservata; qui lo approvi e lo paghi (il pagamento va da solo in prima nota).» + riquadro «**Regole di legge**: con scontrino o fattura si rimborsa la spesa documentata. Senza documento (autocertificazione) al massimo **10 € al giorno e 150 € al mese** per volontario, e solo per i tipi di spesa decisi dall'assemblea.»
- **A36** `SettingsPage.php:57` «Eliminazione del plugin» → «Se un giorno togli il plugin».
- **A37** `ResetPage.php:37` «Ripristino di fabbrica» → «Riporta tutto com'era appena installato».
- **A39 (Importante)** `Admin/CommsPage.php:55-56` «Responsabile del trattamento è il presidente; in mancanza, il socio con il ruolo di segreteria.» Nel GDPR il **titolare** del trattamento è l'associazione (rappresentata dal presidente); «responsabile del trattamento» (art. 28) è un soggetto esterno che tratta i dati per conto del titolare (es. chi ospita il sito). Un consulente privacy lo segnalerebbe subito. Proposta: «Titolare del trattamento è l'associazione, nella persona del presidente (in mancanza, di chi ha il ruolo di segreteria).» e l'etichetta `:56` «**Titolare del trattamento (legale rappresentante):**». Controllare la stessa dicitura nel modello `includes/PrivacyNotice.php`.
- **A40** `Admin/CommsPage.php:17` titolo della pagina «Promemoria, privacy e regolamento», ma la scheda nel menu gratuito è «Privacy e regolamento» (`Admin.php:45`): chi cerca i promemoria non sa che sono lì → scheda «Promemoria e privacy». `:58` il collegamento si chiama «Dati e fiscalità» anche nel gratuito, dove la scheda è «Dati dell'ente» (come A2).
- **A41** `Admin/LedgerPage.php:87` + `LedgerService.php:528`: il motivo dell'annullamento di un movimento è facoltativo (se vuoto diventa «Annullato»). Per chi rilegge la prima nota (revisore, commercialista) un annullamento senza motivo è un problema → campo `required`, segnaposto «Motivo (obbligatorio): es. importo sbagliato».
- **A42** `Admin/Actions.php:165` «Errore imprevisto: » seguito dal messaggio tecnico; `LedgerService.php:255` «Errore del database nel salvare il movimento.»; `PeopleService.php:476` «Impossibile salvare la persona (errore del database).»; `Receipts.php:511` «…controlla i permessi della cartella temporanea.» → «Non siamo riusciti a salvare. Riprova; se succede ancora scrivi all'assistenza indicando l'ora e cosa stavi facendo.» (dettaglio tecnico solo nel registro).
- **A43** `Admin/PaymentsPage.php` (Pro): «gateway» (`:60,145,162,178`), «payout» (`:178`), «webhook» (`:69-73`), «in produzione» (`:60`), «token di accesso» (`:114`), «sandbox/live» (`:66,75,81`). Proposte: «gateway» → «il servizio di pagamento (Stripe, PayPal…)»; «payout» → «l'accredito sul conto corrente»; «Prova (test)» / «Reale» senza «live»; «Prima di usarli in produzione» → «Prima di usarli con i soci veri»; il blocco sul webhook dentro «Istruzioni tecniche (per chi configura Stripe)».
- **A44** `Admin/TechPage.php:20` «un plugin SMTP… record SPF e DKIM» → «Se le email non arrivano, chiedi a chi gestisce il sito di collegarlo a un servizio di posta (si chiama «SMTP») con un indirizzo del vostro dominio.»
- **A45** `Admin/Actions.php:869,879` «Gestore dell'evento aggiunto» / «Gestore tolto» e `ActivityService.php:893-902` «gestori» contro il riquadro «Staff dell'evento» (`ActivitiesPage.php:895`) → «Aggiunto allo staff dell'evento» / «Tolto dallo staff».
- **A38** `Admin/WizardPage.php:101` «il resto sparisce dal menu e dalle schede» → aggiungere «(lo riaccendi quando vuoi da Strumenti → Configurazione guidata)»; `:88-89` «Solo su presentazione» → «Solo tramite la segreteria».

---

## 2. Area soci e moduli pubblici (frontend)

### Bloccante

**F1 — Modulo pubblico di prenotazione: la frase sul pagamento non si capisce**
`includes/EventRequests.php:151`
Attuale: «Contributo: X a persona, da pagare in anticipo con bonifico istantaneo (te lo spieghiamo nell'email) e da inviare in ricevuta alla segreteria entro N ore dalla conferma; altrimenti il posto viene cancellato.»
«da inviare in ricevuta» non è italiano; non è chiaro cosa va inviato, a chi e da quando partono le ore.
Proposta: «Contributo: X a persona. Come funziona: 1) invii la richiesta; 2) ricevi un'email con i dati per il bonifico istantaneo; 3) entro N ore mandi alla segreteria la ricevuta del bonifico. Se la ricevuta non arriva in tempo, il posto torna libero.»
Stessa correzione per `:509` (email), `:514` («…invia la ricevuta alla segreteria entro N ore, altrimenti il posto viene cancellato» → «…altrimenti il posto torna libero») e `:638`, `:747`, `:752` (corsi: «altrimenti viene annullata» senza soggetto → «altrimenti l'iscrizione viene annullata»).

**F2 — «Tessera non valida: va rinnovata» senza dire come**
`includes/Frontend/Actions.php:163,177,195,221,301`, `includes/Frontend/Views.php:1436` («Rinnova la tessera per prenotarti»), `includes/Frontend/ResourcesFront.php:197`.
Il socio (spesso anziano, da telefono) resta bloccato: non c'è né un link né un'indicazione.
Proposta (una sola frase ovunque, con link alla sezione Pagamenti dell'area se i pagamenti sono attivi): «La tua tessera è scaduta. Per prenotare rinnova la quota: [Vai ai pagamenti] oppure rivolgiti alla segreteria.» Per un familiare: «La tessera di [Nome] è scaduta: prima rinnova la sua quota.»

**F3 — Errore di accesso unico per tre cause diverse**
`includes/Frontend/MemberLoginPage.php:26` — «Email, codice o password non corretti, oppure troppi tentativi: riprova tra qualche minuto.»
Chi ha solo sbagliato la password crede di dover aspettare; chi è bloccato continua a riprovare.
Proposta: due messaggi. Errore: «Email o password non corretti. Controlla e riprova, oppure premi «Mandami un link» per entrare senza password.» Blocco: «Troppi tentativi. Aspetta 10 minuti e riprova, oppure chiedi aiuto alla segreteria.» (i minuti reali del blocco).

### Importante

**F4 — Messaggi d'errore generici o tecnici mostrati al socio**
- `Frontend/Actions.php:72,104`, `ResourcesFront.php:45`, `CreditsFront.php:41` «Operazione non riuscita. Riprova più tardi.» → «Non siamo riusciti a completare l'operazione. Riprova tra qualche minuto; se succede ancora scrivi alla segreteria.»
- `Frontend/Actions.php:94` «Metodo non consentito.» → «Questa pagina non si può aprire direttamente. Torna all'area riservata e riprova.»
- `Frontend/Actions.php:127`, `ResourcesFront.php:63`, `CreditsFront.php:71` «Serve un accesso come socio.» → «Per continuare devi entrare nell'area riservata.» (con link)
- `MemberLoginPage.php:95`, `Application.php:80`, `EventRequests.php:174` «Sessione scaduta: ricarica la pagina e riprova.» → «È passato troppo tempo. Aggiorna la pagina e riprova: i dati vanno riscritti.»
- `Frontend/Views.php:165` «Servizio temporaneamente sospeso. Contatta l'associazione.» → «L'area riservata non è disponibile in questo momento. Per urgenze scrivi alla segreteria.»
- `EventRequests.php:502,739` «Non è stato possibile completare la prenotazione (…)» mostra tra parentesi l'errore interno → togliere il dettaglio tecnico.
- `EventRequests.php:447,787` «Richiesta non trovata o già lavorata.» → «Questa richiesta è già stata approvata o rifiutata.»
- `PayReturn.php:34,49` «…lo registreremo appena Stripe o PayPal lo confermano.» → «Non riusciamo ancora a vedere il pagamento. Se hai pagato non devi fare nulla: lo registriamo appena arriva la conferma (di solito entro pochi minuti).»

**F5 — «Non annullabile (gestiscila nella tua area)»**
`includes/Frontend/Views.php:1359` — dice che non si può annullare e insieme di gestirla altrove.
Proposta: «Non si può annullare da qui. Nella tua area puoi cambiare il nominativo.»

**F6 — Stato del pagamento «in registrazione»**
`includes/Frontend/Views.php:369-370` — «in attesa di conferma», «in registrazione», «non riuscito».
Proposta: «in attesa di conferma» · «pagato, lo stiamo registrando» · «non riuscito: puoi riprovare».

**F7 — Conferma generica «Confermi?»**
`includes/Frontend/Views.php:99`, `ResourcesFront.php:145`, `CreditsFront.php:222` — è il testo usato per annullare una prenotazione, uscire dalla lista d'attesa, annullare un addebito.
Proposta: passare sempre un testo specifico: «Annullare la prenotazione del [data]?», «Uscire dalla lista d'attesa?», «Annullare questo addebito?».

**F8 — Modulo «Diventa socio»: la parte per i minorenni è rivolta alla persona sbagliata**
`includes/Frontend/Application.php:152-153` — legenda «Hai meno di 18 anni? Indica un genitore o tutore» e sotto «Se sei minorenne l'iscrizione la fa un genitore o tutore».
Il modulo dà del tu al minore e poi dice che deve compilarlo il genitore. Inoltre il riquadro è visibile finché non si scrive la data di nascita: lo script lo nasconde solo quando risulta un maggiorenne (`assets/frontend.js:175-181`: `box.hidden = a === true`), quindi chi compila dall'alto vede subito una sezione che quasi mai lo riguarda (e senza JavaScript resta sempre).
Proposta: nasconderlo di partenza e mostrarlo solo quando dalla data di nascita risulta un minore (`box.hidden = a !== false`), con testo: «**Iscrizione di un minorenne.** La domanda la compila un genitore (o il tutore): sopra i dati del ragazzo o della ragazza, qui i tuoi.»

**F9 — «Prenota senza iscriverti»: ambiguo**
`includes/EventRequests.php:154` — titolo «Non sei socio?», pulsante «Prenota senza iscriverti». «Iscriversi» nel sito vuol dire anche iscriversi all'evento.
Proposta: «Prenota come ospite (senza diventare socio)». Per i corsi `:641` «Chiedi l'iscrizione senza essere socio» → «Chiedi di iscriverti al corso come ospite».

**F10 — Tre nomi per chi non è socio**
«ospite» (area soci, bacheca), «non socio» (`PublicEvents.php:341,345`, `DashboardPage.php:116`), «accompagnatore» (`EventRequests.php:140,223`), «amici ospiti» (`GroupCashPage.php:56`), «partecipante» (`Views.php:360`).
Proposta: **ospite** = chi partecipa senza essere socio (sempre). «Accompagnatore 1» → «Persona 2, Persona 3…» (possono essere figli, non accompagnatori). `PublicEvents.php:341` → «Anche gli ospiti (chi non è socio) possono chiedere un posto da questa pagina».

**F11 — Area soci: «Il mio profilo» usa campi senza tipo adatto al telefono**
`includes/Frontend/Views.php:685` `<input type="text" name="phone">` → `type="tel" inputmode="tel" autocomplete="tel"`; `:686` codice fiscale senza `autocapitalize="characters"`; `:688` CAP senza `inputmode="numeric"`. Vedi sezione Mobile.

**F12 — «Il mio profilo»: «Per cambiare nome o email scrivi all'associazione.»**
`includes/Frontend/Views.php:693` — non dice come. Proposta: «Per cambiare nome o email scrivi alla segreteria» + il pulsante WhatsApp/email della segreteria se impostato (`SettingsPage.php:76`).

**F13 — «I miei figli» / «Sei il tutore di queste persone»**
`includes/Frontend/Views.php:640` — il titolo dice «figli», il testo «tutore», e «queste persone» è freddo.
Proposta: titolo «Le persone che segui»; testo «Sei il genitore (o tutore) di chi vedi qui sotto: puoi vedere la sua tessera, prenotare e pagare per lui o lei. Le email che lo riguardano arrivano anche a te.»

**F14 — Accesso: «Primo accesso» spiegato in una vista e non nell'altra**
`includes/Frontend/Views.php:126` (tre link in fila: «Password dimenticata? · Entra con un link via email · Primo accesso») e `:133` (con spiegazione «non hai ancora una password»).
Proposta unica, su tre righe distinte (target più grandi sul telefono): «**È la prima volta?** Attiva il tuo accesso» · «Hai dimenticato la password?» · «Entra senza password: ricevi un link per email».

**F15 — Attivazione: «potrai riceverne una nuova per email»**
`includes/Frontend/Activation.php:69,160` — la password non viene spedita: arriva un link per sceglierne un'altra.
Proposta: «Se la dimentichi, ti mandiamo per email un link per sceglierne un'altra.»

**F16 — Attivazione: il cellulare è obbligatorio ma non è detto**
`includes/Frontend/Activation.php:164-170` — «La tua email», «Il tuo cellulare» sono `required` senza segno; solo la Provincia dice «(facoltativa)» e solo le password dicono il vincolo. `:165` dice «I tuoi dati (obbligatori)» solo in certi casi.
Proposta: in testa al modulo «Tutti i campi sono obbligatori, tranne la provincia.»

**F17 — Segreteria nell'area soci: «da evadere»**
`includes/Frontend/Views.php:449` «richieste di accesso da evadere: si evadono dalla Bacheca» → «richieste di accesso da controllare: le trovi in Bacheca».

**F18 — Donazioni e 5 per mille**
`includes/Frontend/Views.php:1269` «Questa raccolta è spenta.» → «Questa raccolta fondi è chiusa.»; `:1279` «Un altro importo (lo scegli su PayPal)».
`includes/Frontend/ProViews.php:313` titolo «5x1000» → «Il tuo 5 per mille».

### Miglioria

- **F19** `Frontend/Actions.php:225` «(posizione N): se si libera un posto vieni prenotato automaticamente e ricevi un'email» → bene; aggiungere «Se non puoi più venire, esci dalla lista.»
- **F20** `Frontend/Actions.php:336` e `ResourcesFront.php:72` «completa i tuoi dati (indirizzo e codice fiscale) nel riquadro «Il mio profilo»» → aggiungere il collegamento diretto alla sezione.
- **F21** `Frontend/Actions.php:609` «Non è possibile aggiungere questo numero come tuo ospite: scrivi alla segreteria.» → spiegare: «Questo numero di cellulare è già di un socio: non può essere registrato come ospite.» (se è questo il caso controllato).
- **F22** `Frontend/Views.php:297` «Non sei ancora iscritto a nessuna attività.» → stato vuoto senza passo successivo: aggiungere «[Guarda corsi ed eventi]».
- **F23** `Frontend/Views.php:382` «Non hai nulla da pagare al momento.» bene. `:289 ProViews.php` «Nessun pagamento registrato finora.» bene.
- **F24** `Frontend/Views.php:1037-1040` schede «Il mio spazio», «Segreteria», «Gestione corsi», «Gestione eventi»; altrove «Area riservata», «area soci», «la tua area» (`EventRequests.php:434`, `Views.php:1359`), «Area riservata ai soci» (`:124`). Proposta: nome unico «Area soci» per i soci; «area riservata» solo nei testi per chi gestisce.
- **F25** `Frontend/FirstAccess.php:165` ultima frase «L'accesso è riservato a chi è già iscritto: l'iscrizione si fa su presentazione, rivolgendosi alla segreteria.» → «Questa pagina è per chi è già socio. Per diventare socio rivolgiti alla segreteria.»
- **F26** `Frontend/Activation.php:43` «sei stato/a registrato/a come socio/a» → «la segreteria ti ha registrato tra i soci» (evita le barre).
- **F27** `Frontend/CardVerify.php:87` e `TicketVerify.php:106` «Può essere stato sostituito» → «Forse è un codice vecchio».
- **F28** `PublicEvents.php:567` «Evento annullato. Ci dispiace: le date previste non si terranno.» bene; aggiungere per chi ha pagato: «Se avevi già versato il contributo, la segreteria ti contatterà per la restituzione.»
- **F29** `includes/Bank.php:19` «Non appena riceviamo il bonifico, registriamo il pagamento.» → aggiungere i tempi: «(di solito 1-3 giorni lavorativi)».

---

## 3. Pro e upgrade

Giudizio d'insieme: nel gratuito gli inviti a Pro sono **pochi e onesti** (una riga in Cassa, `Admin/MoneyPage.php:65`; una pagina «Associazione Semplice Pro», `Admin/ProPage.php`; un messaggio quando si apre una funzione non compresa, `Edition.php:141-156`). Non ci sono finestre a comparsa, conti alla rovescia o funzioni di base che si spengono. I problemi sono di **nomi** e di **chiarezza**.

### Bloccante

**P1 — I nomi dei piani non sono quelli venduti**
- `includes/License.php:71` — etichette: «Gratuito», «Pro Lite», «Pro (contabile)», «Pro Full»
- `includes/Admin/ProPage.php:17` «Pro Lite», `:27` «Pro Full (include tutto il Pro Lite)», `:63` «Pro Lite comprende… Pro Full aggiunge…»
- `includes/ProBoot.php:206` «…fa parte di Associazione Semplice Pro (Lite o Full): la tua è la licenza gratuita.», `:208` «…fa parte della licenza Associazione Semplice Pro Full: la tua licenza è Pro Lite, senza la parte contabile.»
- `wordpress/associazionesemplice-pro/readme.txt:96,192,204` «Contabile, Pro Full», «Pro Lite and Pro Full»; `readme.txt:405` (gratuito).

Perché non va: i piani venduti si chiamano «Contabilità» e «Contabilità & Fiscale». Chi compra «Contabilità & Fiscale» e legge nel pannello «Livello: Pro Full» non riconosce ciò che ha pagato; inoltre nel codice i livelli a pagamento sono tre (`PLAN_LITE`, `PLAN_ACCOUNTING`, `PLAN_FISCAL`) mentre la pagina Pro ne presenta due.
Proposta (da confermare con chi tiene il catalogo: il nome venduto del livello `PLAN_LITE` non risulta dal codice):
- `License.php:71` → `PLAN_ACCOUNTING => 'Contabilità'`, `PLAN_FISCAL => 'Contabilità & Fiscale'`; meglio ancora leggere il nome dal piano firmato dal portale (il codice lo prevede già: «se un piano cambia nome la licenza resta valida»).
- `ProPage.php:17,27` titoli dei due blocchi con i nomi venduti; `:63` → «Il piano «Contabilità» aggiunge conti e fondi, ricevute, report e rendiconto per cassa. «Contabilità & Fiscale» aggiunge IVA, 5 per mille, andamento dell'anno e controlli di fine esercizio. Prezzi e dettagli su associazionesemplice.it.»
- `ProBoot.php:206` → «Questa funzione è compresa nei piani a pagamento di Associazione Semplice. Ora stai usando la versione gratuita.»; `:208` → «Questa funzione è compresa nel piano «Contabilità & Fiscale». Il tuo piano è «[nome del piano]».»

**P2 — «Wega» compare al cliente**
- `wordpress/associazionesemplice-pro/associazionesemplice-pro.php:9-10` — `Author: Wega Studios`, `Author URI: https://www.wegastudios.com`
- `wordpress/associazionesemplice/associazionesemplice.php:9-10` — `Author: Wega Studios`, `Author URI: https://profiles.wordpress.org/wegastudios/`

Queste righe sono mostrate in Plugin → Plugin installati («Di Wega Studios») a ogni amministratore. La regola decisa è che il nome compaia solo tra i Contributors su WordPress.org (`readme.txt:2`).
Proposta: `Author: Associazione Semplice`, `Author URI: https://associazionesemplice.it` in entrambi i file (nel gratuito verificare cosa richiede WordPress.org per l'autore: se serve un profilo, lasciare il solo `Author URI` del profilo con `Author: Associazione Semplice`). Negli altri file «Wega» compare solo in commenti del codice (`License.php`, `LicenseClient.php`), non visibili.

### Importante

**P3 — Tre nomi e due percorsi per la stessa cosa (account e licenza)**
- dove si gestisce l'account: «Area riservata (licenza, dominio, pagamenti, download)» (`Admin/DashboardPage.php:29`), «dall'area riservata del portale» (`Admin/LicensePanel.php:32`, `LicenseClient.php:140`), «dalla tua area clienti» (`Admin/UpdateNotice.php:35`, `Updater.php:292,299`), «dall'area riservata di associazionesemplice.it» (`Admin/LicenseNotice.php:38`, `ProBoot.php:227`)
- dove si inserisce la chiave: «Impostazioni → Soci e quote → Licenza» (`ProBoot.php:227`), «Impostazioni → Licenza» (`LicensePolicy.php:64`), «in Impostazioni» (`ProBoot.php:205`).

Proposta: un solo nome, «il tuo account su associazionesemplice.it», e un solo percorso scritto uguale, «Impostazioni → Soci e quote, riquadro «Licenza»» (o meglio una scheda «Licenza» propria).

**P4 — L'invito «Collega la licenza gratuita» torna a ogni pagina e lo vede anche la segreteria**
`includes/Admin/LicenseNotice.php:19-25` (nessun controllo sui permessi) e `:48` (`is-dismissible` senza memoria della chiusura, a differenza degli altri avvisi che usano `Dismiss::html()`, es. `DashboardPage.php:62`).
Chi lo chiude lo ritrova alla pagina dopo; la segreteria non può aprire le Impostazioni (`Admin.php:57`) e quindi non può farci niente. È l'unico punto in cui il plugin risulta insistente.
Proposta: mostrarlo solo agli amministratori, solo in Bacheca e nelle Impostazioni, con chiusura ricordata. Testo: «**Vuoi ricevere gli avvisi di aggiornamento?** Richiedi la chiave gratuita (serve solo l'email) e incollala qui. Il plugin funziona anche senza.» — togliere «abilita la prova del piano Pro», che non spiega che cosa si prova né per quanto.

**P5 — Messaggi di licenza che non dicono cosa fare**
- `LicenseClient.php:137` «Licenza sospesa dal fornitore.» → «La licenza è sospesa. Apri il tuo account su associazionesemplice.it per vedere il motivo, oppure scrivi all'assistenza.»
- `LicenseClient.php:138` «Licenza revocata.» → «Questa chiave non è più valida. Controlla nel tuo account qual è la chiave attiva.»
- `LicenseClient.php:139` «La licenza non è ancora iniziata.» → «La licenza parte dal [data]: fino ad allora restano attive le funzioni gratuite.»
- `License.php:242,253` «La risposta del server delle licenze non ha una firma valida…», «…non porta il piano firmato…» → «Non siamo riusciti a controllare la licenza (risposta non valida). Non è cambiato nulla: riprova tra qualche ora; se continua scrivi all'assistenza.»
- `LicenseClient.php:166,169` «(codice …)» → lasciare il codice tra parentesi ma prima dire «Il controllo della licenza non è riuscito: riprova più tardi. Nel frattempo non cambia nulla.»
- `License.php:228` «Indirizzo del sito non utilizzabile per la licenza (serve un nome di dominio).» → «Questo sito non ha ancora un indirizzo definitivo (es. www.nomeassociazione.it): la licenza si collega quando il sito è online.»
- `LicenseRules.php:152` «Sono già attive 2 installazioni su questo dominio: disattivane una per liberare il posto.» → aggiungere dove: «…dal tuo account su associazionesemplice.it».

**P6 — Licenza non in regola: il testo dice che «il resto funziona», ma un'impostazione parla di accesso dei soci sospeso**
`Admin/LicenseNotice.php:35` «…sono sospese; il resto funziona e i tuoi dati sono al sicuro.» contro `ProBoot.php:257` «Un problema di rete o del portale sospende le funzioni Pro **(e l'accesso dei soci)** più in fretta.» e `Frontend/Views.php:165` «Servizio temporaneamente sospeso. Contatta l'associazione.»
Se con la licenza scaduta i soci non entrano più nell'area soci, l'avviso deve dirlo chiaramente; se non è così, va corretta la frase in `ProBoot.php:257`. Proposta (se l'accesso viene davvero sospeso): «Le funzioni a pagamento sono sospese **e i soci non possono entrare nell'area soci** finché la licenza non torna in regola. Soci, prima nota e tutti i dati restano al loro posto.»

**P7 — Pagina «Associazione Semplice Pro»: descrizioni con gergo**
`Admin/ProPage.php:18` «il checkout di WooCommerce» → «o il negozio del sito (WooCommerce)»; `:20` «inviate in background» → «anche a centinaia di persone, senza tenere aperta la pagina»; `:34` «utile eccessivo», «inizio dell'anno fiscale a scelta» → «avvisi se le spese superano le entrate o se l'avanzo è troppo alto per un ente senza scopo di lucro; esercizio che può iniziare in un mese diverso da gennaio»; `:53` «in due livelli di licenza» → «in due piani».

### Miglioria

- **P8** `Edition.php:141` «Questa funzione fa parte di Associazione Semplice Pro.» + `:154` «Scopri cosa comprende Associazione Semplice Pro →»: chiaro e non invadente. Aggiungere solo «(la versione a pagamento)» alla prima occorrenza: «Pro» da solo non dice che si paga.
- **P9** `Admin/UpdateNotice.php:35` «carica il nuovo pacchetto da Plugin → Aggiungi nuovo: i dati restano.» → «…da Plugin → Aggiungi nuovo → Carica plugin, e conferma «Sostituisci». Soci e prima nota non si toccano.»
- **P10** `associazionesemplice-pro.php:33` «…aggiorna Associazione Semplice Pro e Associazione Semplice all'ultima versione. Intanto le funzioni avanzate sono sospese; il resto funziona e i dati sono al sicuro.» Bene: dice cosa fare e rassicura. Tenerlo come modello per gli altri messaggi di licenza.
- **P11** `Admin/GuidePage.php:19` «Come cambio i testi o i termini? Con Associazione Semplice Pro…»: onesto; mettere la risposta gratuita per prima («Nome dell'associazione, colori e messaggi principali si cambiano in Impostazioni → Aspetto») e Pro per seconda.

---

## 4. Email

Le email sono in testo semplice, brevi, con il tu e con «Se non sei stato tu, ignora questo messaggio»: bene, si leggono su qualunque telefono.

### Bloccante

**E1 — Data scritta in formato tecnico nelle email ai non soci**
`includes/EventRequests.php:278` («hai chiesto 2 posti per «Cena sociale» del **2026-11-20**»), `:434`, `:507` («il tuo posto per … del 2026-11-20 alle 21:00 … è prenotato»), `includes/Holds.php:60`.
Nelle altre email la data è nel formato italiano (`Waitlist.php:131`, `Reminders.php:175`, `Notices.php:152`: `format('d/m/Y')`).
Proposta: usare lo stesso formato, meglio con il giorno della settimana: «di venerdì 20 novembre 2026 alle 21:00» (`Frontend\Views::date_long()` esiste già, `EventRequests.php:118`).

### Importante

**E2 — Email di prenotazione dei non soci: le istruzioni di pagamento sono un'unica frase lunga**
`includes/EventRequests.php:509` — «Per tenerlo, paga X con bonifico istantaneo entro N ore e invia la ricevuta alla segreteria (email), indicando il nome dell'evento e il tuo cognome. Se la ricevuta non arriva entro il termine il posto viene cancellato.»
Proposta:
«Per confermare il posto:
1. fai un bonifico istantaneo di X con i dati qui sotto;
2. entro N ore (cioè entro [giorno e ora]) manda la ricevuta del bonifico a [email] scrivendo «[evento] – [cognome]».
Se la ricevuta non arriva in tempo il posto torna libero.»
Stessa struttura per i corsi (`:747`). `:512` «Per disdire scrivi alla segreteria.» → aggiungere l'indirizzo o il numero WhatsApp (già noto: `SettingsPage.php:76`).

**E3 — «Ricevuta del pagamento» usata per una semplice conferma**
`includes/PaymentService.php:732` — oggetto «Ricevuta del pagamento — [associazione]».
Nel plugin (e per il commercialista) la «ricevuta» è il documento numerato in PDF (`Receipts.php:519` «Ricevuta n. …»). Due cose diverse con lo stesso nome nella casella del socio.
Proposta: oggetto «Pagamento ricevuto — [associazione]»; in fondo: «Questa email è una conferma, non la ricevuta: la ricevuta la trovi nella tua area soci, alla voce «Le mie ricevute».» (se la funzione è attiva).

**E4 — Promemoria della tessera: «se attivi» e indicazioni diverse tra le due email**
`includes/Reminders.php:175` «Puoi rinnovarla in segreteria o, se attivi, online dall'area riservata.» — «se attivi» non si capisce (attivi che cosa?); il programma sa se i pagamenti online sono accesi e può scrivere la frase giusta.
`:179` (tessera scaduta) dice solo «rivolgiti alla segreteria», anche quando si può pagare online. `:256` «o, se attivo, online».
Proposta: con pagamenti online accesi: «Puoi rinnovarla subito online dalla tua area soci: [link], oppure in segreteria.» Con pagamenti spenti: «Per rinnovarla rivolgiti alla segreteria.» + (se attivo il bonifico, il blocco con l'IBAN c'è già, `:113`).

**E5 — Oggetti senza il nome dell'associazione, o con tre formati diversi**
- senza nome: `MemberLogin.php:124` «Reimposta la password», `:129` «Il tuo accesso», `ActivityReset.php:416` «Evento annullato: …», `Bank.php:194` «Coordinate per il bonifico»
- con prefisso tra parentesi quadre: `Reminders.php:116` «[Associazione] La tua tessera sta per scadere»
- con suffisso: `Applications.php:180`, `EventRequests.php:513` (`assoc_suffix()`), `PaymentService.php:732` (« — Associazione»).

Un'email «Il tuo accesso» senza mittente riconoscibile sembra un tentativo di truffa e finisce ignorata, soprattutto da un socio anziano.
Proposta: un solo formato, «[Nome associazione] Oggetto», applicato in `Texts::mail()` per tutte. Oggetti: «Il link per entrare nell'area soci», «Scegli una nuova password».

**E6 — Rifiuto della domanda: oggetto vago e nessun contatto**
`includes/Applications.php:364-366` — oggetto «Domanda di adesione»; testo «purtroppo la domanda di adesione non è stata accettata… Per chiarimenti rivolgiti alla segreteria.»
Proposta: oggetto «La tua domanda di adesione non è stata accettata»; chiusura con l'email/telefono della segreteria. Se la quota è stata pagata: «La quota che hai pagato online ti sarà restituita **dalla segreteria entro qualche giorno**» (la restituzione è manuale: `Admin/PaymentsPage.php:162`).

### Miglioria

- **E7** `link` e `collegamento` alternati: `EventRequests.php:29,279,321-331` («apri il collegamento», «Il collegamento è scaduto») contro `MemberLogin.php:121`, `Frontend/FirstAccess.php:22`, `Frontend/Activation.php:66` («link»). Scegliere «link».
- **E8** Firma dei promemoria `Reminders.php:115`: «— / [associazione] / Area riservata: URL / Per informazioni rivolgiti alla segreteria.» → aggiungere il contatto della segreteria e usare «Area soci».
- **E9** `Reminders.php:293` «Se non puoi venire, annulla dall'area riservata (se l'evento lo permette).» → il programma sa se la prenotazione si può annullare: scrivere «annulla dalla tua area soci» oppure «avvisa la segreteria».
- **E10** `Reminders.php:78` «il rientro di «…» era previsto per il …» bene, tono gentile. `Holds.php:60` «è stato liberato perché il contributo non è arrivato entro N ore» → aggiungere «Se hai già pagato, scrivi subito alla segreteria.»
- **E11** `Frontend/Activation.php:43-44` (messaggio WhatsApp) «sei stato/a registrato/a come socio/a» → «la segreteria di [associazione] ti ha registrato tra i soci.»
- **E12** `MemberLogin.php:121-127` «Valgono 15 minuti e si usano una volta sola.» bene; 15 minuti sono pochi per chi legge la posta da un altro dispositivo: dirlo nella pagina prima dell'invio (`Frontend/MemberLoginPage.php:273` lo fa già).
- **E13** `ActivityReset.php:413` «l'evento «…» è stato annullato… Ci dispiace per il disagio.» → aggiungere la data dell'evento (chi è prenotato a più date dello stesso evento non sa quale).

---

## 5. Mobile

Base buona: l'area soci ha barra di navigazione in basso, campi a 16 px e pulsanti da 44 px sotto i 700 px (`assets/frontend.css:193-204,251-256,276-287`); la bacheca ha campi da 48 px/16 px (`assets/admin.css:187-191`), elenco soci e prima nota a schede (`:83-97`, `:277-289`) e pagine di accesso/attivazione con campi da 16 px e pulsanti grandi. I problemi restano ai bordi.

### Bloccante

**M1 — Moduli pubblici: Cellulare e Data di nascita fuori dallo stile dei campi (zoom su iPhone)**
- Lo stile dei campi vale solo per `input[type="text"]`, `[type="email"]`, `[type="password"]` e `select` (`assets/frontend.css:89-91` e `:253-255`).
- La regola per il telefono che copre tutti i tipi (`:203`) vale solo dentro `.asemf-section`.
- I moduli pubblici non sono dentro `.asemf-section`: «Diventa socio» è in `<div class="asemf asemf-apply">` (`Frontend/Application.php:104,113`), la prenotazione in `<section class="asemf-guest-request">` (`EventRequests.php:154,641`).
- In quei moduli ci sono `type="date"` (`Application.php:135`, `EventRequests.php:129,141,630`) e `type="tel"` (`Application.php:140,155`, `EventRequests.php:132,633`).

Effetto: su iPhone i campi Data di nascita e Cellulare hanno il carattere predefinito del browser (sotto 16 px → la pagina si ingrandisce al tocco), non sono larghi quanto gli altri e sono più bassi. Sono proprio i moduli compilati da chi non conosce il sito.
Proposta (una riga): estendere i due selettori a tutti i campi —
`.asemf input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]), .asemf select, .asemf textarea { width:100%; min-height:48px; font-size:16px; … }`
Lo stesso vale nell'area soci sopra i 700 px (tablet) per `tel`, `date`, `number`, `search`, `textarea` (`Frontend/Views.php:358,549,624,847,894`).

### Importante

**M2 — La barra in basso dell'area soci copre il pulsante WhatsApp**
`assets/frontend.css:278-282` (`.asemf-jump` fissa in basso, `z-index: 9999`, alta almeno 52 px) e `includes/Frontend/WhatsappFloat.php:44` (`.asem-wa-float` fisso a `bottom:16px`, `z-index:9998`).
Sotto i 700 px il pulsante «Scrivici su WhatsApp» finisce sotto la barra, proprio nella pagina in cui il socio ha più bisogno di chiedere aiuto.
Proposta: `body:has(.asemf-jump) .asem-wa-float { bottom: calc(72px + env(safe-area-inset-bottom)); }`.

**M3 — Tocchi sotto i 44 px nelle pagine pubbliche degli eventi**
`assets/frontend.css:330` `.asemf-chip { padding:.35rem .8rem; font-size:.875rem }` → circa 32 px di altezza. Usato per i filtri degli eventi (`PublicEvents.php:920-927`), mese precedente/successivo del calendario (`:963-965`) e «Condividi» (`:677`). Le pagine pubbliche non sono dentro `.asemf-section`/`.asemf-area`, quindi la regola dei 44 px (`:199-200`) non le copre.
Proposta: `.asemf-chip { min-height:44px; display:inline-flex; align-items:center; padding:0 1rem; font-size:1rem }`.

**M4 — «Apri e chiudi» (`<summary>`) piccoli nell'area soci**
`assets/frontend.css:38-39,373-374`: `.asemf-details > summary` e `.asemf-more > summary` hanno solo `cursor` e grassetto; la regola dei 44 px riguarda `a` e `button`, non `summary`.
Sono i comandi «Cambia nominativo» (`Frontend/Views.php:359`), «Biglietto QR» (`:330`), «Storico prenotazioni» (`:310`), «Aggiungi un ospite» (`:625`), «Invia un avviso agli iscritti» (`:895`), «Prenoti anche per altre persone?» (`EventRequests.php:136`), «＋ Ingresso sul posto» (`Frontend/ProViews.php:246`). Una riga di testo alta circa 24 px, senza aspetto da pulsante: un socio anziano non capisce che si tocca.
Proposta: `.asemf summary { min-height:44px; display:flex; align-items:center; gap:.5rem; padding:0 .75rem; border:1px solid var(--asemf-line); border-radius:12px; }` (come la bacheca fa in parte: `assets/admin.css:304`, 36 px).

**M5 — Caselle di consenso piccole**
Le caselle «Ho letto e accetto…», «Acconsento…», «Dichiaro di essere il genitore…» (`Frontend/Application.php:157-166`, `EventRequests.php:146-150`, `Frontend/Views.php:659-662,1236`) usano la classe `asemf-check`, che in `assets/frontend.css` non ha nessuna regola: resta la casella del browser (circa 13 px) accanto a tre righe di testo.
Proposta: `.asemf-check { display:flex; gap:.75rem; align-items:flex-start; padding:.5rem 0; } .asemf-check input { width:24px; height:24px; flex:none; margin-top:.15rem; }`.

**M6 — Bacheca dal telefono: pulsanti piccoli e ravvicinati per azioni che contano**
- `assets/admin.css:183` `.button-small { min-height:36px }`: sono i pulsanti «Approva» / «Rifiuta» delle domande (`Admin/DashboardPage.php:169-173`), «Incassa» / «Sospendi» (`:388-391`), «Annulla prenotazione» (`Admin/ActivitiesPage.php:968`), «Annulla movimento» (`Admin/LedgerPage.php:87`). Coppie positivo/negativo a 36 px affiancate.
- I comandi `button-link` non hanno altezza minima: «togli» (`LedgerPage.php:24`, `ActivitiesPage.php:907,951`), «rimuovi il consenso» (`Admin/PeoplePage.php:515`), «elimina» (`Admin/FivePmPage.php:66`).

Proposta: sotto i 782 px `.asem.asem-ui .button-small, .asem.asem-ui .button-link { min-height:44px }` e separare le coppie (il negativo a destra o su una riga propria).

**M7 — Bacheca dal telefono: tabelle che scorrono solo in orizzontale**
`assets/admin.css:73` rende scorrevole ogni tabella `widefat` senza vista alternativa; hanno la vista a schede solo l'elenco soci (`asem-cards`), la prima nota (`asem-ledger`) e gli elenchi `asem-rows`. Restano a scorrimento: prenotati di una data con i pulsanti in fondo alla riga (`Admin/ActivitiesPage.php:958`, 6 colonne: «Registra ingresso» e «Annulla prenotazione» sono fuori schermo), rimborsi (`Admin/RefundsPage.php:45`, 7 colonne), 5 per mille (`Admin/FivePmPage.php:49`, 7 colonne), pagamenti online (`Admin/PaymentsPage.php:148`, 7 colonne), report (`Admin/ReportsPage.php:96`, 6 colonne), preventivo (`Admin/BudgetPage.php:88`), anni (`Admin/YearsPage.php:46`).
Proposta: dare `asem-rows` (con `data-label`) almeno ai prenotati di una data e ai rimborsi, che si usano in piedi con il telefono in mano.

**M8 — Campi della bacheca senza tipo adatto alla tastiera del telefono**
- cellulare come `type="text"`: `Admin/PeoplePage.php:211`, `Admin/ActivitiesPage.php:840` («cellulare» del nuovo ospite all'ingresso)
- importi con `inputmode="decimal"` quasi ovunque (bene), ma **senza** in `Admin/ActivitiesPage.php:560` (prezzo del listino), `:624-626` (contributo soci/ospiti nella modifica), `Admin/BudgetPage.php:117` è corretto.
- area soci: `Frontend/Views.php:685` telefono `type="text"` senza `autocomplete="tel"`; `:686` codice fiscale senza `autocapitalize="characters"` (anche `Frontend/Application.php:137`, `Frontend/Activation.php:166`, `EventRequests.php:130`); CAP senza `inputmode="numeric"` (`Views.php:688`, `Application.php:139`, `Activation.php:168`).

### Miglioria

- **M9** `assets/frontend.css:285` etichette della barra in basso a 12 px: è lo standard delle app, ma per soci anziani 13-14 px con icona più piccola è più leggibile.
- **M10** Informativa e regolamento in un riquadro con scorrimento interno alto 200 px (`Frontend/Application.php:164`, `EventRequests.php:149,636`: `max-height:200px;overflow:auto`): sul telefono lo scorrimento dentro lo scorrimento è difficile. Proposta: collegamento «Leggi l'informativa» che apre la pagina, oppure `<details>`.
- **M11** `Admin/WizardPage.php` passi della procedura: `assets/admin.css:57` etichette a 12 px.
- **M12** `Admin/PeoplePage.php:232` quattro campi con `size` fisso (14, 16, 18, 22) su una riga per il nuovo genitore: sul telefono vanno a capo in modo irregolare e senza etichetta visibile dopo che si inizia a scrivere (solo segnaposto). Proposta: quattro righe con etichetta.
- **M13** `assets/frontend.css:341` eventi nel calendario mensile a `.78rem` (circa 12,5 px); sotto i 700 px il calendario diventa elenco (`:343-349`) ma il carattere resta quello: portarlo a 1rem nell'elenco.
- **M14** `Frontend/Views.php:126` tre collegamenti in fila separati da «·» («Password dimenticata? · Entra con un link via email · Primo accesso»): su 360 px vanno a capo a metà; vedi F14 (tre righe distinte).
- **M15** Conferme con `window.confirm` (`assets/frontend.js:5-6,14`): funzionano sul telefono ma il testo predefinito «Confermi?» non dice che cosa (F7).

---

## 6. Logica

### Bloccante

**L1 — Anno sociale e anno della tessera sono due cose diverse, ma all'utente sembrano la stessa**
- La configurazione chiede «L'anno sociale inizia a [mese]» (`Admin/WizardPage.php:92`, `Admin/EntityPage.php:21`), predefinito settembre (`Settings.php:35`).
- La tessera però vale sempre per l'anno solare e scade il 31 dicembre (`Settings.php:338-341`).
- Le schermate chiamano «anno sociale» anche l'anno della tessera (A6) e la guida li presenta insieme: «Quota associativa e anno sociale — La quota proposta negli incassi e il mese in cui inizia l'anno sociale» (`Guide.php:25`).

Cosa sbaglia un volontario: imposta l'anno sociale da settembre ad agosto e si aspetta che chi paga la quota a settembre sia in regola fino ad agosto; invece la tessera scade il 31 dicembre, dopo quattro mesi, e a gennaio il socio non può più prenotare (`Frontend/Actions.php:195`).
Proposta minima (testi): accanto al campo del mese: «Serve per raggruppare corsi ed eventi (es. stagione da settembre ad agosto). **Non cambia la tessera, che vale sempre dal 1° gennaio al 31 dicembre.**» e in `Guide.php:25` separare le due voci. Proposta di fondo (da decidere): permettere che la tessera segua l'anno sociale.

### Importante

**L2 — Accantonamenti contati tra le uscite dell'esercizio (vedi A15)**
`Admin/ReportsPage.php:79,111`. Da far validare a un commercialista: nel rendiconto per cassa l'uscita è il pagamento del rimborso, non l'accantonamento. Se il prospetto esportato («Rendiconto per cassa», `Admin/ToolsPage.php:77`) segue la stessa regola, i totali non coincidono con i movimenti di cassa e banca dell'anno.

**L3 — Creare un anno solare in anticipo cambia subito l'anno delle quote, senza conferma**
`Admin/YearsPage.php:45` «La quota associativa va all'anno più recente tra quelli creati: se non è quello in corso, l'anno in corso è in omaggio per chi si iscrive per la prima volta.» e modulo `:69-72` (campo anno + «Crea l'anno», senza `data-confirm`).
Chi crea l'anno dopo «per portarsi avanti» a marzo fa sì che da quel momento le quote dei nuovi soci valgano per l'anno successivo; non c'è un pulsante per eliminare un anno creato per errore nella tabella (`:46-62`: solo «Chiudi» / «Riapri»).
Proposta: conferma «Creare l'esercizio [anno]? Da adesso le quote dei nuovi soci varranno per il [anno] (con il resto di quest'anno in omaggio). Fallo solo verso fine anno.»; permettere di eliminare un anno futuro senza movimenti.

**L4 — Modulo «Diventa socio»: campi obbligatori non segnalati**
`Frontend/Application.php:140`: Email e Cellulare sono creati come facoltativi (nessun asterisco) e resi obbligatori da uno script solo per i maggiorenni (`assets/frontend.js:180`). L'utente adulto vede i due campi senza asterisco, li salta e riceve l'errore del browser. Anche l'indirizzo non è obbligatorio qui (`:138`) ma può diventarlo all'attivazione (`Frontend/Activation.php:167`, dove `required` è condizionale; `Admin/PeoplePage.php:248` «chi si attiva dal sito deve inserirlo»): lo stesso dato chiesto due volte.
Proposta: asterisco su Email e Cellulare con la nota «(per un minorenne bastano quelli del genitore)»; chiedere l'indirizzo una volta sola, nella domanda.

**L5 — Riquadro «Tesoriere»: il permesso reale è più ampio di quello dichiarato dal pulsante** (vedi A5). È un problema di logica oltre che di testo: si concede la gestione del denaro con un pulsante che parla solo di spese, e senza conferma.
Proposta: `data-confirm="Nominare [Nome] tesoriere? Potrà incassare denaro e registrare spese dall'area soci."`.

**L6 — Annullare un movimento senza motivo** (vedi A41): `LedgerService.php:528` accetta il motivo vuoto.

**L7 — Rifiutare una domanda con quota già pagata**
`Admin/DashboardPage.php:152,173`: la conferma è «Rifiutare la domanda?»; il rimborso è manuale e segnalato in un'altra pagina, visibile solo con i pagamenti online (`Admin/PaymentsPage.php:162`), mentre al richiedente si promette «ti viene restituita» (`Frontend/Application.php:123`, `Applications.php:174,364`).
Proposta: se la quota è pagata, conferma «Rifiutare la domanda di [Nome]? Ha già pagato X online: dovrai restituirglieli tu dal servizio di pagamento.» e lasciare in Bacheca un promemoria «Quote da restituire (n)».

**L8 — Stato «Sospeso (inattivo)» e riattivazione solo a mano**
`Admin/DashboardPage.php:383,391` («Sospendi… Diventa inattivo finché non rinnova») contro `Admin/PeoplePage.php:345` («Va riattivato a mano prima di poter incassare la quota o prenotare») e `Admin/Actions.php:1061,1067` («è inattivo finché non rinnova la tessera (oppure lo riattivi)»).
Tre testi, due regole: in uno il socio torna attivo rinnovando, nell'altro non si può incassare la quota finché non lo si riattiva. Chi è in segreteria non sa se può incassare.
Proposta: una sola regola scritta uguale ovunque. Se serve davvero la riattivazione manuale: «Sospeso: non riceve avvisi e non compare tra i soci da rinnovare. Per farlo tornare premi «Riattiva» nella sua scheda, poi incassa la quota.»

**L9 — Domanda pubblica e prenotazione pubblica chiedono dati che poi non servono subito**
`EventRequests.php:127-132`: per prenotare un posto a un evento (anche gratuito) un ospite deve dare data di nascita e codice fiscale (obbligatori), più luogo di nascita, indirizzo, CAP, comune, provincia. Dieci campi su un telefono per una serata. Se è una scelta voluta (assicurazione, registro), il modulo deve dire perché: «Ci servono per l'assicurazione dei partecipanti.» e mostrare solo i campi obbligatori, con gli altri in «Altri dati (facoltativi)».

### Miglioria

- **L10** `Admin/SettingsPage.php:57` «Cancella tutti i dati se il plugin viene eliminato da WordPress»: una casella senza conferma accanto a impostazioni ordinarie come lo sconto famiglia. Spostarla nella pagina «Azzeramento dati», che ha già le protezioni giuste (`Admin/ResetPage.php:59-61`: casella, frase da scrivere, password).
- **L11** `Admin/AccountsPage.php:50,76` conferme con `onclick="return confirm()"`, diverse dal resto (`data-confirm`).
- **L12** `Admin/IncomePage.php:163` si può registrare un incasso con «— nessuno / anonimo —»: per le quote associative e i contributi nominativi questo produce un movimento senza persona. Avvisare quando tra le voci c'è una quota o un contributo: «Senza indicare chi paga, l'incasso non viene collegato a nessun socio e non rinnova nessuna tessera.»
- **L13** `Admin/ActivitiesPage.php:731-740` due comandi per fermare un corso («Non rinnova» e «Disdici il rinnovo» con «Ultimo mese dovuto») nella stessa riga dell'iscritto, con effetti quasi uguali. Tenerne uno: «Ferma il rinnovo» con la scelta dell'ultimo mese (predefinito: quello in corso).
- **L14** `Frontend/Views.php:1359` e `CancelPolicy.php:91-93`: quando una prenotazione non si può annullare l'unica alternativa è «cambia nominativo»; manca «oppure avvisa la segreteria» per chi semplicemente non può venire.
- **L15** `Admin/BackupPage.php:27-29` ripristino: casella + conferma (bene), ma il testo `:23-25` non dice che anche soci e incassi inseriti **dopo** la copia vanno persi. Aggiungere: «Tutto ciò che è stato inserito dopo la data della copia andrà perso.»
- **L16** `includes/Notices.php:109` (`Edition::allows()` restituisce sempre vero, `Edition.php:134-137`): il messaggio «L'invio degli avvisi è sospeso perché la licenza… non risulta in regola» non può comparire; se un giorno tornasse attivo, non va mostrato a un volontario nell'area soci (non può farci nulla): «L'invio degli avvisi non è disponibile in questo momento: avvisa la segreteria.»
