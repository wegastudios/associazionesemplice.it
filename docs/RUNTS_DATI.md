# Dati per il RUNTS (plugin gratuito, tutte le edizioni)

Decisioni di Luca del 13/11/2026. Solo regole generali: nessun dato di associazioni reali.

## Avvertenza sulle fonti
Le regole sul RUNTS (data di riferimento, numero minimo di soci, termini di 30 giorni e del 30 giugno) vengono da **commenti professionali** (studi di consulenza, centri di servizio per il volontariato), **non dal testo del decreto**. I testi mostrati agli utenti sono quindi prudenti («controlla sul portale del RUNTS») e non affermano mai un obbligo come certo. Prima di pubblicare conviene farli rileggere da chi segue l'associazione.

## Cosa mostra la voce «Registri → Dati per il RUNTS»
Per ogni **31 dicembre** (la data di riferimento è sempre il 31/12, qualunque sia il mese di inizio dell'esercizio: il conteggio è fatto a una data, non per esercizio):

1. **Soci persone fisiche con diritto di voto**: solo il numero.
2. **Soci che sono enti**: elenco con denominazione, codice fiscale e «stessa sezione del RUNTS» (sì / no / da indicare).
3. **Volontari iscritti nel registro dei volontari**: numero.
4. **Lavoratori** (dipendenti a qualsiasi titolo): numero.

### Chi è socio a una data
Si riusa il criterio unico del libro soci (`MemberBook::qualifies()`) applicato a un solo giorno: tessera valida quel giorno, fondatore ammesso entro quel giorno, non già uscito prima. Così libro soci e dati RUNTS non possono dare risposte diverse.

### Diritto di voto
Decisione di Luca del 13/11/2026 (sostituisce la scelta «per tipo di socio» della prima versione).
- **Gratuito**: hanno diritto di voto **tutti i soci** (fondatori, ordinari, soci e volontari), non è configurabile; in Impostazioni → Soci e quote non ci sono più le caselle per tipo, solo una nota.
- **Pro**: il voto si decide **per singolo livello di socio** (l'editor dei livelli è Pro): ogni livello ha «Voto: sì / no» (colonna `member_levels.votes`, schema v61). Un livello nuovo parte da «sì». Un socio senza livello vota. Se la funzione «livelli» non c'è (licenza scaduta) i livelli non contano e votano tutti, come le quote dei livelli tornano a una sola.
- I **minorenni non votano mai**. Vota chi è maggiorenne alla data del conteggio (18 anni compiuti entro quel giorno). Senza data di nascita si lascia il voto, come prima: la pagina dice quanti votanti sono in questo caso.
- **Punto unico**: `VotingRight` (`person_votes`, `sql_condition`, `reason`). Il Pro si aggancia con i filtri `asem_voting_right_level` (singola persona) e `asem_voting_right_sql` (interrogazioni SQL), nel file solo-Pro `LevelVoting.php`. Votazioni (`Polls::is_eligible`, `Polls::eligible_count`), assemblee (`Assemblies::eligible_ids`, numero legale, deleghe) e dati RUNTS passano tutti da qui.
- **Migrazione (v61)**: la colonna parte a «sì» per tutti i livelli, quindi chi non ha toccato nulla non cambia (votano tutti). Chi nella v60 aveva tolto la spunta a un tipo di socio, mantiene l'effetto: i livelli di quella base passano a «no» (conta solo nel Pro). L'opzione `voting_types` viene tolta.
- Il voto è quello di **oggi** (non c'è lo storico): per una data passata la pagina lo dice.
- La scheda del socio mostra «Sì», «No: è minorenne», «No: il suo livello di socio non vota» oppure «Sì (manca la data di nascita)».

### Soci enti
Il plugin prima non distingueva persona fisica / ente. Ora la scheda del socio ha: tipo di soggetto (persona fisica o ente), denominazione (nel campo «Cognome»; il «Nome» resta vuoto), codice fiscale dell'ente (11 cifre con controllo, o 16 caratteri), «iscritto nella stessa sezione del RUNTS». Un ente non conta tra le persone fisiche. Colonne nuove in `people`: `subject_kind`, `runts_same_section` (schema v60).

### Volontari
Il plugin ha il tipo «Socio e volontario» ma **non un registro dei volontari** (le «Assicurazioni» del Pro sono un registro di polizze, non l'elenco dei volontari). Per ora il numero è inserito a mano una volta l'anno in Dati dell'ente, con l'anno di riferimento; la pagina avvisa se l'anno non coincide e riporta, solo come promemoria, quanti soci «socio e volontario» c'erano a quella data. **Da definire**: un vero registro dei volontari.

### Lavoratori
Il numero è il conteggio degli utenti «Dipendente» (ruolo `asem_employee`, `Employees`) **in servizio alla data**. `Runts::workers_count()` passa dal filtro unico `asem_runts_workers_count` (valore, data), a cui `Employees::runts_workers()` si aggancia. Ogni dipendente ha un primo e un ultimo giorno di servizio, entrambi facoltativi (senza date è sempre in servizio; i due giorni sono compresi): la regola è `EmployeeRules::is_active()`. I dipendenti si gestiscono da Rubrica soci → Dipendenti. Un dipendente non è nel libro soci e non conta tra i soci. Il numero è un conteggio dei dipendenti registrati: non sostituisce il controllo sul portale del RUNTS.
**Se il filtro non risponde** (valore `null` o `false`, cioè «non disponibile») la pagina non scrive uno 0 finto: mostra «—» e «Il programma non li conta ancora: scrivi tu il numero nel portale del RUNTS.». Uno 0 vero (ruolo presente, nessun dipendente) resta 0. `Runts::workers_count()` restituisce quindi `?int`; il valore di partenza del filtro è `null`.

## Avvisi
- **Minimo di soci per ODV e APS** (sezione indicata in Dati dell'ente): almeno **7 persone fisiche oppure 3 enti** (della stessa sezione). Sono costanti di legge (`RuntsRules`), non impostazioni. Se si scende sotto, avviso in bacheca e nella voce, con la data da cui si è scesi e la scadenza dei **30 giorni** per comunicare la variazione, sempre con «controlla sul portale del RUNTS». **Come si conta** (decisione di Luca del 13/11/2026): come nel conteggio del 31 dicembre, cioè i soci con la **tessera valida oggi** (criterio unico `MemberBook::qualifies()` sul giorno corrente), non i non cessati. Un socio che non ha rinnovato è «cessato de facto» fino al rinnovo e non è contato; i **cessati veri** sono solo gli espulsi e i dimissionari, che nel libro soci hanno data e causa. Distinzione che resta: «cessato» ≠ «non rinnovato». L'avviso lo dice («chi ha la tessera scaduta non è contato finché non rinnova»). Per non fare falsi allarmi a gennaio mostra anche quanti soci hanno la tessera **scaduta da poco** (al massimo 90 giorni, `RuntsRules::RECENT_EXPIRY_DAYS`): «se rinnovano, tornano nel conteggio» (e, se rinnovando tutti si risalirebbe sopra il minimo, lo dice).
- **Dove sta la data da cui si è scesi** (`since`): non è memorizzata, si **ricalcola** ogni volta dalla storia (ingressi, cessazioni, inizio e fine di ogni tessera) con lo stesso criterio `qualifies()`, scorrendo all'indietro le date in cui il conteggio può cambiare (`RuntsRules::change_dates` e `below_since`, con il conteggio passato da `Runts::minimum_state`). Così non dipende da chi apre la pagina e non serve una colonna. È il giorno dopo l'ultima data in cui si era ancora sopra il minimo. Se non è ricavabile (sotto il minimo da sempre) l'avviso lo dice senza inventare una scadenza.
- **Riporto sopra il minimo** (**non verificato**, da confermare con il commercialista): l'avviso aggiunge «Per quanto ci risulta, il numero va riportato sopra il minimo entro un anno: controlla con chi ti segue.»
- **Promemoria del 30 giugno**: dal 1° maggio al 30 giugno, per chi ha indicato sezione o numero di iscrizione, avviso (chiudibile) «aggiornamento dei dati riferiti al 31 dicembre dell'anno prima, solo se cambiati».

## Importazione CSV dei soci enti
Colonne nuove (riconosciute in qualunque ordine): **Tipo soggetto** (`persona` / `ente`), **Denominazione** (al posto di Nome e Cognome; per i soli enti basta lei), **Codice fiscale** (11 cifre con controllo, o 16 caratteri: stessa regola di `Rules::validate_person`), **Stessa sezione RUNTS** (sì / no). La denominazione finisce nel «cognome», il nome resta vuoto, come nella scheda. Un ente ha bisogno di almeno uno tra codice fiscale, email, cellulare o tessera. Anteprima ed errori per riga come prima (denominazione mancante, codice fiscale non valido, tipo o sezione non riconosciuti, un ospite non può essere un ente, una persona non può sovrascrivere un ente e viceversa). **Anni chiusi**: un ente con data di ingresso in un esercizio chiuso diventa un errore già nell'anteprima (`YearLock`); le iscrizioni per anno sono bloccate dal controllo che c'era già. L'annullamento (`import_batches`) è invariato e ripristina anche tipo e sezione.

## Libro soci: colonna «Tipo»
`kind` (persona / ente) è un campo minimo del libro: sta in `MemberBook::SNAPSHOT_FIELDS` e `ROW_FIELDS` e compare a schermo, nel PDF (riga «Tipo: …») e nel CSV/XLS («Tipo»). Le fotografie già scritte non si toccano: le righe vecchie senza il campo mostrano «Persona fisica» (anche se il socio oggi è un ente: nella fotografia vecchia non c'era scritto).

## Non fatto (altre fasi)
Tessera che segue l'anno sociale; rendiconto / V3; registro dei volontari (resta il numero a mano con l'anno, decisione del 13/11/2026: non si costruisce altro).
