# Conservazione e cancellazione dei dati di un'associazione

Decisioni della titolare del 13/11/2026. Oggi il valore c'è (`saas.portal.retention_days` = 180) ma nessuna
cancellazione parte: questo documento dice cosa costruire.

## 1. Le regole decise

1. **I 180 giorni partono dalla scadenza**: l'ultimo giorno pagato (`until`) è la data certa di ultimo accesso. Vale per
   chi non rinnova (sospesa il giorno dopo) e per chi dà disdetta (la disdetta ferma il rinnovo, il servizio dura fino a
   `until`).
2. **Un'associazione sospesa non esporta nulla**: vede solo la pagina del pagamento. Chi vuole i suoi dati li esporta
   **finché è attiva**, anche dopo aver dato disdetta e fino alla scadenza. Durante la prova non si esporta
   (`terms.trial.exports`).
3. **Dopo 180 giorni la cancellazione è automatica**, senza conferma di una persona dello staff.
4. **Registro delle azioni**: si tengono l'anno solare corrente e quello precedente (taglio al 1° gennaio, in UTC, non all'anno fiscale: il registro resta da uno a due anni). Il comando gira il 1° di ogni mese; la prima cancellazione reale in produzione è il 1° gennaio 2028, per il 2026 e prima. Dopo 180 giorni dalla scadenza si cancella
   anche quello dell'associazione.

## 2. Le date

| Quando | Cosa succede |
|---|---|
| Fino a `until` | L'associazione è attiva e può esportare, anche se ha disdetto. |
| 30 e 7 giorni prima di `until`, se ha disdetto o non ha un pagamento in corso | Avviso del portale (riguarda il pagamento): «dopo la scadenza non potrai più esportare i tuoi dati». Il gestionale non lo manda. |
| `until` + 1 | Sospensione. Si segna `suspended_at` e `purge_after` = `until` + 180. Accesso solo alla pagina del pagamento. Avviso a titolare e amministratori: cosa è successo, che i dati restano fino al `purge_after`, come riattivare. |
| Giorni 30, 90, 150, 170 dalla scadenza | Promemoria con i giorni che mancano. |
| Giorno 179 | Ultimo avviso: «domani si cancella». |
| Giorno 180 | Cancellazione automatica (§4). |

Si riattiva in qualsiasi giorno fino alla cancellazione pagando: lo stato torna `active`, le date si azzerano e nulla è
perso (le esportazioni tornano disponibili).

## 3. Cosa si cancella e cosa resta

**Si cancella (tutto, insieme):**
1. La chiave dati dell'associazione (`saas:keys:destroy`): i campi cifrati e i file non si aprono più, **anche nei
   backup**.
2. Le righe dell'associazione nelle tabelle dell'applicazione (soci, prima nota, attività, risorse, messaggi, ecc.).
3. I file allegati sul disco `org_files`.
4. Il database dedicato, se c'è (`DROP DATABASE`, dopo aver tolto la voce dal registro).
5. Il dominio personalizzato (voce in `core.org_domains` e sito su cPanel).
6. Il registro delle azioni dell'associazione.
7. Gli utenti che appartengono **solo** a quella associazione; chi ne ha altre resta e perde l'appartenenza.

**Resta (senza dati personali):**
- Una riga «lapide» in `core.orgs`: identificatore, nome, data di creazione, scadenza e cancellazione. Serve a non
  riusare lo stesso indirizzo e a dimostrare quando è stata fatta.
- Il registro dello staff (`core.platform_audit`): chi ha fatto cosa su quale identificatore, mai dati dei soci.
- Le nostre fatture e i pagamenti dell'abbonamento: stanno nel portale, con la nostra contabilità.

## 4. Come si cancella

1. Ogni notte `saas:orgs:purge` prende le associazioni con `purge_after` raggiunto e non riattivate.
2. Prima di cancellare **ricontrolla** che lo stato sia ancora sospeso o chiuso e che non sia arrivato un pagamento.
3. Esegue i passi §3 in quest'ordine: blocco (nessun accesso), file, righe, database dedicato, registro, per ultima la
   chiave. Se un passo fallisce si ferma e lo segnala allo staff; la notte dopo riprende dal passo fermo.
4. Ogni passo scrive nel registro dello staff solo l'identificatore e il passo.
5. A fine lavoro email di conferma a titolare e amministratori.

Una guardia semplice contro gli incidenti: se in una notte i candidati sono più di un numero impostato (per esempio 5),
la cancellazione **non parte** e avvisa lo staff. Un errore di data non cancella tutto in una volta.

## 5. Il registro delle azioni: anno corrente e precedente

Il registro è «solo in aggiunta» con catena sigillata: ogni riga contiene il sigillo della precedente
([DATABASE.md](DATABASE.md) §7). Per tenere solo due anni si cancellano le **partizioni mensili** più vecchie
(`audit.log_AAAA_MM`), ogni gennaio quelle dell'anno prima del precedente. Cancellare l'inizio spezza il punto di
partenza della catena, quindi prima di eliminare una partizione si salva il suo ultimo sigillo come **ancora** (numero
progressivo e sigillo, senza dati personali) e `saas:audit:verify` parte da quell'ancora. Il trigger che oggi vieta ogni
cancellazione deve ammettere questa sola operazione, solo al ruolo proprietario e solo sulle partizioni.

## 6. Cosa serve costruire

