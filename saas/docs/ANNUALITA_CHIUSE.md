# Annualità chiuse: disegno e stima

Decisioni della titolare, 13/11/2026. Stato: **disegno, niente costruito**; si parte con il suo via.

## 1. Le regole

1. Anno sociale e anno fiscale (esercizio) restano distinti; ognuno inizia nel mese scelto dall'associazione, anche diversi.
2. Il libro soci segue l'**anno fiscale** (è un adempimento) e contiene solo i soci con tessera dell'anno: chi non ha rinnovato non c'è. Per l'anno in corso è dal vivo; alla chiusura dell'esercizio si **fotografa**.
3. Alla chiusura si fotografano: libro soci (righe e numero progressivo), cifre del rendiconto con la voce di ogni categoria, PDF emesso con data.
4. Nessuna cancellazione da un dato fiscale: chi è in una fotografia chiusa ci resta anche se la persona viene poi cancellata o anonimizzata. La cancellazione dell'intera associazione (7 e 180 giorni) non cambia.
5. Su un esercizio chiuso non si modifica niente, categorie e conti compresi: o si riapre o resta così.
6. Riapertura solo dell'esercizio chiuso più recente, con conferma esplicita e motivo obbligatorio; la fotografia precedente si conserva come versione; la traccia resta per sempre. Si chiude un esercizio solo se i precedenti sono chiusi (deduzione dalla regola: da far vedere alla titolare).
7. Le fotografie entrano nell'esportazione completa.

**Da decidere (non deciso da noi):** con anno sociale e fiscale sfalsati, quale tessera fa entrare nel libro. Proposta: una tessera valida almeno un giorno dell'esercizio (`valid_from <= fine esercizio` e `valid_to >= inizio`). I fondatori (tessera sempre rinnovata) ci sono sempre.

## 2. Com'è oggi (verificato nel codice)

- Libro soci: `MemberBookService::rows()` rigenera dai dati attuali, senza anno; una persona cancellata sparisce da tutti gli anni.
- Prima nota: già immodificabile in un esercizio chiuso (trigger `transaction_guard`).
- Rendiconto: `StatementService` legge categorie e conti dal vivo; `categories` e `accounts` si possono modificare nel database.
- Riapertura: esiste, con motivo e registro, ma per qualunque esercizio; la chiusura non controlla l'ordine.
- L'esercizio parte dall'anno solare di default; si può creare con un'altra data di inizio (`FiscalYearService::create`). L'anno sociale ha il suo mese (`soci.social_year_start_month`).

## 3. Disegno

**Tabelle (schema `app`, append-only come `transactions`):**
- `app.fiscal_year_snapshots (org_id, id, fiscal_year, version, kind 'member_book'|'statement', payload jsonb, pdf_path, created_at, created_by, superseded_at)`. Una riga per tipo e per versione; la versione nuova nasce solo alla richiusura dopo una riapertura, la precedente resta (`superseded_at`).
- Righe del libro nel `payload` con numero progressivo congelato, dati anagrafici *come erano* (nome, codice fiscale cifrato con la chiave dell'associazione, tessera, date). Il PDF con data emissione sul disco `org_files`, hash nel payload.
- Rendiconto nel `payload`: voci per categoria con nome e tipo *a quella data*, totali, saldi dei conti.

**Chiusura (`FiscalYearService::close`):** controlla che i precedenti siano chiusi; in un'unica transazione calcola e scrive le due fotografie, poi marca chiuso; registro `fiscal_year.closed` con l'hash delle fotografie.

**Riapertura:** solo l'esercizio chiuso più recente; conferma esplicita nell'interfaccia (digitare l'anno) + motivo; la fotografia vigente si marca superata ma resta; `fiscal_year.reopened` nel registro (già append-only).

**Lettura:** libro e rendiconto di un esercizio chiuso si leggono **solo dalla fotografia** (mai dai dati vivi); l'anno in corso dal vivo con la regola «tessera dell'anno».

**Immutabilità in database:** trigger che rifiuta UPDATE di `categories` e `accounts` (nome, tipo, genitore) usati da movimenti di un esercizio chiuso, e di `memberships`/`people` che cambierebbero un libro chiuso solo per i campi del libro? No: i dati vivi possono cambiare (privacy), la fotografia no. Vale quindi: nessun trigger su `people`; la fotografia è la prova. Niente DELETE/UPDATE su `fiscal_year_snapshots` tranne `superseded_at`.

**Anonimizzazione:** oggi rende `(dati anonimizzati)` il libro vivo; con le fotografie il libro chiuso conserva i dati fiscali (decisione della titolare: nessuna cancellazione da un dato fiscale). Va detto nell'informativa privacy; l'anonimizzazione su richiesta agisce sull'anagrafica viva, non sulla fotografia.

**Esportazione completa (`FullExport`):** aggiunge le fotografie (JSON) e i PDF di ogni esercizio chiuso, tutte le versioni.

**Purge dell'associazione:** nessuna eccezione; il test di copertura delle tabelle con `org_id` (Portale SaaS) include `fiscal_year_snapshots`.

**Mesi diversi:** oggi `fiscal_years` ha già `starts_on/ends_on`; manca solo la scelta guidata del mese di inizio dell'esercizio (configurazione) e il test con anno sociale sfalsato.

## 4. Stima (giorni di lavoro, con test e CI)

| Pezzo | Giorni |
|---|---|
| Tabella snapshot, trigger, test RLS/append-only | 1 |
| Libro soci per anno fiscale (regola tessera) e lettura dalla fotografia, PDF con data | 2 |
| Fotografia del rendiconto e lettura da essa | 1,5 |
| Chiusura con controllo d'ordine, riapertura limitata con conferma e versioni | 1 |
| Blocco modifiche a categorie/conti di esercizi chiusi | 0,5 |
| Scelta del mese dell'esercizio nella configurazione guidata | 0,5 |
| Esportazione completa e informativa privacy | 0,5 |
| Interfaccia (elenco esercizi con versioni, conferme) | 1 |
| **Totale** | **8** |

Si può consegnare in tre PR: (1) tabella + chiusura/riapertura + ordine; (2) libro soci; (3) rendiconto, blocchi, esportazione.

## 5. Rischi e punti aperti

- Regola della tessera con anni sfalsati (sopra).
- Esercizi già chiusi oggi (anche della demo) non hanno fotografia: alla prima apertura si genera una fotografia «ricostruita» dai dati attuali, segnata come tale, oppure si riapre e si richiude. Da decidere.
- Il codice fiscale nella fotografia è cifrato con la chiave dell'associazione: dopo la distruzione della chiave (cancellazione) non si apre, come tutto il resto.
