# Dati per il RUNTS, socio ente e utente «Dipendente»

Decisioni del titolare, 13/11/2026. Stato: **realizzato e unito in main** (PR 648 e PR 650); nessun deploy. Il testo del decreto RUNTS non è verificato: i criteri vengono da fonti professionali.

## 1. Cosa chiede il RUNTS

Entro il 30 giugno i dati al 31 dicembre dell'anno prima, solo se cambiati: soci con diritto di voto persone fisiche (solo il numero); soci enti (denominazione, CF, se iscritti nella stessa sezione); volontari iscritti nel registro; lavoratori dipendenti e parasubordinati. ODV/APS sotto il minimo (7 persone fisiche o 3 enti della stessa sezione): comunicazione entro 30 giorni.

## 2. Decisioni (risposte del titolare)

1. «Dati per il RUNTS» sta in tutti i piani.
2. **Diritto di voto** (aggiornato 14/11/2026, allineato al plugin): ospiti e genitori mai; i soci votano salvo che il loro **livello** abbia «Vota» spento (`member_levels.votes`, un livello nuovo vota); i minorenni alla data mai.
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

## 7. Matricola (allineamento al plugin, 14/11/2026)

`app.people.matricola`: numero fisso per socio, dato una volta sola a chi entra nel libro soci in ordine di ingresso (data, poi id) e senza buchi; non cambia e non si riassegna (contatore per associazione in `app.matricola_counters`, indice unico). Il numero di tessera (`card_number`) è un'altra cosa. Il libro soci mostra matricola e numero di riga dell'esercizio; la fotografia li conserva entrambi. Assegnazione pigra (`MatricolaService::assignMissing()`, alla prima lettura del libro). Import CSV con enti: da fare dopo.

## 8. Avviso «sotto il minimo» (14/11/2026, come nel plugin)

Decisione del titolare: i numeri da copiare nel portale del RUNTS restano quelli **al 31 dicembre**; l'avviso 7 persone / 3 enti guarda invece i soci con **tessera valida oggi** (chi non ha rinnovato è cessato di fatto fino al rinnovo; cessati veri solo espulsi e dimissionari). `RuntsCounts::today()` riusa lo stesso conteggio (diritto di voto, minorenni esclusi) alla data odierna; l'avviso elenca i soci con tessera scaduta da meno di 90 giorni (`MemberBookService::recentlyExpired()`), che tornano soci se rinnovano. La pagina dice i due conti con parole diverse: «Oggi i soci con diritto di voto sono…» e «Dati al 31 dicembre…». Resta solo per ODV e APS (`ente.kind`).

## 9. Import CSV con enti (14/11/2026)

Colonne nuove nell'import (e nell'export, che si può correggere e ricaricare): «Tipo soggetto» (persona o ente), «Denominazione» (al posto di nome e cognome; va in `last_name`) e «Stessa sezione RUNTS» (sì o no). Un file con «Denominazione» ma senza «Nome» e «Cognome» è accettato. Controlli già in anteprima: denominazione obbligatoria; codice fiscale dell'ente di 11 cifre con la cifra di controllo giusta oppure di 16 caratteri; sezione sì o no; un ospite non può essere un ente; un ente non ha il tutore né la data di nascita; una scheda già presente si riconosce per codice fiscale o denominazione e, se è una persona, non si scambia con un ente (e viceversa). Non portato dal plugin: l'annullamento dell'import (il SaaS non lo ha) e l'errore per ingresso in un esercizio chiuso (il SaaS non blocca le schede in un anno chiuso: il libro chiuso è una fotografia).
