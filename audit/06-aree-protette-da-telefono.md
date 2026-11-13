# Aree protette provate da telefono sul sito vero (13/11/2026)

Accesso fatto dalla titolare; io ho solo letto e navigato (nessun salvataggio, invio o cancellazione).
Metodo: schermo simulato da 375 px, come nel report 05. Nel gestionale la simulazione non disegna bene la barra in basso
(le voci compaiono solo in parte): quella barra va guardata su un telefono vero e non è tra i problemi elencati.

Provato: area soci della demo (socio «Mario Demo»), area clienti del portale (account dello staff, senza licenze),
pannello `/wordpress/admin`, gestionale con l'associazione di prova «Polisportiva Prova» (vuota).
Provata in un secondo momento anche la bacheca di WordPress della demo, come amministratore del plugin (non come segreteria).
Limiti: l'account del portale non ha licenze né abbonamenti e l'associazione di prova non ha dati, quindi elenchi pieni,
fatture, rinnovi e schede dei soci non si sono potuti vedere.

## Area soci del plugin (demo)

| # | Gravità | Cosa succede | Proposta |
|---|---|---|---|
| S1 | Bloccante | Il socio entrato non ha nessun pulsante «Esci» nella pagina. La fascia della demo continua a dire «Entra», e `/prova` dice «per cambiare ruolo usa Esci nella barra blu», che per il socio non c'è. Su un telefono condiviso non ci si può disconnettere. | (La sessione «Portale plugin» segnala che l'«Esci» del socio è già in main con la PR 579, non ancora nella demo: da verificare dopo l'aggiornamento della demo.) «Esci» accanto a «Ciao Mario» e in fondo alla pagina; nella fascia della demo «Esci» al posto di «Entra» quando si è dentro. |
| S2 | Importante | Pagina unica lunga 6.000 px con nove sezioni (attività, risorse, crediti, calendario, pagamenti, ospiti, profilo, ricevute, app). Le voci in alto scorrono di lato e portano solo a un punto della stessa pagina. | Tessera e «cosa devo pagare» in cima; il resto in sezioni richiudibili o in pagine separate. |
| S3 | Importante | La demo deve mostrare il Pro, ma il socio vede «Da versare 25,00 €» e «Il pagamento si effettua in sede presso la segreteria»: il pagamento online, la funzione Pro più richiesta, non si vede. | Attivare nella demo un pagamento di prova, oppure un pulsante «Paga online» che spiega «nella demo non si paga davvero». |
| S4 | Importante | Parole poco chiare: «Scarica i miei dati (JSON)», «8,00 € a fascia», «ospiti fino a 1», «· la tua» nel calendario, «non è cancellabile», «Cambia nominativo». | «Scarica i miei dati», «8,00 € all'ora», «puoi portare 1 ospite», «sei iscritto», «non si può annullare», «Cedi il posto a un'altra persona». |
| S5 | Importante | La fascia fissa della demo copre circa 80 px in fondo, sopra pulsanti e testi. | Una riga sola, richiudibile (già nel report 05, M18). |
| S6 | Miglioria | «Cambia nominativo» e «Aggiungi un ospite» sono alti 27 px; «‹ ottobre» e «dicembre ›» 38 px; i link in fondo al profilo 38 px. | Portarli a 44 px. |
| S7 | Miglioria | Sulla tessera la terza riga della frase sotto il QR è spostata rispetto alle prime due; «Stampa la tessera» e «Scarica il PDF» vanno su due righe. | Testo centrato in un solo blocco; pulsanti uno sotto l'altro a tutta larghezza. |
| S8 | Miglioria | Dieci pulsanti uguali «Prenota (pago in segreteria)» uno sotto l'altro per le fasce orarie. | Elenco di orari con un solo pulsante «Prenota» dopo la scelta. |

Bene: campi del profilo grandi e leggibili, tessera chiara, ricevute e attestazione facili da trovare.

## Bacheca del plugin nella demo (amministratore del plugin)

Provate: Bacheca, Rubrica soci, Corsi ed eventi, Cassa, Prima nota, Contabilità, Rendiconto, Impostazioni (le altre schede
solo con le misure automatiche; Risorse, Crediti, Registri e Strumenti non guardate a vista).

| # | Gravità | Cosa succede | Proposta |
|---|---|---|---|
| B1 | Importante | Dati della demo poco credibili per un commercialista: la «Cassa contanti» chiude a **−19,00 €** (i contanti non possono essere negativi) e il rendiconto mostra un disavanzo; tutte le spese, anche «Acqua e bibite» e «Rinfresco dopo la riunione», sono sotto «Costi generali (affitto, utenze, assicurazione)»; un socio ha la tessera «Valida fino al 01/09/2120». | Dati di esempio con saldo positivo, un conto in banca, 4–5 categorie diverse e date realistiche. |
| B2 | Importante | Bacheca: prima di «Da fare» ci sono cinque avvisi uno sopra l'altro (demo, copia di sicurezza, configurazione, rimborsi, anno 2027): su telefono occupano più di una schermata. La pagina è lunga 9.700 px e un menu a tendina la allarga oltre lo schermo (429 px su 375). | Al massimo un avviso per volta, gli altri raccolti in «3 cose da sistemare»; elenchi limitati alle prime 5 righe con «Vedi tutti». |
| B3 | Importante | Pagine lunghissime senza suddivisione: Rubrica soci 9.000 px per 40 persone, Corsi ed eventi 9.800, Prima nota 11.100, Impostazioni 10.600. | 20 righe per pagina con «Carica altri». |
| B4 | Importante | Pulsanti piccoli e vicini: in Bacheca «Incassa» e «Sospendi» sono affiancati e bassi (errore facile col dito); tra 70 e 140 elementi per pagina sono sotto i 36 px («Annulla», «Allegati», «invia per email», «WhatsApp», «Email»). | Azioni principali a 44 px; «Sospendi» dentro un menu «Altro». |
| B5 | Importante | Schede in alto che scorrono di lato senza alcun segno che continuano (si legge «Adempiment»): Cassa ne ha 9, Contabilità 7, Impostazioni due file. In Contabilità ci sono sia «Anno solare» sia «Anni solari». | Sfumatura o freccia a destra; un solo «Anni»; su telefono un menu a tendina al posto delle schede. |
| B6 | Importante | L'avviso della demo (5 righe) è ripetuto in cima a ogni pagina della bacheca. | Una riga sola, richiudibile. |
| B7 | Miglioria | Rendiconto: gli importi vanno a capo («0,00» sopra, «€» sotto); la tabella «Cassa e conti» non occupa tutta la larghezza. Titolo «Rendiconto per cassa» senza «Mod. D». | Importi su una riga; «Rendiconto per cassa (Mod. D)». |
| B8 | Miglioria | Parole poco chiare: «Senza accesso · Invia link», «Esporta CSV», «Adempimenti (5x1000)», «Rifiutando, la domanda sparisce». | «Non è ancora entrato nell'area soci · Invia l'invito», «Scarica per Excel», «5 per mille», «Se rifiuti, la domanda viene cancellata». |

