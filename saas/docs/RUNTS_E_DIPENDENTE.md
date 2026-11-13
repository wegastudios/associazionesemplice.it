# Dati per il RUNTS, socio ente e utente «Dipendente»: disegno

Decisioni del titolare, 13/11/2026. Stato: **disegno**; le tre cose non decise sono in §5.

## 1. Cosa si decide (già deciso)

1. «Dati per il RUNTS» sta **in tutti i piani**.
2. **Diritto di voto**: casella sulla scheda del socio, spuntata in partenza per i maggiorenni, modificabile.
3. **Lavoratori**: numero inserito a mano una volta l'anno nelle impostazioni dell'associazione.
4. Nuovo tipo di utente **Dipendente**: parte con i permessi della segreteria; presidente, vicepresidente e amministratori possono togliergliene alcuni, uno per uno; non è socio in partenza (non entra nel libro soci, non ha tessera, non conta tra i soci) ma può esserlo.
5. **Socio ente**: persona fisica o ente, con denominazione e codice fiscale.

Cosa chiede il RUNTS (da fonti professionali, testo del decreto non verificato): entro il 30 giugno i dati al 31 dicembre dell'anno prima, solo se cambiati; soci con diritto di voto persone fisiche (solo il numero); soci enti (denominazione, CF, se iscritti nella stessa sezione); volontari iscritti nel registro; lavoratori dipendenti e parasubordinati; ODV/APS sotto il minimo (7 persone o 3 enti): comunicazione entro 30 giorni.

## 2. Anagrafica (modulo Soci)

Nuove colonne su `app.people` (migrazione org):
- `entity_kind text NOT NULL DEFAULT 'person' CHECK IN ('person','entity')`; per `entity`: `entity_name`, `tax_code` = codice fiscale dell'ente (il campo esistente, cifrato), `entity_same_section boolean` (iscritto nella stessa sezione del RUNTS).
- `voting_rights boolean NOT NULL DEFAULT false`; all'inserimento di un socio persona maggiorenne si propone spuntata (regola in `PeopleService::clean`, modificabile); per un ente e per un minorenne parte non spuntata.
- Per i soci già presenti la migrazione non indovina: li lascia da confermare (vedi §4, «da verificare»).
- `MemberType` non cambia; l'ente è un `ordinary` con `entity_kind = 'entity'`. Il libro soci mostra la denominazione.
- Le fotografie del libro (PR del libro soci) aggiungono `kind`, `voting` a `MemberBookSnapshots::FIELDS`.

## 3. Pagina «Dati per il RUNTS» (gestionale, tutti i piani)

Una pagina (sezione Soci o Gestione), data di riferimento 31 dicembre dell'anno precedente (modificabile), calcolata dai dati con `MemberBookService::entersPeriod()` su [data, data]:
- soci con diritto di voto, persone fisiche: numero;
- soci enti: elenco (denominazione, CF, stessa sezione);
- volontari iscritti nel registro dei volontari (tipo `volunteer` alla data): numero;
- lavoratori: il numero inserito (impostazione annuale `runts.workers.<anno>`), con valore suggerito dal conteggio dei dipendenti (§5);
- avviso sotto il minimo: 7 persone fisiche / 3 enti (solo se l'associazione è ODV o APS: serve il tipo di ETS già presente nell'anagrafica dell'associazione, da verificare);
- promemoria del 30 giugno (avviso nella pagina iniziale dal 1° giugno) e del termine dei 30 giorni se si scende sotto il minimo;
- nessun invio al RUNTS: i numeri si copiano nel portale del RUNTS. Pulsante «Copia» e stampa.

Stima: pagina e calcolo 1,5 giorni, campi anagrafica e ente 1,5, impostazione lavoratori e promemoria 0,5, test 1.

## 4. Utente «Dipendente» (modulo Core, accessi)

- Nuovo `OrgRole::Employee` (`'employee'`), etichetta «Dipendente».
- Permessi di partenza = quelli della segreteria (`OrgRole::Operator`).
- Togliere permessi: nuova colonna `core.org_users.denied_abilities jsonb NOT NULL DEFAULT '[]'` con le `Ability` tolte; `Operator::can()` = permessi del ruolo meno quelli tolti. Chi può modificarli: titolare, presidente, vicepresidente, amministratori (oggi i ruoli sono Owner/Admin/Operator/Accountant/Custom: presidente e vicepresidente corrispondono a Owner/Admin o a un ruolo personalizzato; da chiarire in §5).
- Pagina Operatori: invito come «Dipendente» e, per ogni dipendente, l'elenco dei permessi con un interruttore ciascuno (solo ai ruoli sopra); ogni modifica nel registro delle azioni.
- Non è socio: nessuna scheda soci creata; si può **collegare** a una scheda socio (come già per chi è operatore e socio, per email), allora è anche socio.
- Il dipendente alimenta il suggerimento del numero di lavoratori della pagina RUNTS.
- Test: un dipendente con permessi tolti riceve 403 sulle rotte dei permessi tolti; Owner/Admin possono toglierli, Operator e Dipendente no.

Stima: ruolo, colonna, `can()` 1, interfaccia permessi 1,5, invito e collegamento al socio 1, test 1.

## 5. Da decidere con il titolare (non deciso da noi)

1. **Il dipendente conta tra gli operatori del piano** (2 nella Piccola, 5 nella Media, senza limite nella Grande)? Oggi `OrgMembers::assertOperatorAvailable` conta ogni ruolo tranne Commercialista. Proposta: **sì**, perché accede come la segreteria; altrimenti si aggira il limite.
2. **Numero dei lavoratori**: valore suggerito dai dipendenti inseriti, campo comunque modificabile (non tutti i lavoratori avranno un accesso, e i parasubordinati sono da contare a parte). Proposta del titolare/audit: suggerito + modificabile.
3. **Quali permessi si possono togliere e quali no.** Proposta: tutti quelli della segreteria si possono togliere uno per uno, tranne «vedere la propria scheda e il profilo»; mai si può dare un permesso in più del ruolo. Da confermare l'elenco (es. dati sanitari, esportazioni, contabilità).
4. **Presidente e vicepresidente**: oggi non sono ruoli. Si mappano su Owner/Admin o si creano come cariche? Proposta: restano Owner e Admin (chi ha «Gestire gli operatori»).
5. **Soci già presenti** all'attivazione del diritto di voto: spuntati per i maggiorenni con una conferma guidata, oppure da controllare uno per uno? Proposta: spuntati in blocco, con avviso e pagina di verifica.

## 6. Ordine di consegna

1. Anagrafica: socio ente e diritto di voto (+ fotografie del libro con i campi nuovi).
2. Pagina «Dati per il RUNTS» con lavoratori manuali e promemoria.
3. Ruolo Dipendente con permessi toglibili.
Nessun deploy senza il via del titolare.
