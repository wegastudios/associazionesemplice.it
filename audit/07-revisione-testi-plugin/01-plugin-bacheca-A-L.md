# Plugin · Bacheca (file A–L)
File letti: 52 · Proposte: 126 (Alta 5, Media 79, Bassa 42)

Tutti i percorsi sono sotto `wordpress/associazionesemplice/includes/Admin/`. In «Ora» l'apostrofo è scritto com'è a schermo (nel PHP è `\'`). Dove il testo è spezzato da variabili, «Ora» cita solo il pezzo fisso e «…» segna la variabile.

## Difetti ricorrenti

1. **«Cancellare» per tre cose diverse.** Il glossario distingue annullare (resta traccia), disdire (fermare il rinnovo di un corso), eliminare (togliere per sempre) e chiede di non usare «cancellare». Nella bacheca «Cancella l'iscrizione» toglie per sempre (quindi «Elimina»), «Cancellabile» vuol dire «si può annullare», «finché non viene cancellata» vuol dire «disdetta». Regola: mai «cancellare/cancellazione» a schermo. Righe: `Actions.php` 426, 475, 493, 496, 502, 1097 · `ActivitiesPage.php` 98, 125, 236, 504, 518, 635, 800, 831, 845 · `Admin.php` 108 · `DashboardPage.php` 200 · `DeleteBookingPage.php` 23, 33, 45, 90, 92.
2. **«Iscrizione» per tessera, corso e prenotazione.** Per il glossario «iscritto» è solo chi è iscritto a un corso; per un evento è «prenotazione», per l'associazione è «socio/tessera». Righe: `Actions.php` 241 («Iscrizione aggiornata.» detto della tessera), 487, 496, 502, 1639 · `AuditPage.php` 25 · `DashboardPage.php` 150, 519 («iscriversi» = diventare soci) · `DeleteBookingPage.php` 23, 25, 33, 34.
3. **Lo stesso oggetto con più nomi.**
   - Chi fa gli ingressi a un evento: «staff dell'evento», «gestore», «gestori» (`Actions.php` 979, 989 · `ActivitiesPage.php` 996, 1020, 1032). Regola: sempre «staff dell'evento». E «staff» non va usato per dire «segreteria» (`CertificatesPage.php` 42, 153).
   - Chi tiene un'attività: «referente» ovunque, ma «istruttore» in `CertificatesPage.php` 63 e `EmployeesPage.php` 99.
   - Le varianti di prezzo: «listino», «tariffa», «variante» (`ActivitiesPage.php` 647, 696, 707, 708, 1075). Sceglierne una per gli eventi e una per i corsi.
   - Cedere un posto: «Cedi il posto», «Cambia», «Nominativo cambiato», «trasferita» (`Actions.php` 1382 · `ActivitiesPage.php` 1088, 1099, 1102).
   - Il prodotto stesso: «il plugin», «il sito», «il programma», «il sistema», «il gestionale» (`BackupPage.php` 18, 39 · `CommsPage.php` 19, 34 · `EntityPage.php` 127 · `FileCryptPanel.php` 37 · `DeleteActivityPage.php` 166 · `Admin.php` 287). Regola: «il gestionale» per ciò che fa il prodotto, «il sito» solo per le pagine pubbliche.
   - «registro azioni» (nome del menu) e «registro delle azioni» (`Actions.php` 1030 · `BackupPage.php` 40, 41 · `CertificatesPage.php` 42). Regola: sempre «registro azioni».
   - «Import» e «Importazione» nello stesso flusso (`Actions.php` 802, 1558, 1566, 1641, 1642, 1644 · `Admin.php` 139). Regola: «importazione» nelle frasi.
4. **Gergo tecnico.** «record … tabelle» (`Actions.php` 1056), «impronta» (`Actions.php` 1048, 1054), «database» (`AuditPage.php` 19), «manifest», «tabelle in chiaro» (`BackupPage.php` 35), «PWA», «manifest e service worker», «openssl» (`AppPage.php` 22, 25, 38, 39, 42), «sandbox» (`BlockEActions.php` 175), «dal sistema» (`DeleteActivityPage.php` 166 · `DeleteBookingPage.php` 73), «docs/CREDITI.md» (`CreditsPage.php` 38), «in chiaro», «sul disco», «un lotto al minuto» (`FileCryptPanel.php`). Regola: dire l'effetto per chi usa («non cifrati», «sul server», «un po' alla volta») e, se serve un tecnico, dirlo: «chiedi a chi gestisce il sito».
5. **Virgolette dritte invece di «…».** `AccountsPage.php` 88, 114 · `Actions.php` 704, 1592, 1642 · `ActivitiesPage.php` 184 · `CardPage.php` 27 · `DashboardPage.php` 120 · `ImportPage.php` 56, 65. Sostituzione meccanica, nessun cambio di testo.
6. **«Di default» e tre modi di dire acceso/spento.** «Spenta di default», «Spento di default», «Di default», «già di default», «Disattivato per impostazione predefinita», accanto a «attivo/disattivato» e «acceso/spento» (`AppPage.php` 23 · `BankPage.php` 30 · `AppearancePage.php` 84 · `ActivitiesPage.php` 1021 · `CalendarPage.php` 71 · `CardPage.php` 22 · `DonatePage.php` 37 · `FivePmPage.php` 25 · `GuidePage.php` 55). Regola: «All'inizio è spento» e una sola coppia, «acceso/spento» (è già la più usata e la più umana).
7. **Errori che non dicono come rimediare.** «Non autorizzato.» compare 13 volte (`Actions.php` 160 · `BlockBActions.php` 32 · `BlockCExports.php` 48, 58 · `BlockDActions.php` 33 · `BlockDExports.php` 41, 56, 69 · `BlockFActions.php` 27, 82 · `CertificatesPage.php` 33 · `CreditsActions.php` 24 · `Exports.php` 58). Vedi anche `Actions.php` 443, 1519 · `BackupPage.php` 30 · `AppPage.php` 39 · `Exports.php` 62.
8. **Trattino lungo come inciso o separatore.** `AccountingPage.php` 40 · `ActivitiesPage.php` 225, 472, 713, 967 · `DashboardPage.php` 216, 335 · `LedgerPage.php` 54. Regola: due punti o «·» (già usato altrove nella bacheca).
9. **Pulsanti minuscoli.** «elimina», «togli» (`AffiliationsPage.php` 65, 123, 175 · `ActivitiesPage.php` 1032 · `CertificatesPage.php` 137 · `AppPage.php` 31 · `FivePmPage.php` 66 · `LedgerPage.php` 27) contro «Elimina» nel resto. Regola: iniziale maiuscola su ogni pulsante.
10. **«Area riservata» e «area soci».** Per il glossario «area soci» è la pagina del socio e «area riservata» è l'account sul portale; nella bacheca «area riservata» indica quasi sempre l'area del socio (`Actions.php` 385, 979 · `ActivitiesPage.php` 201, 995, 996, 1021 · `AssembliesPage.php` 156 · `BankPage.php` 29 · `CardPage.php` 25, 33 · `ConventionsPage.php` 25 · `DocumentsPage.php` 58), mentre `DashboardPage.php` 33 la usa per il portale e `AppPage.php`, `CertificatesPage.php` 108 dicono «area soci». Non riscritto riga per riga perché «Area riservata» è anche il nome della voce di menu creata sul sito (`Actions.php` 1400): vedi «Da verificare».

## Proposte

### Admin/AccountingPage.php:40 · Bassa · bacheca, Contabilità
- Ora: «La prima nota registra aliquota e IVA contenuta di ogni riga:»
- Proposta: «La prima nota registra aliquota e IVA contenuta di ogni movimento:»
- Perché: il glossario chiama «movimento» la riga della prima nota. Nella stessa riga «IVA — partita IVA» va con i due punti.

### Admin/AccountsPage.php:38 · Media · bacheca, Conti e cassa
- Ora: «Per un POS fisico o un conto che trattiene una commissione. A ogni incasso su questo conto, in segreteria o sul posto, la spesa va in prima nota in automatico. Gli incassi online hanno le loro tariffe in «Pagamenti online». Vuoto = nessuna commissione.»
- Proposta: «Per un POS o un conto che trattiene una commissione. A ogni incasso su questo conto la commissione entra da sola in prima nota come spesa. Gli incassi online hanno le loro commissioni in «Pagamenti online». Vuoto = nessuna commissione.»
- Perché: «la spesa» non dice quale; «tariffe» e «commissione» sono la stessa cosa.

### Admin/AccountsPage.php:72 · Media · bacheca, Conti e cassa (e 76, 106, 128; ActivitiesPage.php:184)
- Ora: «Estingui e paga il rimborso» · «Estingui il fondo» · «finché il fondo non è estinto o liberato» · «finché non lo estingui» · «finché non estingui il fondo registrando il rimborso»
- Proposta: «Chiudi il fondo e paga il rimborso» · «Chiudi il fondo» · «finché il fondo non è chiuso o liberato» · «finché non lo chiudi» · «finché non chiudi il fondo registrando il rimborso»
- Perché: «estinguere» è burocratese; la descrizione dello stesso riquadro dice già «chiude il fondo».

