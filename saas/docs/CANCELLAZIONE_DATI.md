# Conservazione e cancellazione dei dati di un'associazione

Progetto del 13/11/2026, da approvare prima di scrivere codice. Parte dalla regola della titolare: i dati di
un'associazione **sospesa o che ha disdetto** si conservano **180 giorni**, poi si cancellano. Oggi il valore c'è
(`saas.portal.retention_days` = 180) ma nessuna cancellazione parte.

## 1. Il problema da risolvere prima

La cancellazione è **definitiva** e riguarda dati che l'associazione deve per legge conservare: la prima nota e il
rendiconto vanno tenuti 10 anni (art. 2220 c.c., già richiamato in [DATABASE.md](DATABASE.md) §7). Il titolare del
trattamento è l'associazione; noi siamo il suo fornitore. Quindi cancellare a 180 giorni è possibile solo se
l'associazione ha avuto **tutto il tempo e il modo di portarsi via i dati**. Il progetto serve a questo: avvisi, accesso
solo per scaricare, cancellazione solo dopo una conferma di una persona dello staff.

## 2. Le date

| Quando | Cosa succede |
|---|---|
| Giorno 0 | Sospensione (il giorno dopo la scadenza) o disdetta. Si segna la data (`suspended_at` / `closed_at`) e quando si cancella (`purge_after` = giorno 0 + 180). |
| Giorno 0 | Primo avviso a titolare e amministratori: cosa è successo, che i dati restano 180 giorni, come riattivare, come scaricarli. |
| Giorni 1–180 | L'associazione **non lavora** più (nessuna scrittura), ma chi è titolare o amministratore può entrare in una pagina «Scarica i tuoi dati» (esportazione completa, `FullExport`) e vedere il pagamento da fare per riattivare. |
| Giorni 30, 90, 150, 170 | Avvisi di promemoria, con i giorni che mancano. |
| Giorno 179 | Ultimo avviso: «domani si cancella». |
| Giorno 180 | L'associazione entra nell'elenco «Da cancellare» di `/saas/admin`. **Non si cancella da sola:** lo fa una persona dello staff con conferma (§4). |

Riattivazione in qualsiasi giorno fino alla cancellazione: lo stato torna `active`, le date si azzerano, nulla va
perso. Se l'associazione riattiva e disdice di nuovo, i 180 giorni ricominciano.

## 3. Cosa si cancella e cosa resta

**Si cancella (tutto, insieme):**
1. La chiave dati dell'associazione (`saas:keys:destroy`): da quel momento i campi cifrati e i file non si aprono più,
   **anche nei backup**. È la parte che rende la cancellazione vera anche dove non arriviamo.
2. Le righe dell'associazione nelle tabelle dell'applicazione (soci, prima nota, attività, risorse, messaggi, ecc.).
3. I file allegati sul disco `org_files`.
4. Il database dedicato, se ce l'ha (`DROP DATABASE`, dopo aver tolto la voce dal registro).
5. Il dominio personalizzato (voce in `core.org_domains` e sito su cPanel).
6. Gli utenti che appartengono **solo** a quella associazione; chi ha anche altre associazioni resta e perde solo
   l'appartenenza.

**Resta (senza dati personali):**
- Una riga «lapide» in `core.orgs`: identificatore, nome, data di creazione, sospensione e cancellazione. Serve a
  non riusare lo stesso indirizzo e a dimostrare quando e da chi è stata fatta.
- Il registro delle azioni (`audit.log`): contiene solo chi, cosa e quando, mai valori personali. Si conserva con la
  catena sigillata intatta; da decidere per quanto tempo (§6).
- Le nostre fatture e i pagamenti dell'abbonamento: stanno nel portale, con la nostra contabilità (10 anni).

## 4. Come si cancella (una persona, con conferma)

Una cancellazione di dati veri non si fa in automatico, almeno all'inizio.

