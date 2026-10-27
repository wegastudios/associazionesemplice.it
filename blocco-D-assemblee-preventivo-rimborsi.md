# Blocco D — testo per le guide di supporto

Tre funzioni nuove, tutte del plugin **Pro** (Associazione Semplice Pro): **Assemblee e deleghe** e **Rimborsi ai volontari** in tutti i piani Pro, **Bilancio preventivo** in Pro Contabile e Pro Fiscale. Senza Pro o con la licenza non in regola le tre funzioni spariscono; i dati restano.

## Assemblee e deleghe (Pro)

**A cosa serve.** Convocare l'assemblea dei soci, registrare chi c'è, le deleghe tra soci, controllare il numero legale e avere il verbale già pronto.

**Come si usa** (Registri → Assemblee; la usano segreteria, presidente, vicepresidente e amministratori).
1. «Nuova assemblea»: tipo (ordinaria o straordinaria), titolo, data e ora della prima convocazione, seconda convocazione (facoltativa), luogo, ordine del giorno, numero legale.
2. «Convoca e avvisa i soci»: ogni socio con diritto di intervenire riceve un'email con data, luogo e ordine del giorno. L'avviso parte una sola volta per persona; «Rimanda l'avviso a chi non l'ha ricevuto» manda agli altri. Se la riunione è fra meno di otto giorni compare un avviso: controlla che il preavviso previsto dallo statuto sia rispettato. Senza ordine del giorno non si convoca.
3. Deleghe: il socio sceglie un altro socio dalla sua area riservata (sezione «Assemblee»), oppure la segreteria registra una delega scritta ricevuta. Ogni socio può dare una sola delega per assemblea e riceverne al massimo 3 (5 se i soci sono almeno 500). Chi riceve una delega non può delegare, e chi ha delegato non può riceverne. Non si delega a chi non è socio in regola; tutori e ospiti non sono soci.
4. Presenze: la segreteria spunta i soci presenti. Il numero legale conta i presenti e le deleghe di chi è presente; chi ha delegato ma viene di persona conta una volta sola.
5. «Chiudi: svolta in prima (o seconda) convocazione»: serve almeno una presenza e il numero legale raggiunto. Dopo la chiusura l'assemblea non si modifica più.
6. «Crea il verbale»: nasce il verbale (Registri → Verbali) già compilato con presenti, rappresentati per delega, numero legale e ordine del giorno; resta da scrivere lo svolgimento. Un verbale per assemblea.
7. «Foglio presenze (CSV)»: elenco di tutti i soci con presente, delegato o assente.

**Numero legale.** Si scrive assemblea per assemblea, in percentuale degli aventi diritto. Si parte da 50% in prima e 0% in seconda per l'ordinaria, 75% e 50% per la straordinaria. Il 50% significa «metà più uno». Lo statuto dell'ente può prevedere altro: controlla e correggi.

**Chi ha diritto di intervenire.** I soci con la tessera in regola, non sospesi e non usciti.

**Privacy.** L'esportazione dei dati di una persona comprende presenze e deleghe.

## Bilancio preventivo (Pro)

**A cosa serve.** Scrivere quanto si prevede di incassare e spendere nell'anno e vedere, voce per voce, come va rispetto ai movimenti reali.

**Come si usa** (Contabilità → Preventivo).
1. «Nuovo preventivo»: anno (uno per anno) e titolo.
2. Nella tabella delle voci scrivi l'importo previsto per ogni categoria di entrata e di uscita; le righe vuote non si salvano.
3. «Parti dall'anno prima»: copia le voci dal preventivo o dal consuntivo dell'anno precedente, con una variazione in percentuale. Sostituisce le voci scritte.
4. Il confronto mostra per ogni voce preventivo, consuntivo (dalla prima nota; giroconti e movimenti annullati non contano), differenza a favore, percentuale e stato: «nei limiti», «quasi esaurita» (dal 90%), «oltre il preventivo», «non prevista». In fondo i totali e il risultato.
5. «Segna come approvato»: data e assemblea (facoltativa, scelta tra quelle registrate). Approvato non si modifica; «Riapri» lo riporta in preparazione.
6. «Esporta il confronto (CSV)».