### Admin/AccountsPage.php:141 · Alta · bacheca, Nuovo conto
- Ora: «Saldo iniziale attuale»
- Proposta: «Saldo di partenza»
- Perché: «iniziale attuale» si contraddice; nel modulo di modifica lo stesso campo si chiama «Saldo di partenza» (riga 35).

### Admin/Actions.php:160 · Media · bacheca, pagina di errore (e le altre 12 righe del difetto 7)
- Ora: «Non autorizzato.»
- Proposta: «Non hai il permesso per questa operazione: chiedilo a un amministratore.»
- Perché: messaggio da macchina, non dice cosa fare. È più lungo perché manca il rimedio.

### Admin/Actions.php:219 · Bassa · bacheca, avviso dopo il salvataggio della scheda
- Ora: «Scheda salvata. Essendo maggiorenne, gli è arrivato l'invito a scegliere la password.»
- Proposta: «Scheda salvata. È maggiorenne: gli è arrivato l'invito a scegliere la password.»
- Perché: gerundio burocratico.

### Admin/Actions.php:241 · Media · bacheca, scheda persona
- Ora: «Iscrizione aggiornata.»
- Proposta: «Tessera aggiornata.»
- Perché: qui si cambia la tessera del socio, non un'iscrizione a un corso. Vedi «Da verificare» se l'azione tocca altro.

### Admin/Actions.php:338 · Media · bacheca, scheda persona
- Ora: « Non ha più poteri: nella sua scheda, in «Accesso all'area riservata», puoi farlo passare all'accesso con link.»
- Proposta: « Non ha più incarichi: dalla sua scheda, in «Accesso all'area riservata», puoi passarlo all'accesso con link.»
- Perché: «poteri» è parola da programma; altrove si dice «incarichi».

### Admin/Actions.php:401 · Alta · bacheca, scheda persona
- Ora: «Ora fa parte dello staff: verifica gli accessi a tutti gli eventi.»
- Proposta: «Ora fa parte dello staff: registra gli ingressi a tutti gli eventi.»
- Perché: «verifica gli accessi» si legge come un ordine a chi sta usando la bacheca; nel resto si dice «registrare gli ingressi».

### Admin/Actions.php:426 · Media · bacheca, avviso dopo l'eliminazione di un evento
- Ora: «1 prenotazione attiva cancellata» · « prenotazioni attive cancellate»
- Proposta: «1 prenotazione attiva eliminata» · « prenotazioni attive eliminate»
- Perché: difetto 1; nella stessa frase l'evento è «eliminato».

### Admin/Actions.php:443 · Media · bacheca, eliminazione di più attività
- Ora: «Nessuna delle attività scelte si può eliminare così.»
- Proposta: «Nessuna delle attività scelte si può eliminare da qui: hanno movimenti in prima nota o un blocco. Eliminale una per una dalla loro scheda.»
- Perché: «così» non spiega né il motivo né il rimedio (il motivo è lo stesso della riga 458).

### Admin/Actions.php:458 · Media · bacheca, eliminazione di più attività
- Ora: « Non eliminati perché hanno movimenti in prima nota o un blocco (da fare una per una): »
- Proposta: « Non eliminate perché hanno movimenti in prima nota o un blocco (eliminale una per una): »
- Perché: concordanza (sono «attività») e «da fare» è vago.

### Admin/Actions.php:475 · Media · bacheca, scheda del corso
- Ora: « Restano perché hanno incassi registrati (cancellali uno per uno, scegliendo cosa fare delle somme): »
- Proposta: « Restano perché hanno incassi registrati (eliminali uno per uno, scegliendo cosa fare delle somme): »
- Perché: difetto 1.

### Admin/Actions.php:493 · Media · bacheca, eliminazione di un'iscrizione (e 496, 502)
- Ora: «Spunta la conferma: la cancellazione è definitiva.» · «Il nome scritto non corrisponde: l'iscrizione non è stata cancellata.» · « cancellata.»
- Proposta: «Spunta la conferma: l'eliminazione è definitiva.» · «Il nome scritto non corrisponde: l'iscrizione non è stata eliminata.» · « eliminata.»
- Perché: difetto 1; così coincide con l'analogo messaggio dell'evento (righe 414 e 417).

### Admin/Actions.php:995 · Bassa · bacheca, scheda dell'evento
- Ora: «Registrazione annullata.»
- Proposta: «Ingresso annullato.»
- Perché: «registrazione» di cosa? Gli altri due esiti parlano di «ingresso».

### Admin/Actions.php:1033 · Media · bacheca, esito del ripristino
- Ora: «Attenzione: {n} file della copia non sono citati dai dati e non sono stati rimessi sul sito.»
- Proposta: «Attenzione: {n} file della copia non sono collegati a nessun dato e non sono stati rimessi sul sito.»
- Perché: «citati dai dati» non si capisce.

### Admin/Actions.php:1036 · Media · bacheca, esito del ripristino
- Ora: «Attenzione: {n} documenti riservati sono sul sito ma restano chiusi: si aprono quando riapri la chiave con la frase della copia («Riapri la chiave con la frase», qui in Copia di sicurezza).»
- Proposta: «Attenzione: {n} documenti riservati sono sul sito ma restano chiusi. Per aprirli usa «Riapri la chiave con la frase», qui in Copia di sicurezza.»
- Perché: 32 parole, due «due punti» e una parentesi che ripete la frase.

### Admin/Actions.php:1048 · Media · bacheca, esito del ripristino (e 1054)
- Ora: «Attenzione: {n} file della copia non coincidono con la loro impronta (o non si aprono con la chiave) e non sono stati rimessi sul sito.» · «Attenzione: {n} PDF definitivi non si sono potuti ritrovare o non coincidono con la loro impronta; controllali da «Verifica delle ricevute».»
- Proposta: «Attenzione: {n} file della copia risultano alterati (o non si aprono con la chiave) e non sono stati rimessi sul sito.» · «Attenzione: {n} PDF definitivi mancano o risultano alterati: controllali da «Verifica delle ricevute».»
- Perché: «impronta» è gergo; all'utente interessa che il file non è più quello originale.

### Admin/Actions.php:1056 · Bassa · bacheca, esito del ripristino
- Ora: « record in » … « tabelle»
- Proposta: « righe in » … « tabelle»
- Perché: «record» è gergo. Sulla frase finale dello stesso messaggio vedi «Da verificare».

### Admin/Actions.php:1069 · Media · bacheca, scheda dell'evento
- Ora: «Prenotazione annullata (eventuali pagamenti vanno rimborsati a mano).»
- Proposta: «Prenotazione annullata. Se aveva già pagato, rimborsalo a mano.»
- Perché: dà del tu e toglie la parentesi impersonale.

### Admin/Actions.php:1092 · Media · bacheca, scheda del corso (con ActivitiesPage.php:856)
- Ora: «Non rinnova: resta iscritto fino a »
- Proposta: «Rinnovo disdetto: resta iscritto fino a »
- Perché: il messaggio d'esito ripete l'etichetta del pulsante invece di dire cosa è successo; «disdire» è il termine di glossario.

### Admin/Actions.php:1097 · Alta · bacheca, scheda del corso
- Ora: «Cancellazione registrata: i mesi successivi non sono più dovuti.»
- Proposta: «Rinnovo disdetto: i mesi successivi non sono più dovuti.»
- Perché: il pulsante premuto è «Disdici il rinnovo» (ActivitiesPage.php:863); «cancellazione» fa pensare che l'iscrizione sia stata eliminata.

### Admin/Actions.php:1214 · Bassa · bacheca, avviso dopo un'iscrizione
- Ora: « Attenzione: la tessera non è valida: rinnova la quota dalla scheda del socio (la trovi anche tra i pagamenti da incassare in Bacheca).»
- Proposta: « Attenzione: la tessera non è valida. Rinnova la quota dalla scheda del socio (la trovi anche tra i pagamenti da incassare in Bacheca).»
- Perché: due «due punti» di fila.

### Admin/Actions.php:1382 · Media · bacheca, scheda dell'evento (con ActivitiesPage.php:1088 e 1102)
- Ora: «Nominativo cambiato: il pagamento già fatto passa alla nuova persona.» · «trasferita ad altra persona» · «Cambia»
- Proposta: «Posto ceduto: il pagamento già fatto passa alla nuova persona.» · «posto ceduto a un'altra persona» · «Cedi il posto»
- Perché: la funzione si apre con «Cedi il posto a un'altra persona»: pulsante, stato ed esito devono usare la stessa parola. «Nominativo» è burocratese.