| Pezzo | Dove |
|---|---|
| Colonne `suspended_at`, `purge_after`, avvisi inviati su `core.orgs`; si registra la data di sospensione | migrazione centrale, `PortalSync` |
| Accesso limitato a un'associazione sospesa: solo pagamento, nessuna esportazione né lettura | applicazione («Sviluppo SaaS in locale») |
| `saas:orgs:lifecycle` (avvisi) e le email | Piattaforma |
| `saas:orgs:purge` con guardia e ripresa dei passi | Core |
| Un servizio di cancellazione per modulo, registrato nel contratto del Core (come le esportazioni `*DataExport`) | tutti i moduli |
| Scadenza del registro per anno con ancora della catena | Audit |
| Test: la riattivazione azzera le date; una cancellazione non tocca altre associazioni (RLS); il pagamento arrivato all'ultimo momento ferma la cancellazione; i backup non si aprono dopo la distruzione della chiave | tutti |

## 7. Ancora aperto

1. **Conservazione per legge.** Il progetto dice che la contabilità l'associazione la deve conservare per i tempi di
   legge e che, se la perde dopo 180 giorni dalla scadenza, la responsabilità è sua. Quali tempi e quali articoli valgono
   per un'associazione (e quali per una iscritta al RUNTS) li deve dire il commercialista: va scritto nei termini e
   nell'informativa, con l'esportazione prima della scadenza come strada indicata.
2. **Avvisi sull'esportazione prima della scadenza:** la regola 2 funziona solo se chi sta per scadere lo sa. I testi dei
   due avvisi (30 e 7 giorni prima) li scrive l'audit.
3. **Backup:** le copie esistenti scadono da sole; la chiave distrutta le rende illeggibili subito. Da provare con
   `saas:backup:restore-test` su un'associazione di prova.
4. **Termini e informativa** (portale): regola dei 180 giorni dalla scadenza, nessuna esportazione dopo la scadenza,
   cancellazione automatica e non recuperabile.

## 8. Fine della prova senza un piano (decisione della titolare, 13/11/2026)

Chi non sceglie un piano alla fine dei 30 giorni di prova non ha avuto un abbonamento: i dati della prova si
cancellano **7 giorni dopo la fine della prova** (`terms.trial.purge_days`), non dopo 180. Prima partono email di avviso,
con una proposta scontata che dà il portale (`terms.trial.end_discount_percent`, partenza 0), perché solo il portale conosce il codice sconto: gli avvisi del gestionale non nominano lo sconto. Non si
esporta (la prova non esporta). La cancellazione usa gli stessi passi di §3–4 con la scadenza della prova al posto di
`until`; chi sceglie un piano prima, la ferma. Sequenza degli avvisi da definire con l'audit (per esempio al giorno
−7, −2 della fine prova, alla fine, +3 e +6).

## 9. Decisioni del 13/11/2026 (titolare, via audit)

- Lo sconto di fine prova si imposta in Condizioni (`terms.trial.end_discount_percent`, partenza 0). Con 0 le email non hanno il paragrafo dell'offerta. Vale sul primo pagamento, dalla fine della prova fino alla cancellazione dei dati.
- Nei 7 giorni dopo la prova il gestionale è in **sola lettura** e si può acquistare un piano (realizzazione nell'app: «Sviluppo SaaS in locale»).
- Commercialista: i termini di legge di conservazione non sono un problema nostro. Backup legati al servizio: distrutta la chiave, i dati non si aprono più.
- Pacchetti di soci (decisione definitiva 13/11): «500 soci in più» 17 €/mese (170 €/anno) e «1.000 soci in più» 30 €/mese (300 €/anno), IVA esclusa, solo sopra il piano Grande, nascosti finché la titolare non li mette in vendita. Piccola e Media non li hanno. Da chiederle: si sommano e si comprano più volte? Cosa succede se si supera il limite senza cambiare piano?

## 10. Le condizioni della cancellazione automatica (16/11/2026)

La cancellazione notturna parte solo se, insieme: la scadenza più i giorni di conservazione è passata; la sospensione (`core.orgs.suspended_at`) più i giorni di conservazione è passata; l'ultimo avviso risulta inviato da almeno un giorno. Una scadenza che arretra di oltre 30 giorni resta segnata nel registro (`until_back_days`) ma da sola non basta mai a far cancellare. Con `ops.sh spegni-cancellazione` si spegne tutto per la notte.

**Chi manda gli avvisi (decisione del 19/11/2026, titolare via Audit; sostituisce quella del 13/11).** Ogni avviso ha un solo mittente. Il gestionale manda quelli su dati e accesso, perché è lui a eseguire sospensione e cancellazione: fine della prova, sospensione, promemoria prima dell'eliminazione, ultimo avviso (`SendLifecycleNoticesCommand::KINDS`, registrati in `core.org_notices`). Il portale manda quelli sui soldi: benvenuto, rinnovo, pagamento non riuscito, scadenza vicina del piano a pagamento, fattura, disdetta registrata. La cancellazione automatica guarda gli avvisi registrati nel gestionale: finché `SAAS_NOTICES_ENABLED` è spento non ce ne sono e la cancellazione non cancella mai (voluto). Ordine di lancio: prima il portale smette di mandare i suoi avvisi su dati e accesso, poi si accende `SAAS_NOTICES_ENABLED`, così nessun avviso parte due volte.

**I numeri hanno una sola fonte**: `saas.portal.retention_days` (180, dalla scadenza) e `saas.portal.trial_purge_days` (7, dalla fine della prova). Le email dicono la data esatta: l'ultimo giorno in cui i dati ci sono è la scadenza più i giorni di conservazione; l'eliminazione avviene dopo.
