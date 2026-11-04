# Blocco C — testo per le guide di supporto

Due funzioni nuove del plugin **Pro** (Associazione Semplice Pro). Si trovano in **Registri**, accanto a «Libro soci» e «Verbali»: schede **Certificati medici** e **Affiliazioni**. Le usano la segreteria, il presidente, il vicepresidente e gli amministratori. Sono incluse in Pro Contabile e Pro Fiscale (e nel Pro Lite); senza Pro o con la licenza non in regola spariscono.

## Certificato medico sportivo

**A cosa serve.** Sapere chi ha il certificato in regola, chi è in scadenza, chi è scaduto e chi non lo ha mai portato, tra gli iscritti alle attività che lo richiedono.

**Come si usa.**
1. Nel modulo di ogni attività che lo richiede, spunta «richiede il certificato medico sportivo».
2. Dalla scheda del socio (sezione «Certificato medico») o da Registri → Certificati medici registra il certificato: tipo (non agonistico, agonistico, altro), data di rilascio, scadenza (proposta: un anno dopo, da correggere con quella scritta sul certificato), medico o struttura, file PDF o foto.
3. Registri → Certificati medici mostra gli iscritti con lo stato (valido, in scadenza, scaduto, mancante). Filtri: stato, attività, «tutti i soci attivi». Il pulsante «Esporta (CSV)» scarica l'elenco.

**Dato sanitario.** Lo vede solo la segreteria (e chi ha gli stessi permessi). Il file non ha un indirizzo pubblico: si scarica solo dal sito, da chi è autorizzato. Nel registro delle azioni resta scritto che un certificato è stato aggiunto, aperto o eliminato, mai cosa conteneva. Genitori e tutori non sono soci: non hanno certificati.

**Promemoria (spenti di default).** In Registri → Certificati medici → Impostazioni: «Promemoria automatici». Una volta al giorno il socio (o il suo tutore, se minorenne) riceve un'email quando il certificato sta per scadere e, una volta, quando è scaduto da poco; la segreteria riceve un'unica email con l'elenco. Ogni avviso parte una sola volta per certificato. «Preavviso»: giorni prima della scadenza in cui il certificato risulta «In scadenza» (predefinito 30).

**Bacheca.** Un riquadro avvisa quanti certificati sono scaduti, mancanti o in scadenza.

**Area soci (facoltativa).** «Il socio vede nella sua area la scadenza del proprio certificato»: mostra stato e scadenza, non il file né il medico.

**Privacy.** I certificati compaiono nell'esportazione dei dati della persona; se la persona viene anonimizzata, certificati e file vengono cancellati.

## Affiliazioni agli enti

**A cosa serve.** Tenere i codici di affiliazione dell'associazione agli enti di promozione sportiva e le tessere dei soci, e preparare i file per i portali degli enti.

**Enti.** CONI/RAS, UISP, CSEN, ACSI, AICS, oppure «Altro ente» con il nome scritto a mano.

**Affiliazione dell'associazione.** Registri → Affiliazioni → «Registra un'affiliazione»: ente, codice, stagione (2026 oppure 2025/2026), scadenza, costo. Una sola per ente e stagione.

**Tessere dei soci.**
- Una per volta: dalla scheda del socio («Tessere degli enti») o da Registri → Affiliazioni → «Nuova tessera». Si indicano ente, stagione, numero (facoltativo), tipo tessera, data, scadenza, costo.
- In blocco: «Tesseramento in blocco» crea la tessera per **tutti i soci attivi** o per **gli iscritti a un'attività**. Salta chi ha già la tessera dello stesso ente e stagione. Il numero si aggiunge dopo, dall'elenco, quando l'ente lo assegna.
- Il tutore e l'ospite non sono soci: non si tesserano.

**Elenco e file.** Filtri per ente, stagione, stato e attività. «Esporta l'elenco (CSV)» scarica l'elenco con numeri, costi e stato. «File per il portale dell'ente (CSV)» scarica le colonne: nome, cognome, codice fiscale, data di nascita, luogo di nascita, sesso, indirizzo, email, telefono, tipo tessera. Attenzione: il sito non conserva luogo di nascita e sesso; li ricava dal codice fiscale (il luogo è il codice catastale, ad esempio H501, non il nome del comune). Controllare il file prima del caricamento.

**Promemoria (spenti di default).** Registri → Affiliazioni → Impostazioni. Il socio (o il tutore) riceve un'email per la scadenza della sua tessera; la segreteria riceve un'unica email con affiliazioni e tessere in scadenza o scadute da poco. Una sola volta per scadenza. «Preavviso» predefinito 30 giorni. Un riquadro in Bacheca ricorda le scadenze.

## Percorsi dei menu

- Registri → Certificati medici (`?page=asem-medical`)
- Registri → Affiliazioni (`?page=asem-affiliations`)
- Scheda del socio: sezioni «Certificato medico» e «Tessere degli enti»
- Modulo dell'attività: riga «Certificato medico»

## Shortcode

Nessuno nuovo. La scadenza del certificato compare nell'area soci (sezione «Certificato medico») se attivata nelle impostazioni dei certificati.

## Impostazioni

| Impostazione | Dove | Predefinito |
|---|---|---|
| Preavviso certificati (`cert_warn_days`) | Certificati medici → Impostazioni | 30 giorni |
| Promemoria certificati (`cert_reminders`) | idem | spento |
| Il socio vede la scadenza (`cert_show_member`) | idem | spento |
| Preavviso affiliazioni e tessere (`aff_warn_days`) | Affiliazioni → Impostazioni | 30 giorni |
| Promemoria affiliazioni e tessere (`aff_reminders`) | idem | spento |

Le impostazioni le cambia solo l'amministratore.