### Admin/Actions.php:1519 · Media · bacheca, caricamento di un file
- Ora: «Caricamento del file non riuscito (massimo 1 MB).»
- Proposta: «Il file non si è caricato: può pesare al massimo 1 MB. Riprova con un file più leggero.»
- Perché: la parentesi non fa capire che il limite è la causa probabile, né cosa fare.

### Admin/Actions.php:1588 · Bassa · bacheca, WP All Import
- Ora: « soci/ospiti, »
- Proposta: « tra soci e ospiti, »
- Perché: forma con la barra.

### Admin/Actions.php:1642 · Media · bacheca, esito dell'importazione
- Ora: «: si può annullare in blocco da "Importa".)»
- Proposta: «: puoi annullarlo in blocco da «Import già fatti».)»
- Perché: la riga prima e la 802 chiamano lo stesso punto «Import già fatti»; virgolette dritte.

### Admin/Actions.php:1644 · Media · bacheca, esito dell'importazione
- Ora: «La prima nota si era interrotta a metà: controlla i movimenti importati e, se serve, annulla l'import in blocco.»
- Proposta: «L'importazione della prima nota si era interrotta a metà: controlla i movimenti importati e, se serve, annulla tutta l'importazione.»
- Perché: non è la prima nota a interrompersi; «import in blocco» è gergo.

### Admin/ActivitiesPage.php:98 · Media · bacheca, modulo dell'evento (con la riga 100)
- Ora: «Cancellazione» · «Cancellabile anche se a pagamento» · «Gratuito: si può sempre annullare. A pagamento: non si annulla mai, ma si può cedere il posto a un'altra persona (se il nuovo partecipante è un ospite con contributo maggiore si integra la differenza), a meno che l'evento sia cancellabile entro il termine scelto.»
- Proposta: «Annullamento» · «Si può annullare anche se a pagamento» · «Evento gratuito: la prenotazione si può sempre annullare. A pagamento: si annulla solo con questa spunta ed entro il termine scelto; altrimenti si può solo cedere il posto (se il nuovo partecipante paga un contributo più alto, versa la differenza).»
- Perché: difetto 1, e la frase dice «non si annulla mai» e poi «a meno che»: 45 parole per una regola che si capisce solo alla fine.

### Admin/ActivitiesPage.php:124 · Media · bacheca, modulo del corso (con 125 e 800)
- Ora: «Una tantum (anche annuale)» · «<strong>Una tantum:</strong>» · «finché l'iscritto non lo cancella» · «finché non viene cancellata»
- Proposta: «Pagamento unico (anche annuale)» · «<strong>Pagamento unico:</strong>» · «finché l'iscritto non lo disdice» · «finché non viene disdetta»
- Perché: la scheda del corso dice già «Corso a pagamento unico» (riga 799): stesso nome nel modulo. «Disdire» è il termine di glossario per fermare un rinnovo.

### Admin/ActivitiesPage.php:190 · Media · bacheca, modulo dell'evento (con 191)
- Ora: «Tolleranza per il pagamento» · «Per chi paga con bonifico o contanti: se il contributo non arriva entro queste ore dalla prenotazione il posto si libera e passa a chi è in lista d'attesa. Vale solo se i posti sono limitati e c'è un contributo; non si libera mai il posto di chi ha già versato (anche in parte) o sta pagando online. 0 = nessun limite.»
- Proposta: «Tempo per pagare» · «Per chi paga con bonifico o contanti: se il contributo non arriva entro queste ore dalla prenotazione, il posto passa a chi è in lista d'attesa. Vale solo con posti limitati e un contributo; chi ha già versato qualcosa o sta pagando online non perde mai il posto. 0 = nessun limite.»
- Perché: «tolleranza» è da regolamento; il testo si accorcia senza perdere regole.