Bene: su telefono soci e movimenti sono già schede e non tabelle; campi da 16 px; testi d'aiuto chiari («Tessera scaduta o in
scadenza entro 30 giorni…»); il rendiconto spiega da dove vengono i numeri e nomina il commercialista.

## Area clienti del portale

| # | Gravità | Cosa succede | Proposta |
|---|---|---|---|
| C1 | Importante | Titolo «Le tue licenze» anche per chi ha (o avrà) solo il gestionale; il titolo della scheda del browser è quello della home. | «Il tuo account»; titolo della scheda «Il tuo account · Associazione Semplice». |
| C2 | Importante | Stato vuoto «Nessuna licenza, per ora — Parti dal piano gratuito oppure scegli un piano Pro»: non nomina il gestionale online. | Aggiungere «oppure prova il gestionale online». |
| C3 | Miglioria | «Cambia la password» alto 24 px; «Gestisci SaaS» e «Gestisci Plugin» sono parole da staff (le vede solo lo staff: va bene, ma «SaaS» non deve arrivare ai clienti). | Riga più alta. |

Non visto: abbonamenti, fatture, cambio dominio, disdetta (l'account non ne ha).

## Pannello `/wordpress/admin` (staff)

- Conferma il motivo della vendita chiusa: riquadro «Da completare: Stripe (chiave segreta e segreto del webhook) · PayPal».
- La tabella «Ultime licenze» scorre di lato dentro il suo riquadro: accettabile per lo staff.
- Cinque collegamenti di servizio in cima («Piattaforma SaaS · Vai a SaaS · Vai a Supporto · Esci · La mia area cliente · Supporto») alti 18 px e con «Supporto» due volte.

## Gestionale online

| # | Gravità | Cosa succede | Proposta |
|---|---|---|---|
| G1 | Bloccante | `/gestionale/abbonamento` risponde «Non hai accesso a questa pagina… chiedi a chi gestisce l'associazione di darti il permesso» anche all'**Amministratore** dell'associazione. Nel menu la voce non c'è. Chi gestisce l'associazione non ha modo di vedere o cambiare il piano. | Voce «Abbonamento» nel menu «Gestione» per l'amministratore; messaggio diverso quando è un amministratore a non poter entrare. |
| G2 | Importante | Pagina «Conti»: la tabella (Conto, Tipo, Saldo iniziale, Saldo, azioni) è più larga dello schermo e allarga tutta la pagina (505 px su 375): si muove di lato anche la barra in basso. «Salva» e «Chiudi» nella riga sono alti 20 px. | Su telefono una scheda per conto (nome, tipo, saldo) con le azioni sotto. |
| G3 | Importante | Pagina «Attività»: la tabella esce dallo schermo di circa 60 px. | Elenco a schede su telefono. |
| G4 | Importante | Rendiconto: titolo corretto («Rendiconto per cassa 2026», «esercizio aperto», «Avanzo / disavanzo»), ma nessun riferimento al Mod. D e nessun pulsante per stampare o scaricare. «Chiudi l'esercizio 2026» è un pulsante pieno, in evidenza, per un'azione che non si annulla. | «Stampa» e «Scarica»; dicitura «schema Mod. D»; pulsante di chiusura secondario, con conferma che spiega le conseguenze. |
| G5 | Importante | Stato vuoto della prima nota: l'unico pulsante è «Registra un incasso»; per una spesa bisogna passare da «Nuovo». | Due pulsanti: «Registra un incasso» e «Registra una spesa». |
| G6 | Miglioria | Pulsante «Nuovo» alto 36 px, logo-collegamento 32 px, «Giorno / Settimana / Mese» 36 px, caselle da 20 px, campo del file da importare con testo piccolo. | Portare i pulsanti a 44 px. |
| G7 | Miglioria | In «Soci» i filtri sono i tipi di persona («Genitore o tutore (non socio)», «Ospite di un socio»): scorrono di lato e il più utile, «da rinnovare», non c'è. | Filtri per stato (in regola, da rinnovare, scaduti) prima dei tipi. |

Bene: testi semplici e in italiano corretto («Tutto a posto», «Oggi non c'è niente in programma», «Crea la prima scheda o
importa l'elenco da Excel»); configurazione guidata in una pagina sola con il Numero RUNTS; campi da 16 px; nessuna pagina,
tranne Conti e Attività, esce dallo schermo.