**Bacheca.** Un riquadro avvisa quante voci di spesa hanno superato il preventivo approvato dell'anno in corso.

## Rimborsi ai volontari (Pro)

**A cosa serve.** Gestire le richieste di rimborso spese dei volontari, dall'approvazione al pagamento, con la registrazione in prima nota.

**Come si usa.**
- Il volontario (o socio) chiede il rimborso dall'area riservata, sezione «Rimborsi spese». La segreteria può registrare una richiesta per lui da Cassa → Rimborsi.
- Tre tipi: **spesa documentata** (serve lo scontrino o la fattura, in PDF o foto), **autocertificazione** (fino a 10 € al giorno e 150 € al mese per volontario; vale solo se l'assemblea ha deliberato i tipi di spesa) e **chilometrico** (compare solo se in Cassa → Rimborsi → Impostazioni c'è la tariffa al chilometro; l'importo si calcola da solo).
- Cassa → Rimborsi: elenco con filtro per stato (da approvare, approvato da pagare, rifiutato, pagato, annullato). Nella scheda: «Approva», «Rifiuta» (con motivo) o «Annulla la richiesta».
- «Segna come pagato e registra in prima nota»: si sceglie il conto e la data; il sito registra un'uscita nella categoria «Rimborso spese socio/volontario» e la collega alla richiesta (riferimento RIMB-n). Un rimborso già pagato non si paga due volte.
- Il volontario riceve un'email quando il rimborso è approvato, rifiutato o pagato. Può ritirare la richiesta finché non è decisa.
- Chi approva o paga non può farlo per un rimborso a proprio nome. Nelle impostazioni c'è l'opzione «Chi ha chiesto il rimborso può anche approvarlo e pagarlo» per gli enti in cui una persona sola gestisce la cassa.
- «Esporta (CSV)» scarica l'elenco. Tutori e ospiti non sono soci e non chiedono rimborsi.

**Bacheca.** Un riquadro ricorda le richieste da approvare e i rimborsi da pagare.

**Privacy.** L'esportazione dei dati di una persona comprende i suoi rimborsi. Se la persona viene anonimizzata, giustificativi e descrizioni si cancellano; importi e collegamento alla prima nota restano per la contabilità.

## Percorsi dei menu

- Registri → Assemblee (`?page=asem-assemblies`)
- Contabilità → Preventivo (`?page=asem-budget`)
- Cassa → Rimborsi (`?page=asem-refunds`)
- Area riservata dei soci: sezioni «Assemblee» e «Rimborsi spese»

## Shortcode

| Shortcode | Cosa mostra |
|---|---|
| `[associazionesemplice_assemblee]` | Le assemblee convocate, con il modulo di delega (soci) |
| `[associazionesemplice_rimborsi]` | I rimborsi spese del volontario e la richiesta di un nuovo rimborso (Pro) |

Le due sezioni compaiono anche in `[associazionesemplice_area_soci]`.

## Impostazioni

| Impostazione | Dove | Predefinito |
|---|---|---|
| Numero legale di ogni assemblea (prima e seconda convocazione) | Modulo dell'assemblea | ordinaria 50% e 0%; straordinaria 75% e 50% |
| Tariffa al chilometro (`refund_km_rate_cents`, in centesimi) | Cassa → Rimborsi → Impostazioni | vuota: niente rimborso chilometrico |
| Chi ha chiesto il rimborso può approvarlo e pagarlo (`refund_self_approve`) | idem | spento |

Le impostazioni dei rimborsi le cambia solo l'amministratore. Nessuna impostazione per le assemblee oltre ai campi del modulo.