### Admin/ActivitiesPage.php:225 · Media · bacheca, elenco attività (e 785; BookDeskPage.php:168)
- Ora: «Attività — anno » · «Riepilogo · anno » · «Corsi (anno »
- Proposta: «Attività · anno delle attività » · «Riepilogo · anno delle attività » · «Corsi (anno delle attività »
- Perché: regola fissa: corsi ed eventi seguono l'«anno delle attività»; «anno» da solo si confonde con l'anno fiscale. Più lungo perché manca l'informazione.

### Admin/ActivitiesPage.php:236 · Bassa · bacheca, elenco attività
- Ora: «Spunta le attività da togliere: prima di eliminare vedrai l'elenco e cosa viene cancellato.»
- Proposta: «Spunta le attività da eliminare: prima di confermare vedrai l'elenco e cosa viene eliminato.»
- Perché: tre verbi (togliere, eliminare, cancellare) per la stessa azione.

### Admin/ActivitiesPage.php:455 · Bassa · bacheca, scheda del corso
- Ora: «il calendario, le presenze e i promemoria seguono.»
- Proposta: «calendario, presenze e promemoria si aggiornano da soli.»
- Perché: «seguono» lascia la frase a metà.

### Admin/ActivitiesPage.php:587 · Bassa · bacheca, scheda attività
- Ora: «Il collegamento WhatsApp compare solo per chi ha lasciato un cellulare.»
- Proposta: «Il link WhatsApp compare solo per chi ha lasciato un cellulare.»
- Perché: il glossario sceglie «link».

### Admin/ActivitiesPage.php:635 · Media · bacheca, scheda dell'evento
- Ora: «Cancella l'evento con tutte le sue date, prenotazioni e iscrizioni, e decide cosa fare delle somme incassate (restituirle o annullarle). Con doppia conferma.»
- Proposta: «Elimina l'evento con tutte le date, le prenotazioni e le iscrizioni. Prima scegli cosa fare delle somme incassate (restituirle o annullarle) e confermi due volte.»
- Perché: il soggetto di «decide» non c'è (decide chi usa la bacheca); difetto 1.

### Admin/ActivitiesPage.php:749 · Media · bacheca, dati dell'attività
- Ora: «Vale per le nuove prenotazioni/mensilità: quelle già fatte tengono l'importo di allora (eventi) o seguono la nuova quota (corsi).»
- Proposta: «Vale da ora: le prenotazioni già fatte tengono l'importo di allora, le mensilità dei corsi seguono il nuovo contributo.»
- Perché: forma con la barra, due parentesi, e «quota» usata per un corso (per il glossario è «contributo»).

### Admin/ActivitiesPage.php:811 · Bassa · bacheca, scheda del corso (con 812)
- Ora: «Salva e prossimo inserimento» · ««prossimo inserimento» resta qui, pronto per iscrivere la persona dopo.»
- Proposta: «Iscrivi e aggiungine un'altra» · ««aggiungine un'altra» resta qui, pronto per la persona dopo.»
- Perché: «inserimento» è linguaggio da archivio; l'altro pulsante dice «Iscrivi e vai alla cassa».

### Admin/ActivitiesPage.php:856 · Media · bacheca, scheda del corso
- Ora: «Non rinnova» · «Chi smette di venire ha già fruito del mese: non si restituisce niente.»
- Proposta: «Disdici a fine periodo» · «Il mese già iniziato non si rimborsa.»
- Perché: accanto c'è «Disdici il rinnovo»: due pulsanti per la stessa cosa con nomi che non si richiamano; «fruito» è burocratese.

### Admin/ActivitiesPage.php:903 · Media · bacheca, scheda dell'evento ricorrente
- Ora: «Aggiungi o elimina date»
- Proposta: «Aggiungi o annulla date»
- Perché: nel riquadro le date si annullano (restano in elenco con la scritta «annullata»), non si eliminano.

### Admin/ActivitiesPage.php:987 · Media · bacheca, elenco delle prenotazioni
- Ora: «Annulla»
- Proposta: «Annulla ingresso»
- Perché: sulla stessa riga c'è «Annulla prenotazione»: un «Annulla» da solo si può premere per sbaglio.

### Admin/ActivitiesPage.php:996 · Media · bacheca, avvisi agli iscritti (con Actions.php:979 e 989; ActivitiesPage.php:1032)
- Ora: «Il referente e i gestori dell'evento» · «Gestore dell'evento aggiunto:» · «Gestore tolto.» · «Togliere questo gestore?»
- Proposta: «Il referente e lo staff dell'evento» · «Aggiunto allo staff dell'evento:» · «Tolto dallo staff.» · «Togliere questa persona dallo staff?»
- Perché: difetto 3: il riquadro si chiama «Staff dell'evento» e il glossario esclude «gestori».

### Admin/ActivitiesPage.php:1021 · Media · bacheca, staff dell'evento
- Ora: «Se abiliti l'incasso, possono anche far pagare il contributo d'ingresso sul posto a chi si presenta senza aver prenotato (se c'è posto) e registrare subito l'ingresso. Il referente e gli amministratori possono fare tutto questo già di default.»
- Proposta: «Con «può incassare sul posto» fanno anche pagare il contributo a chi arriva senza prenotazione (se c'è posto) e ne registrano l'ingresso. Il referente e gli amministratori possono già fare tutto questo.»
- Perché: 33 parole in una frase, «abiliti l'incasso» non richiama il nome della casella, «di default».

### Admin/ActivitiesPage.php:1075 · Media · bacheca, lista d'attesa
- Ora: «manca la tariffa scelta: non entra da solo, prenotalo a mano scegliendo il listino»
- Proposta: «non ha scelto la tariffa: non entra da solo, prenotalo a mano scegliendola tu»
- Perché: «tariffa» e «listino» nella stessa frase per la stessa cosa.

### Admin/ActivitiesPage.php:1094 · Media · bacheca, elenco delle prenotazioni
- Ora: «Annullare la prenotazione? Gli eventuali pagamenti restano registrati.»
- Proposta: «Annullare la prenotazione? I pagamenti già fatti restano registrati: vanno rimborsati a mano.»
- Perché: la conferma tace la conseguenza che il messaggio d'esito dice subito dopo (Actions.php:1069).

### Admin/Admin.php:137 · Bassa · bacheca, titoli delle pagine (e 139, 286)
- Ora: «Importa soci» · «Import con WP All Import» · «Parte non in uso»
- Proposta: «Importa da Excel o CSV» · «Importa con WP All Import» · «Funzione spenta»
- Perché: la scheda si chiama «Importa da Excel/CSV» e importa anche la prima nota; «Parte non in uso» è l'unico dei tre avvisi gemelli a non dire «Funzione».

### Admin/AffiliationsPage.php:146 · Bassa · bacheca, Affiliazioni
- Ora: «Tessera i soci»
- Proposta: «Crea le tessere»
- Perché: «Tessera» letto come nome confonde; la conferma dice già «Creare le tessere».

### Admin/AffiliationsPage.php:155 · Media · bacheca, Affiliazioni
- Ora: «Una sola volta per ogni scadenza e tipo di avviso, una volta al giorno.»
- Proposta: «Ogni avviso parte una volta sola. Il controllo si fa una volta al giorno.»
- Perché: «una sola volta … una volta al giorno» sembra contraddirsi.

### Admin/AppPage.php:22 · Media · bacheca, App e notifiche (con 25, 38, 42)
- Ora: «un'app installabile sul telefono (PWA)» · «App installabile (PWA)» · «gli indirizzi dell'app (manifest e service worker) potrebbero non essere raggiungibili» · «Indirizzi: »
- Proposta: «un'app installabile sul telefono» · «App installabile» · «gli indirizzi dell'app potrebbero non essere raggiungibili» · «Indirizzi tecnici: »
- Perché: sigle e nomi tecnici che al volontario non servono; i due link di controllo restano, con un'etichetta che dice a chi servono.

### Admin/AppPage.php:39 · Media · bacheca, App e notifiche
- Ora: «Senza openssl le notifiche non sono disponibili; l'app installabile funziona lo stesso.»
- Proposta: «Le notifiche non sono disponibili: chiedi a chi gestisce il sito di attivare openssl. L'app installabile funziona lo stesso.»
- Perché: non dice come rimediare.

### Admin/AppPage.php:47 · Bassa · bacheca, App e notifiche (con 51 e 56)
- Ora: «Invia una notifica di prova al mio utente» · «Solo se sospetti che siano state esposte.» · «dall'app attivano le notifiche con un tocco.»
- Proposta: «Mandami una notifica di prova» · «Solo se temi che qualcuno le abbia copiate.» · «i soci le attivano dall'app con un tocco.»
- Perché: «al mio utente» e «esposte» sono gergo; nella terza manca il soggetto e «notifiche» è ripetuto.

### Admin/AppearancePage.php:30 · Bassa · bacheca, Aspetto
- Ora: «Il socio la scarica in PDF dalla sua area e la segreteria dalla sua scheda.»
- Proposta: «Il socio la scarica in PDF dalla sua area, la segreteria dalla scheda del socio.»
- Perché: il secondo «sua» sembra la scheda della segreteria.

### Admin/AppearancePage.php:94 · Media · bacheca, Aspetto
- Ora: «Stai usando il logo del sito» + « (non ne è stato trovato uno: scegline uno).»
- Proposta: quando il logo manca, tutta la frase diventa «Il sito non ha un logo: scegline uno qui.»
- Perché: «Stai usando il logo del sito (non ne è stato trovato uno)» si contraddice.

### Admin/AssembliesPage.php:159 · Bassa · bacheca, Assemblee
- Ora: «Chi delega » … « delega » …
- Proposta: «Delega di » … « a » …
- Perché: a schermo si legge «Chi delega [socio] delega [socio]».

### Admin/AuditPage.php:19 · Media · bacheca, Registro azioni (con 26)
- Ora: «Per alleggerire subito il database puoi eliminare tutto ciò che non è dell'anno in corso.» · «Verifiche cassa»
- Proposta: «Per liberare spazio subito puoi eliminare tutto ciò che non è dell'anno in corso.» · «Verifiche del saldo»
- Perché: «database» è gergo e il pulsante si chiama «Libera spazio»; la verifica vale per tutti i conti, non solo per la cassa.

### Admin/BackupPage.php:30 · Media · bacheca, Copia di sicurezza
- Ora: «Questo server non ha l'estensione zip di PHP: la copia non si può creare.»
- Proposta: «La copia non si può creare: sul server manca l'estensione zip di PHP. Chiedi a chi gestisce il sito di attivarla.»
- Perché: prima l'effetto, poi la causa, poi il rimedio (che mancava).

### Admin/BackupPage.php:35 · Media · bacheca, Copia di sicurezza
- Ora: «Nell'ultima copia {n} campi riservati (ad esempio il medico nei certificati) sono nelle tabelle in chiaro, perché su questo sito non si possono ancora cifrare: controlla la sezione «Documenti riservati» qui sotto. Nessun dato è andato perso: il manifest della copia ne dichiara il numero.»
- Proposta: «Nell'ultima copia {n} campi riservati (ad esempio il medico nei certificati) non sono cifrati, perché su questo sito non si possono ancora cifrare: controlla «Documenti riservati» qui sotto. Nessun dato è andato perso.»
- Perché: «tabelle in chiaro» e «manifest» sono gergo; l'ultima precisazione serve solo a un tecnico.

### Admin/BackupPage.php:44 · Media · bacheca, Ripristino
- Ora: «Scrivi la frase: se non l'hai cambiata, è il codice di licenza (o, nel plugin gratuito, la data e l'ora in cui era stata creata la chiave dei file, in UTC, non la data di installazione del plugin) che avevi quando hai fatto la copia. Va scritta esattamente com'era, spazi e trattini compresi.»
- Proposta: «Se non l'hai cambiata, la frase è il codice di licenza che avevi quando hai fatto la copia. Nel plugin gratuito è la data e l'ora (UTC) in cui è stata creata la chiave dei file, non quella di installazione. Scrivila esattamente com'era, spazi e trattini compresi.»
- Perché: 45 parole con una parentesi di 25 in mezzo: il soggetto e il suo «che avevi» finiscono lontanissimi.

### Admin/BankPage.php:29 · Media · bacheca, Bonifico
- Ora: «Mostra ai soci, nell'area riservata e accanto a ciò che devono pagare, l'IBAN dell'associazione (o gli IBAN, se più di uno) con una causale già pronta, e permette di farselo mandare per email.»
- Proposta: «Mostra ai soci l'IBAN dell'associazione (anche più di uno) con la causale già pronta: nell'area riservata e accanto a ciò che devono pagare. Possono anche farselo mandare per email.»
- Perché: 34 parole con due incisi prima di arrivare all'oggetto.

### Admin/BankPage.php:48 · Media · bacheca, Bonifico (con 49)
- Ora: «Ogni modifica viene scritta nel registro azioni (con l'IBAN mascherato) e <strong>avvisata per email a tutti gli amministratori</strong>: se un accesso viene rubato e il conto sostituito, ci si accorge subito. » · «L'email con le coordinate parte sempre e solo all'indirizzo del socio.»
- Proposta: «Ogni modifica resta nel registro azioni (con l'IBAN in parte nascosto) e <strong>viene segnalata per email a tutti gli amministratori</strong>: se qualcuno ruba un accesso e cambia il conto, te ne accorgi subito. » · «L'email con le coordinate arriva solo all'indirizzo del socio.»
- Perché: una modifica non si «avvisa»; «mascherato» è gergo; impersonale al posto del tu.

### Admin/BlockEActions.php:175 · Bassa · bacheca, Pagamenti online
- Ora: «sandbox»
- Proposta: «modalità di prova»
- Perché: gergo; l'altra metà dice «modalità reale».

### Admin/BookDeskPage.php:111 · Media · bacheca, Prenota (con 127 e 187)
- Ora: «oppure la cosa (vedi le date disponibili)» · «Parto dalla cosa» · «Scegli una cosa dall'elenco per vedere le date disponibili.»
- Proposta: «oppure l'attività (vedi le date disponibili)» · «Parto dall'attività» · «Scegli un'attività dall'elenco per vedere le date disponibili.»
- Perché: «la cosa» è troppo colloquiale e vago; l'elenco contiene corsi ed eventi, cioè attività.

### Admin/BookDeskPage.php:111 · Media · bacheca, Prenota
- Ora: «se la persona versa solo una parte, riduci l'importo e il resto resta come acconto.»
- Proposta: «se la persona versa solo una parte, riduci l'importo: il resto resta da pagare.»
- Perché: l'acconto è la parte versata, non il resto; e «il resto resta». Vedi «Da verificare».

### Admin/BudgetPage.php:23 · Bassa · bacheca, Preventivo (e 71)
- Ora: «Bilancio preventivo»
- Proposta: «Preventivo»
- Perché: il glossario evita «bilancio» e in tutta la pagina si dice già «preventivo».

### Admin/BudgetPage.php:25 · Bassa · bacheca, Preventivo
- Ora: «Per ogni anno scrivi quanto prevedi di incassare e di spendere per categoria; il confronto con il consuntivo si aggiorna da solo con i movimenti della prima nota (i giroconti e i movimenti annullati non contano).»
- Proposta: «Per ogni anno scrivi quanto prevedi di incassare e di spendere, categoria per categoria. Il confronto con il consuntivo (quanto hai incassato e speso davvero) si aggiorna da solo dalla prima nota; giroconti e movimenti annullati non contano.»
- Perché: 36 parole in una frase; «consuntivo» va spiegato la prima volta, come chiede il glossario.

### Admin/CardPage.php:25 · Bassa · bacheca, Tessera e QR
- Ora: «Ogni socio trova nella sua area riservata un QR: chi lo scansiona (anche senza accedere al sito) vede subito se la tessera è <strong>valida in questo momento</strong>, con nome, tipo, numero e scadenza e nient'altro.»
- Proposta: «Ogni socio trova un QR nella sua area riservata. Chi lo scansiona, anche senza accedere al sito, vede solo se la tessera è <strong>valida in questo momento</strong>, con nome, tipo, numero e scadenza.»
- Perché: 37 parole; «e … e nient'altro» si risolve con «solo».

### Admin/CertificatesPage.php:42 · Media · bacheca, Certificati medici (con 43, 153, 163)
- Ora: «lo vede solo lo staff» · «con chi è scaduto, in scadenza o senza certificato» · «lo scarica solo lo staff»
- Proposta: «lo vede solo la segreteria» · «con chi ha il certificato scaduto, in scadenza o mancante» · «lo scarica solo la segreteria»
- Perché: per il glossario «staff» è solo chi fa gli ingressi agli eventi; e a scadere è il certificato, non la persona.

### Admin/CertificatesPage.php:63 · Media · bacheca, Certificati medici (e EmployeesPage.php:99)
- Ora: «alle attività di cui è istruttore e di quelle senza istruttore.»
- Proposta: «alle attività di cui è referente e a quelle senza referente.»
- Perché: nel modulo dell'attività il campo si chiama «Referente»; «di quelle» non regge («iscritti alle … e a quelle»).

### Admin/CommsPage.php:34 · Bassa · bacheca, Privacy
- Ora: «Per sapere quali cookie usa davvero il tuo sito puoi servirti di uno scanner esterno.»
- Proposta: «Per sapere quali cookie usa davvero il tuo sito puoi usare un servizio online che li elenca.»
- Perché: «scanner esterno» non dice cos'è.

### Admin/CommsPage.php:37 · Media · bacheca, Privacy
- Ora: «Tempo oltre il quale proponi di togliere i dati personali di chi non partecipa più.»
- Proposta: «Passato questo tempo, qui sotto ti viene proposto di togliere i dati personali di chi non partecipa più.»
- Perché: «proponi» attribuisce a chi legge un'azione che fa il gestionale (l'elenco «Ex soci da anonimizzare»).

### Admin/CommsPage.php:55 · Media · bacheca, Informativa privacy
- Ora: «Il modello dell'informativa (artt. 13 e 14 GDPR) si compila da solo con i dati dell'ente e con le funzioni che usi: dichiara che i dati si comunicano a terzi solo per le finalità degli eventi cui si partecipa, che non vengono ceduti per scopi commerciali e che il trattamento avviene con strumenti informatici, senza profilazione commerciale. Titolare del trattamento è l'associazione, nella persona del presidente (in mancanza, di chi ha il ruolo di segreteria). È un modello: fallo leggere al tuo consulente prima di pubblicarlo.»
- Proposta: «Il modello dell'informativa (artt. 13 e 14 GDPR) si compila da solo con i dati dell'ente e con le funzioni che usi: il testo completo è nell'anteprima qui sotto. Titolare del trattamento è l'associazione, nella persona del presidente (in mancanza, di chi ha il ruolo di segreteria). È un modello: fallo leggere al tuo consulente prima di pubblicarlo.»
- Perché: 80 parole; la frase centrale riassume in legalese un testo che si legge per intero nell'anteprima della stessa pagina.

### Admin/ConventionsPage.php:30 · Bassa · bacheca, Convenzioni
- Ora: «Chi»
- Proposta: «A chi spetta»
- Perché: intestazione di colonna monca; nel modulo il campo si chiama «A chi spetta».

### Admin/ConventionsPage.php:128 · Alta · bacheca, Convenzioni
- Ora: «Mandare ora l'email ai soci a cui spetta? Non si può rimandare.»
- Proposta: «Mandare ora l'email ai soci a cui spetta? Parte una volta sola.»
- Perché: «rimandare» si legge come «rinviare a dopo», cioè il contrario di quello che si vuole dire (non si può inviare di nuovo).

### Admin/CreditsPage.php:38 · Media · bacheca, Crediti
- Ora: «La vendita di un pacchetto è l'entrata in prima nota; la spesa dei crediti non registra niente in prima nota (vedi <code>docs/CREDITI.md</code>).»
- Proposta: «Quando vendi un pacchetto l'incasso entra in prima nota; quando il socio spende i crediti la prima nota non cambia.»
- Perché: rimanda a un file per sviluppatori che l'utente non può aprire; «la spesa dei crediti» si confonde con una spesa in prima nota.

### Admin/DashboardPage.php:94 · Bassa · bacheca, avviso
- Ora: «Se servono, dagli il ruolo Segreteria o Dipendente.»
- Proposta: «Se devono entrare, assegna loro il ruolo Segreteria o Dipendente.»
- Perché: «se servono» (gli utenti? i ruoli?) e «dagli» al plurale è troppo parlato.

### Admin/DashboardPage.php:131 · Media · messaggio WhatsApp precompilato per chi ha chiesto l'accesso
- Ora: «confermaci nome, cognome ed email con cui sei iscritto/a.»
- Proposta: «confermaci nome, cognome ed email che hai dato all'associazione.»
- Perché: doppia forma con la barra, che il glossario chiede di togliere; lo legge una persona, non un modulo.

### Admin/DashboardPage.php:150 · Media · bacheca, riquadri sugli ospiti (con 519)
- Ora: «Ospiti da invitare a iscriversi (» · «Hanno raggiunto la soglia di partecipazioni per i non soci (sommando le registrazioni dello stesso cellulare, email o nome). Nessun blocco: decidi tu.» · «Ospiti attesi che dovrebbero iscriversi (» · «hanno già raggiunto la soglia di partecipazioni per i non soci.»
- Proposta: «Ospiti da invitare a diventare soci (» · «Hanno raggiunto il numero massimo di partecipazioni come ospiti (contando insieme le schede con lo stesso cellulare, email o nome). Non vengono bloccati: decidi tu.» · «Ospiti attesi da invitare a diventare soci (» · «hanno già raggiunto il numero massimo di partecipazioni come ospiti.»
- Perché: «iscriversi» qui vuol dire diventare soci (difetto 2); il glossario dice «ospite», non «non socio»; «soglia» e «registrazioni» sono gergo.

### Admin/DashboardPage.php:210 · Bassa · bacheca, domande di adesione (con 216)
- Ora: «Genitore/tutore:» · « — »
- Proposta: «Genitore o tutore:» · «: »
- Perché: forma con la barra e trattino come separatore («Quota 20 € — pagata online»).

### Admin/DashboardPage.php:224 · Bassa · bacheca, domande di adesione
- Ora: «Rifiutare la domanda?»
- Proposta: «Rifiutare la domanda? Viene eliminata.»
- Perché: la conferma non dice la conseguenza, che è scritta solo nella descrizione in alto.

### Admin/DashboardPage.php:451 · Bassa · bacheca, pagamenti da incassare
- Ora: «Quote dei nuovi soci o con la tessera non valida,»
- Proposta: «Quote dei nuovi soci e di chi ha la tessera non valida,»
- Perché: «quote … con la tessera non valida» non sta in piedi.

### Admin/DeleteActivityPage.php:77 · Bassa · bacheca, conferma di eliminazione (e 194)
- Ora: «Ho letto e ho capito: l'eliminazione è definitiva e non si può annullare.»
- Proposta: «Ho capito: l'eliminazione è definitiva.»
- Perché: «definitiva» e «non si può annullare» dicono la stessa cosa; il ripristino usa già «Ho capito:» (BackupPage.php:45).

### Admin/DeleteActivityPage.php:92 · Bassa · bacheca (e DeleteBookingPage.php:25)
- Ora: «L'evento non esiste (più).» · «L'iscrizione non esiste (più).»
- Proposta: «Evento non trovato: forse è già stato eliminato.» · «Iscrizione non trovata: forse è già stata eliminata.»
- Perché: la parentesi «(più)» è una strizzata d'occhio da programmatore.

### Admin/DeleteActivityPage.php:116 · Media · bacheca, eliminazione di un evento
- Ora: «restano in prima nota così come sono e non vengono restituiti né annullati. Restituzione e annullamento valgono solo per ciò che non è ancora stato fruito.»
- Proposta: «restano in prima nota così come sono. Restituzione e annullamento valgono solo per le date e i mesi non ancora svolti.»
- Perché: «fruito» è burocratese e la prima frase dice due volte la stessa cosa.

### Admin/DeleteActivityPage.php:165 · Bassa · bacheca (e DeleteBookingPage.php:72)
- Ora: «Nei saldi l'effetto netto è zero.» · «Effetto netto sui saldi: zero.»
- Proposta: «I saldi non cambiano.» (in entrambi)
- Perché: «effetto netto» è linguaggio da contabile; due forme diverse per la stessa frase.

### Admin/DeleteActivityPage.php:166 · Media · bacheca (e DeleteBookingPage.php:73)
- Ora: «Il denaro non viene restituito dal sistema:»
- Proposta: «Il gestionale non restituisce il denaro:»
- Perché: «dal sistema» è da macchina; passivo inutile.

### Admin/DeleteActivityPage.php:168 · Bassa · bacheca (e DeleteBookingPage.php:75)
- Ora: «vengono ANNULLATI»
- Proposta: «vengono annullati»
- Perché: il maiuscolo grida; la frase è già in grassetto.

### Admin/DeleteBookingPage.php:23 · Media · bacheca, titolo e pulsanti (con 45, 90, 92; Admin.php:108; ActivitiesPage.php:518, 845)
- Ora: «Cancella l'iscrizione» · «Ho letto e ho capito: la cancellazione è definitiva.» · «Cancella»
- Proposta: «Elimina l'iscrizione» · «Ho capito: l'eliminazione è definitiva.» · «Elimina»
- Perché: difetto 1: è un'eliminazione definitiva, e la pagina gemella dell'evento dice già «Elimina».

### Admin/DeleteBookingPage.php:33 · Media · bacheca, eliminazione di un'iscrizione
- Ora: «L'iscrizione (» + «prenotazione a questa data» / «iscrizione al corso, con le presenze» + «) viene cancellata e non si può ripristinare.»
- Proposta: «La prenotazione a questa data viene eliminata e non si può ripristinare.» / «L'iscrizione al corso, con le presenze, viene eliminata e non si può ripristinare.»
- Perché: oggi si legge «L'iscrizione (iscrizione al corso, con le presenze)»; per un evento la parola giusta è «prenotazione».

### Admin/DocumentsPage.php:21 · Media · bacheca, Documenti
- Ora: «I file non hanno un indirizzo pubblico: si aprono solo con il controllo dei permessi.»
- Proposta: «I file non hanno un indirizzo pubblico: li apre solo chi ha il permesso di vederli.»
- Perché: «con il controllo dei permessi» è una frase da programma.

### Admin/DonatePage.php:37 · Media · bacheca, Donazioni
- Ora: «Spento di default. Spento, nessun modulo di donazione compare nel sito.»
- Proposta: «All'inizio è spento: finché resta spento, sul sito non compare nessun modulo di donazione.»
- Perché: «Spento. Spento,» è ripetuto e monco; «di default».

### Admin/EmployeesPage.php:70 · Media · bacheca, Dipendenti (con 71)
- Ora: «Solo chi legge questa pagina (amministratori, presidente e vicepresidente) può togliergli, area per area, i permessi qui sotto: un dipendente nuovo li ha tutti, mentre impostazioni, pagamenti e copie di sicurezza restano solo per gli amministratori. » · «Il numero dei lavoratori per il RUNTS è il numero dei dipendenti in servizio alla data.»
- Proposta: «Un dipendente nuovo ha tutti i permessi qui sotto: amministratori, presidente e vicepresidente possono toglierli area per area. Impostazioni, pagamenti e copie di sicurezza restano solo agli amministratori. » · la seconda frase va completata: «alla data» di cosa? (vedi «Da verificare»)
- Perché: 37 parole con un inciso e due subordinate; «chi legge questa pagina» è un giro di parole.

### Admin/EntityPage.php:39 · Bassa · bacheca, Dati dell'ente
- Ora: «Cambiando il mese, le tessere degli anni fiscali ancora aperti che hanno un pagamento registrato si riallineano al nuovo anno fiscale: non si accorciano mai e quelle degli anni chiusi non si toccano.»
- Proposta: «Se cambi il mese, le tessere pagate degli anni fiscali ancora aperti si riallineano al nuovo anno fiscale. Non si accorciano mai; quelle degli anni chiusi non cambiano.»
- Perché: 33 parole in una frase, gerundio al posto del tu.

### Admin/EntityPage.php:51 · Media · bacheca, Dati dell'ente
- Ora: «Li leggono i soci nelle email e nell'informativa sulla privacy: usa un recapito che qualcuno controlla davvero.»
- Proposta: «Email e telefono compaiono nelle email ai soci e nell'informativa sulla privacy: usa recapiti che qualcuno controlla davvero.»
- Perché: la nota sta sotto il campo email e comincia con «Li», che si riferisce anche al telefono chiesto nella riga dopo.

### Admin/EntityPage.php:122 · Bassa · bacheca, Dati dell'ente
- Ora: «Da che mese inizia il tuo anno fiscale (bilancio e rendiconto)?»
- Proposta: «Da che mese inizia il tuo anno fiscale (conti e rendiconto)?»
- Perché: il glossario evita «bilancio»; la descrizione sotto dice già «l'anno dei conti».

### Admin/ExpensePage.php:31 · Bassa · bacheca, titolo della pagina
- Ora: «Nuova spesa / rimborso»
- Proposta: «Nuova spesa o rimborso»
- Perché: forma con la barra.

### Admin/ExpensePage.php:36 · Media · bacheca, Nuova spesa
- Ora: «Il saldo tra parentesi è quello attuale: controlla che il conto abbia fondi sufficienti.»
- Proposta: «Il saldo tra parentesi è quello attuale: controlla che sul conto ci siano abbastanza soldi.»
- Perché: per il glossario «fondi» non va usato nel senso di «soldi» (qui i «fondi» sono un'altra cosa).

### Admin/ExpensePage.php:45 · Bassa · bacheca, simboli nei testi (e Exports.php:23; BookDeskPage.php:144; IncomePage.php:154, 155)
- Ora: «📷 Scatta una foto» · « 🔒» · «⚠ Socio sospeso (inattivo):» · «⚠ il socio ha già la tessera»
- Proposta: «Scatta una foto» · «(sospesa)» · «Socio sospeso (inattivo):» · «Il socio ha già la tessera»
- Perché: emoji e simboli dentro i testi; il colore dell'avviso basta già.

### Admin/Exports.php:62 · Media · bacheca, pagina di errore dell'esportazione
- Ora: «L'esportazione dei dati è sospesa perché la licenza di Associazione Semplice non risulta in regola.»
- Proposta: «L'esportazione dei dati è sospesa: la licenza di Associazione Semplice non è in regola. Sistemala da Impostazioni, riquadro «Licenza».»
- Perché: non dice come rimediare; «non risulta» è burocratese.

### Admin/Exports.php:88 · Bassa · bacheca, pagina di errore
- Ora: «Export non valido.»
- Proposta: «Esportazione non valida.»
- Perché: inglese inutile; nel resto si dice «esportazione».

### Admin/FileCryptPanel.php:39 · Media · bacheca, cifratura dei file (con 341 e 346)
- Ora: «Il lavoro prosegue da solo, un lotto al minuto.» · «Il lavoro prosegue da solo, un lotto al minuto: si può chiudere la pagina.» · «Si procede a lotti di {lotto} file, prima i certificati medici e poi gli altri. Ogni file viene cifrato in un file temporaneo, riletto e controllato con la sua impronta, e solo dopo prende il posto dell'originale. Se il lavoro si interrompe non si perde nulla: si riparte da dove era arrivato. Nel frattempo il sito resta utilizzabile.»
- Proposta: «Il lavoro prosegue da solo, un po' alla volta.» · «Il lavoro prosegue da solo, un po' alla volta: puoi chiudere la pagina.» · «Si procede a gruppi di {lotto} file, prima i certificati medici e poi gli altri. Ogni file cifrato viene riletto e controllato prima di prendere il posto dell'originale. Se il lavoro si interrompe non si perde nulla: riparte da dove era arrivato. Intanto il sito resta utilizzabile.»
- Perché: «lotto», «file temporaneo», «impronta» sono gergo; impersonale al posto del tu.

### Admin/FileCryptPanel.php:48 · Media · bacheca, avvisi sulla cifratura (con 107 e 196)
- Ora: «Va chiesto a chi gestisce il server o l'hosting.» · «Va chiesto a chi gestisce il server o l'hosting di attivare l'estensione sodium di PHP.»
- Proposta: «Chiedi a chi gestisce il server o l'hosting di attivarla.» · «Chiedi a chi gestisce il server o l'hosting di attivare l'estensione sodium di PHP.»
- Perché: passivo impersonale dove serve un'istruzione; alla riga 48 manca anche che cosa chiedere.

### Admin/FileCryptPanel.php:177 · Bassa · bacheca, frase per riaprire i documenti (e 180)
- Ora: «sicuro solo se la frase è lunga: almeno quattro parole.»
- Proposta: «sicuro solo se la frase è lunga (almeno quattro parole).»
- Perché: due «due punti» nella stessa frase.

### Admin/FileCryptPanel.php:193 · Media · bacheca, cifratura dei file
- Ora: «Protegge se qualcuno porta via la sola cartella dei file. Chi ha anche il database può aprirli quando la frase è quella automatica»
- Proposta: «Chi ha anche il database può aprire i file quando la frase è quella automatica»
- Perché: la prima frase ripete, quasi con le stesse parole, il grassetto della riga sopra (192) e torna ancora alla 239.

### Admin/FileCryptPanel.php:207 · Media · bacheca, cifratura dei file
- Ora: «La chiave di cifratura è presente, coincide con quella usata per salvare i file e il blocco di prova si apre: i file cifrati si leggono.»
- Proposta: «La chiave di cifratura funziona: i file cifrati si leggono.»
- Perché: tre verifiche tecniche («blocco di prova») per dire una cosa sola; il titolo dice già «Chiave valida».

### Admin/FileCryptPanel.php:232 · Alta · bacheca, frase per riaprire i documenti
- Ora: «Conservala fuori dal sito (su carta o in un gestore di password): se perdi il sito, altrimenti non la ritrovi.»
- Proposta: «Conservala fuori dal sito (su carta o in un gestore di password): altrimenti, se perdi il sito, non la ritrovi.»
- Perché: le parole sono in ordine sbagliato e la frase non si capisce.

### Admin/FileCryptPanel.php:283 · Media · bacheca, avviso dopo il cambio della frase
- Ora: «Conservale con cura o cancellale; non c'è ancora un cambio completo della chiave.»
- Proposta: «Conserva con cura le vecchie copie, oppure eliminale.»
- Perché: «Conservale» è lontano dal suo soggetto; l'ultima parte è una nota da sviluppatore su una funzione che non c'è.

### Admin/FileCryptPanel.php:288 · Bassa · bacheca, copia con gli allegati
- Ora: «La copia con gli allegati si apre con la frase (nel gratuito la data e ora in cui è stata creata la chiave; con la licenza il codice di licenza, che conosce anche Associazione Semplice e sta nelle email di acquisto).»
- Proposta: «La copia con gli allegati si apre con la frase. Nel gratuito è la data e l'ora in cui è stata creata la chiave; con la licenza è il codice di licenza, che conosce anche Associazione Semplice e sta nelle email di acquisto.»
- Perché: parentesi di 30 parole.

### Admin/FileCryptPanel.php:293 · Media · bacheca, cifratura dei file (con 296, 320, 409)
- Ora: «le loro chiavi, avvolte con la frase in uso.» · «la copia vale quanto quella frase» · «avvolge di nuovo la chiave.» · «qui sotto si incollano i vecchi valori: la chiave viene avvolta di nuovo con quelli di adesso»
- Proposta: «le loro chiavi, protette dalla frase in uso.» · «la copia è sicura quanto quella frase» · «protegge di nuovo la chiave.» · «incolla qui sotto i vecchi valori: la chiave viene protetta di nuovo con quelli di adesso»
- Perché: «avvolgere una chiave» è gergo di crittografia tradotto alla lettera.

### Admin/FileCryptPanel.php:356 · Media · bacheca, conferma
- Ora: «Cifrare i file già caricati? Si consiglia di aver fatto prima una copia di sicurezza.»
- Proposta: «Cifrare i file già caricati? Prima fai una copia di sicurezza.»
- Perché: «si consiglia di aver fatto» è burocratese; due righe sopra lo stesso consiglio è già al tu.

### Admin/FileCryptPanel.php:371 · Media · bacheca, file non cifrati (con 374; stessa parola alla 43)
- Ora: «Un file non si cifra se il suo contenuto non coincide più con l'impronta registrata.» · «Un file che non coincide più con la sua impronta è stato modificato: va controllato e, se serve, caricato di nuovo.»
- Proposta: «Un file non si cifra se è stato modificato dopo il caricamento.» · «Un file modificato dopo il caricamento va controllato e, se serve, caricato di nuovo.»
- Perché: «impronta» è gergo. Nelle stesse righe «in chiaro» e «sul disco» diventano «non cifrati» e «sul server».

### Admin/FivePmPage.php:45 · Bassa · bacheca, 5 per mille
- Ora: «entro 12 mesi va redatto il rendiconto sull'utilizzo (e la relazione illustrativa), da pubblicare.»
- Proposta: «entro 12 mesi devi preparare il rendiconto sull'utilizzo (e la relazione illustrativa), da pubblicare.»
- Perché: «va redatto» è burocratese e non dice a chi tocca.

### Admin/GroupCashPage.php:57 · Bassa · bacheca, Cassa per più persone
- Ora: «ogni voce resta intestata a chi ne beneficia.»
- Proposta: «ogni voce resta intestata alla persona per cui si paga.»
- Perché: «chi ne beneficia» è burocratese.

### Admin/GuidePage.php:24 · Media · bacheca, Guida iniziale
- Ora: «Il programma si compone a righe: date uniche o giorni che si ripetono ogni settimana.»
- Proposta: «Le date si scrivono a righe: date uniche o giorni che si ripetono ogni settimana.»
- Perché: «il programma» altrove è il software; qui sembra che sia il software a «comporsi a righe».

### Admin/GuidePage.php:55 · Bassa · bacheca, Guida iniziale (con 56)
- Ora: «Attiva» / «Spenta» · pulsante «Attiva»
- Proposta: «Accesa» / «Spenta» · pulsante «Accendi»
- Perché: lo stato e il pulsante hanno la stessa parola («Attiva») e la coppia è mista; la riga sopra dice già «accendi solo quelle che ti servono».

### Admin/ImportPage.php:55 · Media · bacheca, Importa (con 56)
- Ora: «con i <strong>soci</strong>, gli <strong>ospiti</strong> e/o la <strong>prima nota</strong>, anche di anni passati. » · «Se nel file Excel ci sono più fogli (es. "Soci", "Ospiti", "Prima nota") vengono letti tutti: lo scopo di ciascuno si riconosce dalle intestazioni. Prima di registrare qualsiasi dato viene mostrata un'anteprima.»
- Proposta: «con i <strong>soci</strong>, gli <strong>ospiti</strong>, la <strong>prima nota</strong> o tutto insieme, anche di anni passati. » · «Se il file Excel ha più fogli (es. «Soci», «Ospiti», «Prima nota») vengono letti tutti: cosa contiene ciascuno si capisce dalle intestazioni. Prima di registrare qualcosa vedrai un'anteprima.»
- Perché: «e/o», virgolette dritte, passivo.

### Admin/ImportPage.php:58 · Media · bacheca, Importa (righe 58-62)
- Ora: un solo paragrafo di circa 250 parole che comincia con «Colonne riconosciute, in qualunque ordine:»
- Proposta: stessi testi, ma in un elenco puntato con un titoletto per blocco: «Colonne di base», «Anni di iscrizione», «Ospiti», «Soci che sono enti», «Cosa è obbligatorio».
- Perché: è il testo più lungo della bacheca e nessuno lo legge in fila; i cinque blocchi ci sono già, uno per frase.

### Admin/ImportPage.php:130 · Media · bacheca, conferma (e 131)
- Ora: «Annullare TUTTO questo import?» · «Non si può rifare con un clic.»
- Proposta: «Annullare tutta questa importazione?» · «Per rifarla dovrai importare di nuovo.»
- Perché: maiuscolo gridato; «non si può rifare con un clic» non dice che cosa si deve fare.

### Admin/ImportPage.php:141 · Media · bacheca, Import già fatti
- Ora: «L'annullamento in blocco annulla i movimenti (restano nel registro come annullati), rimette i saldi iniziali dei conti, toglie i conti nuovi rimasti vuoti, ripristina i dati dei soci aggiornati e rimuove i soci e gli ospiti creati che non sono stati ancora usati (iscritti ad attività, prenotati, con movimenti o pagamenti): quelli già usati restano e vengono elencati.»
- Proposta: «L'annullamento in blocco annulla i movimenti (restano nel registro come annullati), rimette i saldi di partenza dei conti e toglie i conti nuovi rimasti vuoti. Ripristina i dati dei soci aggiornati e toglie i soci e gli ospiti creati e mai usati. Quelli già usati (iscritti, prenotati, con movimenti o pagamenti) restano e vengono elencati.»
- Perché: una frase sola di 57 parole; la parentesi oggi sembra spiegare i «non usati» mentre descrive gli «usati».

### Admin/ImportPage.php:201 · Media · bacheca, anteprima dell'importazione
- Ora: «si aggiusta il saldo iniziale dei conti già esistenti, così il saldo di oggi resta com'è e la storia si completa. Toglila se il file contiene TUTTA la storia e vuoi che i saldi derivino dai movimenti.»
- Proposta: «si aggiusta il saldo di partenza dei conti già esistenti, così il saldo di oggi resta com'è e la storia si completa. Togli la spunta se il file contiene tutta la storia e vuoi che i saldi derivino dai movimenti.»
- Perché: «Toglila» non ha un nome a cui riferirsi; maiuscolo gridato; nei conti il campo si chiama «Saldo di partenza».

### Admin/IncomePage.php:154 · Media · bacheca, Nuovo incasso (e 155)
- Ora: «l'incasso non verrà salvato finché c'è questa voce»
- Proposta: «togli questa voce per salvare l'incasso»
- Perché: dice il blocco ma non il gesto da fare.

### Admin/InventoryPage.php:48 · Bassa · bacheca, Inventario (e 135)
- Ora: «Ubicazione»
- Proposta: «Dove si trova»
- Perché: burocratese.

### Admin/LedgerPage.php:103 · Bassa · bacheca, Prima nota
- Ora: «I movimenti non si modificano: per correggere si annulla e si registra di nuovo. L'annullamento resta tracciato.»
- Proposta: «I movimenti non si modificano: per correggerne uno, annullalo e registralo di nuovo. L'annullamento resta visibile.»
- Perché: impersonale al posto del tu; «tracciato» è da macchina (anche alla riga 86 e in Actions.php:1171).

### Admin/LevelsEditor.php:22 · Media · bacheca, Soci e quote (con 24)
- Ora: «Ogni livello ha il suo nome (come appare sulla tessera e negli elenchi), una base che ne decide il comportamento, se serve una quota propria e il diritto di voto (vota «sì» o «no»: conta nelle votazioni, nel numero legale delle assemblee e nei dati per il RUNTS; i minorenni non votano mai). » · «Un livello con dei soci non si cancella: se lo togli resta, ma non si può più assegnare.»
- Proposta: «Ogni livello ha il suo nome (come appare sulla tessera e negli elenchi), una base che ne decide il comportamento, una quota propria se serve e il diritto di voto, sì o no. Il voto conta nelle votazioni, nel numero legale delle assemblee e nei dati per il RUNTS; i minorenni non votano mai. » · «Un livello con dei soci non si elimina: se lo togli resta ai soci che lo hanno, ma non si può più assegnare.»
- Perché: 55 parole con una parentesi che ne contiene un'altra frase; difetto 1.

### Admin/LicenseNotice.php:68 · Bassa · bacheca, avviso sulla licenza
- Ora: «C'è ancora una breve tolleranza: rinnovala per non perdere le funzioni del tuo piano.»
- Proposta: «Hai ancora qualche giorno: rinnovala per non perdere le funzioni del tuo piano.»
- Perché: «tolleranza» è da contratto.

### Admin/LicensePanel.php:33 · Media · bacheca, Licenza
- Ora: «Livello:»
- Proposta: «Piano:»
- Perché: nella stessa pagina i «livelli» sono i livelli di socio; per la licenza si parla ovunque di «piano».

### Admin/LimitsPage.php:13 · Media · bacheca, Limiti e soglie (con 16)
- Ora: «Qui trovi i limiti, le dimensioni dei gruppi di invio e i comportamenti automatici del plugin.» · «sposta il rischio dal plugin al server, al servizio di posta o ai gateway di pagamento.»
- Proposta: «Qui trovi i limiti, quanti messaggi partono per volta e i comportamenti automatici del gestionale.» · «sposta il rischio dal gestionale al server, al servizio di posta o ai servizi di pagamento.»
- Perché: «dimensioni dei gruppi di invio» e «gateway» sono gergo.

## Da verificare (sospetti errori di fatto, non riscritti)

- **Ripristino: la copia dello stato precedente c'è o no?** `BackupPage.php:39` dice «Prima del ripristino il sito salva da solo una copia dello stato attuale (qui sotto)»; il messaggio d'esito in `Actions.php:1056` dice «Lo stato precedente non è stato conservato sul sito: se ti serve, scarica una copia prima di ripristinare» (e arriva a ripristino già fatto). Una delle due è falsa, oppure la seconda va mostrata solo in un caso preciso.
- **Percorso «Impostazioni → Copia di sicurezza».** `FileCryptPanel.php:34`, 43 e 51 mandano lì; nel menu la Copia di sicurezza è sotto Strumenti (`Admin.php:45`) e la guida dice «Strumenti → Copia di sicurezza» (`GuidePage.php:38`).
- **«(Dati e fiscalità)».** `AccountingPage.php:43` chiude con questa parentesi, ma la pagina nel menu si chiama «Dati dell'ente» (`Admin.php:48`, 126). Forse il nome cambia nei piani a pagamento: se no, va corretto e completato («in Impostazioni → Dati dell'ente»).
- **Codice fiscale del 5 per mille.** `FivePmPage.php:37` dice «inseriscilo in Impostazioni» e il link porta a «Soci e quote» (`asem-settings`), ma il codice fiscale si scrive in «Dati dell'ente» (`EntityPage.php:78`).
- **«I corsi si rinnovano ogni mese».** `GuidePage.php:24`: i corsi possono anche essere a pagamento unico o trimestrale (`ActivitiesPage.php:124`).
- **Acconto.** `BookDeskPage.php:111`: «il resto resta come acconto». Da chiarire se la parte non versata resta da pagare (come ho proposto) o se la somma versata è registrata come acconto.
- **Donazioni in prima nota.** `DonatePage.php:32`: «si registrano in prima nota come «Erogazione liberale» (Nuovo incasso)». Se la registrazione va fatta a mano, deve dire «vanno registrate»; se è automatica, il link a «Nuovo incasso» confonde.
- **«Iscrizione aggiornata.»** `Actions.php:241`: ho proposto «Tessera aggiornata» supponendo che l'azione cambi tessera o validità; da confermare su cosa agisce.
- **«Area riservata» o «area soci».** Vedi difetto 10: serve decidere qual è il nome a schermo dell'area del socio (la voce di menu creata sul sito è «Area riservata», `Actions.php:1400`) e poi allineare bacheca e glossario.
- **Dipendenti e RUNTS.** `EmployeesPage.php:71`: «in servizio alla data» non dice quale data (31 dicembre?). `EmployeesPage.php:72`: «Per il socio usa un'altra email, oppure l'accesso personale con la stessa email» non si capisce che cosa consigli.
- **Chi accredita il 5 per mille.** `FivePmPage.php:45`: «l'importo accreditato dall'Agenzia delle Entrate». Da far controllare al consulente (per gli enti del RUNTS il pagamento arriva da un'altra amministrazione).
- **Percorsi di menu non controllabili da questi file.** «Registri → Certificati medici» (`CertificatesPage.php:163`), «Registri → Presenze» (`GuidePage.php:26`), «Contabilità → Rendiconto» (`GuidePage.php:33`), «Impostazioni → Contabilità» (`Admin.php:301`), «Impostazioni → Testi personalizzati» (`GuidePage.php:37`): le voci non sono nel menu di base di `Admin.php` (le aggiungono i piani a pagamento); da ricontrollare lì.
- **«Restituzione quote».** `DeleteActivityPage.php:165` e `DeleteBookingPage.php:72` citano questa voce di prima nota anche per i contributi di corsi ed eventi: per il glossario «quota» è solo la quota associativa. È il nome di una voce, quindi non l'ho toccato.