1. Comando notturno `saas:orgs:lifecycle` (solo avvisi e stato): manda gli avvisi della tabella §2 e porta le
   associazioni a 180 giorni nell'elenco. Non cancella niente.
2. Pagina «Da cancellare» in `/saas/admin` (scheda Clienti): per ognuna mostra nome, quando è stata sospesa, se ha
   scaricato l'esportazione, quanti dati ha, avvisi inviati. Il pulsante «Cancella i dati» chiede di **scrivere
   l'indirizzo dell'associazione** e la password; lo vede e lo preme solo chi ha il ruolo di amministratore.
3. La cancellazione è un lavoro in coda, in due tempi: prima si **blocca** (nessun accesso, nessuna scrittura),
   poi dopo 24 ore, annullabili, si eseguono i passi §3 nell'ordine: file, righe, database dedicato, per ultima la
   chiave. Così un errore a metà si può ancora rimediare.
4. Ogni passo scrive nel registro dello staff (`core.platform_audit`): chi, quale associazione, quale passo. Solo
   l'identificatore, mai nomi o dati dei soci.
5. Alla fine si manda un'email di conferma a titolare e amministratori.

## 5. Cosa serve costruire

| Pezzo | Dove |
|---|---|
| Colonne `suspended_at`, `closed_at`, `purge_after`, `purge_stage` e avvisi inviati su `core.orgs` | migrazione centrale |
| Quando l'associazione è sospesa si registra la data (oggi si cambia solo lo `status`) | `PortalSync` e `SuspendExpiredCommand` |
| Pagina «Scarica i tuoi dati» per un'associazione sospesa, senza altre scritture | applicazione (sessione «Sviluppo SaaS in locale») |
| `saas:orgs:lifecycle` e le sei email | Piattaforma |
| Elenco «Da cancellare», conferma e lavoro in coda | `/saas/admin` e Core |
| Servizio di cancellazione che chiama i passi §3 (righe, file, database, chiave) | Core e Cifratura (uno per modulo, come l'esportazione) |
| Test: riattivazione che azzera le date, nessuna cancellazione senza conferma, cancellazione di un'associazione non tocca le altre (RLS), backup non apribili dopo la distruzione della chiave | tutti |

Ogni modulo ha già la sua esportazione (`*DataExport`): la cancellazione segue lo stesso schema, un servizio per
modulo registrato nel contratto del Core, così un modulo nuovo non resta fuori.

## 6. Da decidere con la titolare

1. **I 180 giorni partono dalla sospensione o dalla scadenza?** Qui: dal giorno della sospensione (il giorno dopo
   la scadenza), uguale per chi disdice. Per chi disdice con il periodo ancora pagato: dalla fine del periodo.
2. **Cosa vede un'associazione sospesa:** qui, solo l'esportazione e il pagamento. Se vuoi che veda anche i dati in
   sola lettura per 30 giorni, si può.
3. **Avvisi:** calendario del §2 e destinatari (titolare e amministratori). Testi: li scrive l'audit.
4. **Cancellazione a mano con conferma** (consigliata) o automatica. Si può passare all'automatico dopo i primi mesi.
5. **Per quanto si tiene il registro delle azioni** dopo la cancellazione (qui: 10 anni, senza dati personali).
6. **Parere del commercialista o del legale** su due punti: l'associazione non perde l'obbligo di tenere la contabilità
   10 anni (noi garantiamo l'esportazione e lo scriviamo nei termini), e la cancellazione dei dati dei soci a 180 giorni
   come tempo di conservazione da indicare nell'informativa.
7. **Termini e informativa** (portale): la regola dei 180 giorni, l'esportazione e che dopo non si recupera nulla.
8. **Backup:** le copie esistenti si cancellano da sole alla loro scadenza; la distruzione della chiave le rende
   illeggibili subito. Verificare con `saas:backup:restore-test` su un'associazione di prova.
