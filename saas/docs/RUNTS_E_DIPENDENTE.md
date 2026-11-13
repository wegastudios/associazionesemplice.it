# Dati per il RUNTS, socio ente e utente «Dipendente»

Decisioni del titolare, 13/11/2026. Stato: **realizzato e unito in main** (PR 648 e PR 650); nessun deploy. Il testo del decreto RUNTS non è verificato: i criteri vengono da fonti professionali.

## 1. Cosa chiede il RUNTS

Entro il 30 giugno i dati al 31 dicembre dell'anno prima, solo se cambiati: soci con diritto di voto persone fisiche (solo il numero); soci enti (denominazione, CF, se iscritti nella stessa sezione); volontari iscritti nel registro; lavoratori dipendenti e parasubordinati. ODV/APS sotto il minimo (7 persone fisiche o 3 enti della stessa sezione): comunicazione entro 30 giorni.

## 2. Decisioni (risposte del titolare)

1. «Dati per il RUNTS» sta in tutti i piani.
2. **Diritto di voto sul tipo di socio** (non una casella per scheda): `MemberType::hasVotingRights()`. I minorenni alla data si escludono dal conteggio.
3. **Lavoratori = numero dei Dipendenti** (utenti con ruolo Dipendente), non un numero inserito a mano.
4. **Dipendente conta tra gli operatori del piano** (`OrgMembers::assertOperatorAvailable` conta ogni ruolo tranne Commercialista), perché accede come la segreteria.
5. **Elenco dei permessi rimovibili chiuso** (`Ability::removable()`); mai un permesso in più del ruolo.
6. **Chi li toglie: titolare e amministratori** (chi può assegnare il ruolo Dipendente); ogni modifica è nel registro delle azioni. Presidente e vicepresidente restano Owner e Admin.
7. **Socio ente**: persona fisica o ente, con denominazione e codice fiscale (anche di 11 cifre).
8. Il Dipendente non è socio in partenza (niente scheda, tessera, libro soci) ma si può collegare a una scheda.

## 3. Anagrafica (PR 648)

Colonne su `app.people`: `is_entity`, `entity_same_section` (migrazione org `2026_11_24_000100_add_entity_to_people`). Per l'ente la denominazione sta in `last_name`, `first_name` è vuoto, niente data di nascita. `is_entity` non indicato in un aggiornamento mantiene il valore attuale. Scheda socio con casella «Socio ente» e «Iscritto nella stessa sezione».

## 4. Pagina «Dati per il RUNTS» (PR 650)

`/gestionale/soci/runts` (permesso `people.view`), data di riferimento 31 dicembre dell'anno precedente. Il calcolo (`RuntsCounts`) riusa il criterio del libro soci, `MemberBookService::entersPeriod()` / `peopleEntering()`: fondatori per date, gli altri solo con tessera valida alla data. Mostra:
- soci con diritto di voto, persone fisiche maggiorenni;
- soci enti (elenco con denominazione e «stessa sezione»);
- volontari (tipo `volunteer` alla data);
- lavoratori = Dipendenti (`StaffCount`, implementato in Core da `EmployeeCounter`);
- avviso sotto il minimo, solo se `ente.kind` è `odv` o `aps` (7 persone oppure 3 enti della stessa sezione).

Il promemoria del 30 giugno è solo un avviso nella pagina, nessuna notifica programmata. Nessun invio al RUNTS: i numeri si copiano nel portale.

## 5. Utente «Dipendente» (PR 650)

- `OrgRole::Employee` (`'employee'`), etichetta «Dipendente», permessi di partenza della segreteria.
- Colonna `core.org_users.denied_abilities jsonb` con le abilità tolte; `Operator::can()` = permessi del ruolo meno quelle tolte. Per i ruoli personalizzati i permessi fini derivano da quelli generali (`parent()`).
- Pagina Operatori: per ogni Dipendente un interruttore per permesso, visibile a titolare e amministratori.
- Permessi applicati alle rotte: entrate, uscite e fondi in contabilità (`accounting.income|expense|funds`), corsi (`courses.manage`).

## 6. Ancora aperto

- I permessi «eventi» e «rimborsi» sono definiti ma non applicati a nessuna rotta, perché le pagine non esistono ancora: vanno agganciati quando arrivano.
- I parasubordinati non hanno un conteggio proprio: oggi «lavoratori» è solo il numero dei Dipendenti.
