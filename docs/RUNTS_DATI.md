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
È una proprietà del **tipo di socio** (fondatore, ordinario, socio e volontario), scelta in Impostazioni → Soci e quote; i **minorenni non votano mai**. Vota chi è di un tipo che vota ed è maggiorenne alla data del conteggio (18 anni compiuti entro quel giorno). Senza data di nascita si lascia il voto, come prima.
- Il programma già calcolava «chi ha diritto di voto» per votazioni e assemblee (socio in regola, non sospeso, non uscito): la nuova regola si aggiunge **nello stesso punto** (`Polls::is_eligible`, `Polls::eligible_count`, `Assemblies::eligible_ids`), senza duplicare.
- **Migrazione prudente**: nessuna modifica ai dati. Di partenza votano tutti e tre i tipi, cioè ciò che già succedeva. L'unica differenza è l'esclusione dei minorenni con data di nascita nota. I sondaggi tra gli iscritti a un'attività restano aperti a chi partecipa (la regola vale per le votazioni di tutti i soci e per le assemblee).
- Il tipo che vota è quello di **oggi**: il programma non tiene lo storico, per una data passata la pagina lo dice.

### Soci enti
Il plugin prima non distingueva persona fisica / ente. Ora la scheda del socio ha: tipo di soggetto (persona fisica o ente), denominazione (nel campo «Cognome»; il «Nome» resta vuoto), codice fiscale dell'ente (11 cifre con controllo, o 16 caratteri), «iscritto nella stessa sezione del RUNTS». Un ente non conta tra le persone fisiche. Colonne nuove in `people`: `subject_kind`, `runts_same_section` (schema v60).

### Volontari
Il plugin ha il tipo «Socio e volontario» ma **non un registro dei volontari** (le «Assicurazioni» del Pro sono un registro di polizze, non l'elenco dei volontari). Per ora il numero è inserito a mano una volta l'anno in Dati dell'ente, con l'anno di riferimento; la pagina avvisa se l'anno non coincide e riporta, solo come promemoria, quanti soci «socio e volontario» c'erano a quella data. **Da definire**: un vero registro dei volontari.

### Lavoratori
Il numero è il conteggio degli utenti «Dipendente», un tipo di utente che sarà costruito in una **fase separata**. Finché non esiste, `Runts::workers_count()` restituisce 0 passando dal filtro unico `asem_runts_workers_count` (valore, data): chi costruirà il ruolo si aggancia lì. La pagina scrive «Lavoratori: n (utenti Dipendente)» con nota che il valore è 0 finché il ruolo non c'è.

## Avvisi
- **Minimo di soci per ODV e APS** (sezione indicata in Dati dell'ente): almeno **7 persone fisiche oppure 3 enti** (della stessa sezione). Sono costanti di legge (`RuntsRules`), non impostazioni. Se si scende sotto, avviso in bacheca e nella voce, con la data da cui si è scesi e la scadenza dei **30 giorni** per comunicare la variazione, sempre con «controlla sul portale del RUNTS». Per questo controllo si contano i soci **non cessati** (una tessera non rinnovata non è una cessazione, altrimenti a gennaio l'avviso scatterebbe per tutti); la data è ricavata dalle date di ingresso e di uscita (`RuntsRules::below_since`). Se non è ricavabile (sotto il minimo da sempre) l'avviso lo dice senza inventare una scadenza.
- **Promemoria del 30 giugno**: dal 1° maggio al 30 giugno, per chi ha indicato sezione o numero di iscrizione, avviso (chiudibile) «aggiornamento dei dati riferiti al 31 dicembre dell'anno prima, solo se cambiati».

## Non fatto (altre fasi)
Utente «Dipendente»; tessera che segue l'anno sociale; rendiconto / V3; registro dei volontari; importazione CSV dei soci enti; colonna «tipo» nel libro soci.
